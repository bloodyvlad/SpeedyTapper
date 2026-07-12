const SOUND_FILES = Object.freeze({
  hum: "./assets/audio/fluorescent-hum.wav",
  oops: "./assets/audio/oops.mp3"
});

const HUM_GAIN = 0.3;
const OOPS_GAIN = 0.68;
const HUM_ATTACK_TIME_CONSTANT = 0.008;
const HUM_RELEASE_TIME_CONSTANT = 0.012;
const MASTER_ATTACK_TIME_CONSTANT = 0.012;
const ONE_SHOT_ATTACK_SECONDS = 0.008;
const ONE_SHOT_RELEASE_SECONDS = 0.012;
const ONE_SHOT_RESTART_FADE_SECONDS = 0.012;
const RESTART_FADE_TIME_CONSTANT_DIVISOR = 8;
const RENDER_QUANTUM_FRAMES = 128;
const DEFAULT_SAMPLE_RATE = 48000;

function ignoreFailure(promise) {
  Promise.resolve(promise).catch(() => {});
}

export function createSoundController({
  AudioContextClass = globalThis.AudioContext,
  fetchImpl = globalThis.fetch?.bind(globalThis)
} = {}) {
  let enabled = false;
  let generation = 0;
  let desiredRunning = false;
  let resumeAttemptId = 0;
  let context = null;
  let contextStateHandler = null;
  let pendingSuspendContext = null;
  let pendingSuspendWork = null;
  let masterGain = null;
  let humGain = null;
  let humSource = null;
  let buffers = null;
  let preparation = null;
  let loadController = null;
  let masterGateOpen = false;
  const oneShots = new Set();

  function isCurrent(preparationGeneration, preparationContext) {
    return (
      enabled &&
      preparationGeneration === generation &&
      preparationContext === context &&
      preparationContext.state !== "closed"
    );
  }

  function stopSource(source) {
    if (!source) return;
    try {
      source.stop();
    } catch {
      // A source may already have ended or may not have started yet.
    }
    try {
      source.disconnect();
    } catch {
      // Already-disconnected nodes need no further cleanup.
    }
  }

  function disconnectNode(node) {
    if (!node) return;
    try {
      node.disconnect();
    } catch {
      // Already-disconnected nodes need no further cleanup.
    }
  }

  function cleanupOneShot(record) {
    if (!record || !oneShots.delete(record)) return;
    disconnectNode(record.source);
    disconnectNode(record.gain);
  }

  function stopOneShots(fadeSeconds = 0) {
    for (const record of [...oneShots]) {
      if (fadeSeconds > 0 && context?.state === "running") {
        const time = context.currentTime;
        const fadeEndTime = time + fadeSeconds;
        const timeConstant = fadeSeconds / RESTART_FADE_TIME_CONSTANT_DIVISOR;
        const renderQuantumSeconds =
          RENDER_QUANTUM_FRAMES / (context.sampleRate || DEFAULT_SAMPLE_RATE);
        const heldGain = getOneShotGainAtTime(record, time);
        record.gain.gain.cancelScheduledValues(time);
        record.gain.gain.setValueAtTime(heldGain, time);
        record.gain.gain.setTargetAtTime(0, time, timeConstant);
        record.gain.gain.setValueAtTime(0, fadeEndTime);
        try {
          record.source.stop(fadeEndTime + renderQuantumSeconds);
          continue;
        } catch {
          // Fall through to immediate cleanup if this source already ended.
        }
      }

      oneShots.delete(record);
      record.source.onended = null;
      stopSource(record.source);
      disconnectNode(record.gain);
    }
  }

  function getOneShotGainAtTime(record, time) {
    const elapsed = Math.max(0, time - record.startedAt);
    if (record.duration <= ONE_SHOT_ATTACK_SECONDS + ONE_SHOT_RELEASE_SECONDS) {
      return elapsed < record.duration ? OOPS_GAIN : 0;
    }
    if (elapsed < ONE_SHOT_ATTACK_SECONDS) {
      return OOPS_GAIN * (elapsed / ONE_SHOT_ATTACK_SECONDS);
    }
    if (elapsed < record.duration - ONE_SHOT_RELEASE_SECONDS) return OOPS_GAIN;
    if (elapsed < record.duration) {
      return OOPS_GAIN * ((record.duration - elapsed) / ONE_SHOT_RELEASE_SECONDS);
    }
    return 0;
  }

  function createPersistentHum(preparationGeneration, preparationContext) {
    if (!isCurrent(preparationGeneration, preparationContext) || !buffers?.hum) return;

    const source = preparationContext.createBufferSource();
    const gain = preparationContext.createGain();
    source.buffer = buffers.hum;
    source.loop = true;
    gain.gain.value = 0;
    source.connect(gain);
    gain.connect(masterGain);
    source.start();
    humSource = source;
    humGain = gain;
  }

  async function fetchAndDecode(url, signal, preparationGeneration, preparationContext) {
    const response = await fetchImpl(url, { cache: "no-store", signal });
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    if (!response?.ok) throw new Error(`Unable to load ${url}`);

    const encodedAudio = await response.arrayBuffer();
    if (!isCurrent(preparationGeneration, preparationContext)) return null;

    const decodedAudio = await preparationContext.decodeAudioData(encodedAudio);
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    return decodedAudio;
  }

  function prepareAudio(preparationGeneration, preparationContext) {
    if (
      preparation ||
      buffers ||
      !isCurrent(preparationGeneration, preparationContext) ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }

    loadController = new AbortController();
    const activeController = loadController;
    const requests = [SOUND_FILES.hum, SOUND_FILES.oops].map((url) =>
      fetchAndDecode(
        url,
        activeController.signal,
        preparationGeneration,
        preparationContext
      ).catch((error) => {
        activeController.abort();
        throw error;
      })
    );
    const work = Promise.allSettled(requests)
      .then(([humResult, oopsResult]) => {
        if (humResult.status !== "fulfilled" || oopsResult.status !== "fulfilled") return;
        const hum = humResult.value;
        const oops = oopsResult.value;
        if (!hum || !oops || !isCurrent(preparationGeneration, preparationContext)) return;
        buffers = Object.freeze({ hum, oops });
        createPersistentHum(preparationGeneration, preparationContext);
      })
      .finally(() => {
        if (preparation === work) preparation = null;
        if (loadController === activeController) loadController = null;
      });
    preparation = work;
  }

  function ensureContext() {
    if (!enabled || typeof AudioContextClass !== "function" || typeof fetchImpl !== "function") {
      return null;
    }

    if (!context || context.state === "closed") {
      try {
        context = new AudioContextClass({ latencyHint: "interactive" });
        masterGain = context.createGain();
        masterGain.gain.value = 0;
        masterGateOpen = false;
        masterGain.connect(context.destination);
        const observedContext = context;
        contextStateHandler = () => {
          if (context !== observedContext || observedContext.state === "running") return;
          desiredRunning = false;
          resumeAttemptId += 1;
          silenceHumImmediately();
          silenceMasterImmediately();
          stopOneShots();
        };
        observedContext.addEventListener?.("statechange", contextStateHandler);
      } catch {
        context = null;
        masterGain = null;
        return null;
      }
    }

    prepareAudio(generation, context);
    return context;
  }

  function smoothHumTo(targetGain) {
    if (!enabled || !context || context.state !== "running" || !humSource || !humGain) return;

    const time = context.currentTime;
    const gain = humGain.gain;
    gain.cancelScheduledValues(time);
    gain.setTargetAtTime(
      targetGain,
      time,
      targetGain > 0 ? HUM_ATTACK_TIME_CONSTANT : HUM_RELEASE_TIME_CONSTANT
    );
  }

  function openMasterGate(activeContext) {
    if (
      masterGateOpen ||
      !isCurrent(generation, activeContext) ||
      activeContext.state !== "running" ||
      !masterGain
    ) {
      return;
    }

    const time = activeContext.currentTime;
    const gain = masterGain.gain;
    gain.cancelScheduledValues(time);
    gain.setValueAtTime(0, time);
    gain.setTargetAtTime(1, time, MASTER_ATTACK_TIME_CONSTANT);
    masterGateOpen = true;
  }

  function silenceMasterImmediately() {
    masterGateOpen = false;
    if (!context || !masterGain) return;
    const time = context.currentTime;
    const gain = masterGain.gain;
    gain.cancelScheduledValues(time);
    gain.setValueAtTime(0, time);
    gain.value = 0;
  }

  function resumeFromGesture() {
    if (!enabled) return Promise.resolve(false);
    if (pendingSuspendContext && pendingSuspendContext === context) {
      // suspend() cannot be cancelled. Replace its context inside this newer trusted
      // gesture so the old completion cannot mute the run that now owns audio.
      generation += 1;
      desiredRunning = false;
      resumeAttemptId += 1;
      releaseAudio();
    }
    const activeContext = ensureContext();
    if (!activeContext) return Promise.resolve(false);
    const unlockGeneration = generation;
    desiredRunning = true;
    const unlockAttemptId = resumeAttemptId + 1;
    resumeAttemptId = unlockAttemptId;
    if (activeContext.state === "running") {
      openMasterGate(activeContext);
      return Promise.resolve(true);
    }

    // iOS may interrupt a running context without sending this controller through suspend().
    // Close both gates before resume so a stale target cannot return with the audio route.
    silenceHumImmediately();
    silenceMasterImmediately();
    try {
      return Promise.resolve(activeContext.resume())
        .then(() => {
          if (
            !isCurrent(unlockGeneration, activeContext) ||
            unlockAttemptId !== resumeAttemptId ||
            !desiredRunning ||
            activeContext.state !== "running"
          ) {
            if (
              !desiredRunning &&
              context === activeContext &&
              activeContext.state === "running"
            ) {
              silenceHumImmediately();
              silenceMasterImmediately();
              stopOneShots();
              ignoreFailure(activeContext.suspend());
            }
            return false;
          }
          openMasterGate(activeContext);
          return true;
        })
        .catch(() => false);
    } catch {
      // Browsers can reject audio output while still allowing gameplay.
      return Promise.resolve(false);
    }
  }

  function silenceHumImmediately() {
    if (!context || !humGain) return;
    const time = context.currentTime;
    const gain = humGain.gain;
    gain.cancelScheduledValues(time);
    gain.setValueAtTime(0, time);
    gain.value = 0;
  }

  function releaseAudio() {
    loadController?.abort();
    loadController = null;
    preparation = null;
    buffers = null;

    stopSource(humSource);
    humSource = null;
    disconnectNode(humGain);
    humGain = null;

    stopOneShots();

    disconnectNode(masterGain);
    masterGain = null;
    masterGateOpen = false;

    const closingContext = context;
    if (closingContext && contextStateHandler) {
      closingContext.removeEventListener?.("statechange", contextStateHandler);
    }
    contextStateHandler = null;
    if (pendingSuspendContext === closingContext) {
      pendingSuspendContext = null;
      pendingSuspendWork = null;
    }
    context = null;
    if (closingContext && closingContext.state !== "closed") {
      try {
        ignoreFailure(closingContext.close());
      } catch {
        // A failed close still leaves this controller fully detached.
      }
    }
  }

  return {
    setEnabled(value) {
      const nextEnabled = Boolean(value);
      if (enabled === nextEnabled) return;
      enabled = nextEnabled;
      generation += 1;
      desiredRunning = false;
      resumeAttemptId += 1;
      if (!enabled) {
        releaseAudio();
        return;
      }
      ensureContext();
    },

    unlock() {
      return resumeFromGesture();
    },

    startRun() {
      if (!enabled) return Promise.resolve(false);
      stopOneShots(ONE_SHOT_RESTART_FADE_SECONDS);
      smoothHumTo(0);
      return resumeFromGesture();
    },

    suspend() {
      if (!enabled || !context) return;
      desiredRunning = false;
      resumeAttemptId += 1;
      silenceHumImmediately();
      silenceMasterImmediately();
      stopOneShots();
      if (context.state !== "running") return;
      const suspendingContext = context;
      try {
        const work = Promise.resolve(suspendingContext.suspend())
          .catch(() => {})
          .finally(() => {
            if (pendingSuspendWork !== work) return;
            pendingSuspendContext = null;
            pendingSuspendWork = null;
          });
        pendingSuspendContext = suspendingContext;
        pendingSuspendWork = work;
      } catch {
        // A failed suspension is harmless because the hum has already been gated off.
      }
    },

    tileOn() {
      smoothHumTo(HUM_GAIN);
    },

    tileOff() {
      smoothHumTo(0);
    },

    lifeLost() {
      if (!enabled || !context || context.state !== "running" || !buffers?.oops || !masterGain) {
        return;
      }
      if (oneShots.size > 0) return;

      const source = context.createBufferSource();
      const gain = context.createGain();
      const time = context.currentTime;
      gain.gain.value = 0;
      gain.gain.setValueAtTime(0, time);
      source.buffer = buffers.oops;
      source.connect(gain);
      gain.connect(masterGain);

      const duration = Number.isFinite(buffers.oops.duration) ? buffers.oops.duration : 0;
      if (duration > ONE_SHOT_ATTACK_SECONDS + ONE_SHOT_RELEASE_SECONDS) {
        gain.gain.setValueAtTime(0, time);
        gain.gain.linearRampToValueAtTime(OOPS_GAIN, time + ONE_SHOT_ATTACK_SECONDS);
        gain.gain.setValueAtTime(
          OOPS_GAIN,
          time + duration - ONE_SHOT_RELEASE_SECONDS
        );
        gain.gain.linearRampToValueAtTime(0, time + duration);
      } else {
        gain.gain.value = OOPS_GAIN;
      }

      const record = { duration, gain, source, startedAt: time };
      oneShots.add(record);
      source.onended = () => cleanupOneShot(record);
      try {
        source.start();
      } catch {
        cleanupOneShot(record);
      }
    }
  };
}
