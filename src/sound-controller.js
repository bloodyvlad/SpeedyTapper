const TONE_BANK_URL = "./assets/audio/tap-tones.wav";
const LIFE_LOSS_URL = "./assets/audio/oops.wav";

const TONE_SLOT_COUNT = 16;
const TONE_SLOT_SECONDS = 0.5;
const REQUIRED_BANK_DURATION_SECONDS = TONE_SLOT_COUNT * TONE_SLOT_SECONDS;
const TONE_GAIN = 0.375;
const LIFE_LOSS_GAIN = 0.55;
const MAX_TONE_VOICES = 2;
const VOICE_RELEASE_SECONDS = 0.012;
const VOICE_STOP_PADDING_SECONDS = 0.002;
const MASTER_ATTACK_TIME_CONSTANT = 0.012;
const VOLUME_RAMP_SECONDS = 0.025;

function clampVolume(value) {
  const numericValue = Number(value);
  if (!Number.isFinite(numericValue)) return 1;
  return Math.max(0, Math.min(1, numericValue));
}

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
  let volumeGain = null;
  let volume = 1;
  let masterGateOpen = false;
  let toneBuffer = null;
  let lifeLossBuffer = null;
  let lifeLossVoice = null;
  let preparation = null;
  let loadController = null;
  const voices = new Set();

  function isCurrent(preparationGeneration, preparationContext) {
    return (
      enabled &&
      preparationGeneration === generation &&
      preparationContext === context &&
      preparationContext.state !== "closed"
    );
  }

  function disconnectNode(node) {
    if (!node) return;
    try {
      node.disconnect();
    } catch {
      // Already-disconnected nodes need no further cleanup.
    }
  }

  function cleanupVoice(voice) {
    if (!voice || voice.cleaned) return;
    voice.cleaned = true;
    voices.delete(voice);
    if (lifeLossVoice === voice) lifeLossVoice = null;
    voice.source.onended = null;
    disconnectNode(voice.source);
    disconnectNode(voice.gain);
  }

  function stopVoiceImmediately(voice) {
    if (!voice || voice.cleaned) return;
    try {
      voice.source.stop();
    } catch {
      // The source may already have ended or been scheduled to stop.
    }
    cleanupVoice(voice);
  }

  function fadeAndStopVoice(voice, activeContext = context) {
    if (!voice || voice.cleaned || voice.retiring) return;
    voice.retiring = true;

    if (!activeContext || activeContext.state !== "running") {
      stopVoiceImmediately(voice);
      return;
    }

    const time = activeContext.currentTime;
    const fadeEndTime = time + VOICE_RELEASE_SECONDS;
    voice.gain.gain.cancelScheduledValues(time);
    voice.gain.gain.setValueAtTime(voice.level, time);
    voice.gain.gain.linearRampToValueAtTime(0, fadeEndTime);
    try {
      voice.source.stop(fadeEndTime + VOICE_STOP_PADDING_SECONDS);
    } catch {
      cleanupVoice(voice);
    }
  }

  function stopAllVoices({ fade = false } = {}) {
    for (const voice of [...voices]) {
      if (fade) fadeAndStopVoice(voice);
      else stopVoiceImmediately(voice);
    }
  }

  async function fetchAndDecode(
    url,
    minimumDuration,
    signal,
    preparationGeneration,
    preparationContext
  ) {
    const response = await fetchImpl(url, {
      cache: "no-store",
      signal
    });
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    if (!response?.ok) throw new Error(`Unable to load ${url}`);

    const encodedAudio = await response.arrayBuffer();
    if (!isCurrent(preparationGeneration, preparationContext)) return null;

    const decodedAudio = await preparationContext.decodeAudioData(encodedAudio);
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    if (
      !Number.isFinite(decodedAudio?.duration) ||
      decodedAudio.duration < minimumDuration
    ) {
      throw new Error(`The audio asset at ${url} is malformed or incomplete.`);
    }
    return decodedAudio;
  }

  function prepareAudio(preparationGeneration, preparationContext) {
    if (
      preparation ||
      (toneBuffer && lifeLossBuffer) ||
      !isCurrent(preparationGeneration, preparationContext) ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }

    loadController = new AbortController();
    const activeController = loadController;
    const pendingAssets = [];
    if (!toneBuffer) {
      pendingAssets.push({
        assign(decodedAudio) {
          toneBuffer = decodedAudio;
        },
        minimumDuration: REQUIRED_BANK_DURATION_SECONDS,
        url: TONE_BANK_URL
      });
    }
    if (!lifeLossBuffer) {
      pendingAssets.push({
        assign(decodedAudio) {
          lifeLossBuffer = decodedAudio;
        },
        minimumDuration: Number.EPSILON,
        url: LIFE_LOSS_URL
      });
    }

    const work = Promise.allSettled(
      pendingAssets.map(async ({ assign, minimumDuration, url }) => {
        const decodedAudio = await fetchAndDecode(
          url,
          minimumDuration,
          activeController.signal,
          preparationGeneration,
          preparationContext
        );
        if (decodedAudio && isCurrent(preparationGeneration, preparationContext)) {
          assign(decodedAudio);
        }
      })
    )
      .finally(() => {
        if (preparation === work) preparation = null;
        if (loadController === activeController) loadController = null;
      });
    preparation = work;
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

  function applyVolume({ immediate = false } = {}) {
    if (!context || !volumeGain) return;
    const time = context.currentTime;
    const gain = volumeGain.gain;
    gain.cancelScheduledValues(time);
    if (immediate || context.state !== "running") {
      gain.setValueAtTime(volume, time);
      gain.value = volume;
      return;
    }
    gain.setValueAtTime(gain.value, time);
    gain.linearRampToValueAtTime(volume, time + VOLUME_RAMP_SECONDS);
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
        volumeGain = context.createGain();
        volumeGain.gain.value = volume;
        masterGateOpen = false;
        masterGain.connect(volumeGain);
        volumeGain.connect(context.destination);
        applyVolume({ immediate: true });

        const observedContext = context;
        contextStateHandler = () => {
          if (context !== observedContext || observedContext.state === "running") return;
          desiredRunning = false;
          resumeAttemptId += 1;
          silenceMasterImmediately();
          stopAllVoices();
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

  function releaseAudio() {
    loadController?.abort();
    loadController = null;
    preparation = null;
    toneBuffer = null;
    lifeLossBuffer = null;
    lifeLossVoice = null;
    stopAllVoices();

    disconnectNode(masterGain);
    masterGain = null;
    disconnectNode(volumeGain);
    volumeGain = null;
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
        // Sound FX remain optional when audio output is unavailable.
      }
    }
  }

  function resumeFromGesture() {
    if (!enabled) return Promise.resolve(false);
    if (pendingSuspendContext && pendingSuspendContext === context) {
      // suspend() cannot be cancelled. Replace its context while this newer
      // trusted gesture is active so an old completion cannot mute the run.
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
              silenceMasterImmediately();
              stopAllVoices();
              ignoreFailure(activeContext.suspend());
            }
            return false;
          }
          openMasterGate(activeContext);
          return true;
        })
        .catch(() => false);
    } catch {
      return Promise.resolve(false);
    }
  }

  function playCorrectTap(hitNumber) {
    if (
      !enabled ||
      !desiredRunning ||
      context?.state !== "running" ||
      !masterGateOpen ||
      !masterGain ||
      !toneBuffer
    ) {
      return false;
    }

    const safeHitNumber = Number.isInteger(hitNumber) && hitNumber > 0 ? hitNumber : 1;
    const slotIndex = (safeHitNumber - 1) % TONE_SLOT_COUNT;
    const activeVoices = [...voices].filter(
      (voice) => voice.kind === "tone" && !voice.retiring && !voice.cleaned
    );
    while (activeVoices.length >= MAX_TONE_VOICES) {
      fadeAndStopVoice(activeVoices.shift());
    }

    const source = context.createBufferSource();
    const gain = context.createGain();
    gain.gain.value = TONE_GAIN;
    source.buffer = toneBuffer;
    source.connect(gain);
    gain.connect(masterGain);

    const voice = {
      cleaned: false,
      gain,
      kind: "tone",
      level: TONE_GAIN,
      retiring: false,
      source
    };
    voices.add(voice);
    source.onended = () => cleanupVoice(voice);
    try {
      source.start(
        context.currentTime,
        slotIndex * TONE_SLOT_SECONDS,
        TONE_SLOT_SECONDS
      );
      return true;
    } catch {
      cleanupVoice(voice);
      return false;
    }
  }

  function lifeLost() {
    if (
      !enabled ||
      !desiredRunning ||
      context?.state !== "running" ||
      !masterGateOpen ||
      !masterGain ||
      !lifeLossBuffer
    ) {
      return false;
    }

    if (lifeLossVoice) fadeAndStopVoice(lifeLossVoice);

    const source = context.createBufferSource();
    const gain = context.createGain();
    gain.gain.value = LIFE_LOSS_GAIN;
    source.buffer = lifeLossBuffer;
    source.connect(gain);
    gain.connect(masterGain);

    const voice = {
      cleaned: false,
      gain,
      kind: "life-loss",
      level: LIFE_LOSS_GAIN,
      retiring: false,
      source
    };
    lifeLossVoice = voice;
    voices.add(voice);
    source.onended = () => cleanupVoice(voice);
    try {
      source.start(context.currentTime);
      return true;
    } catch {
      cleanupVoice(voice);
      return false;
    }
  }

  return {
    setVolume(value) {
      volume = clampVolume(value);
      applyVolume();
      return volume;
    },

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
      stopAllVoices({ fade: true });
      return resumeFromGesture();
    },

    suspend() {
      if (!enabled || !context) return;
      desiredRunning = false;
      resumeAttemptId += 1;
      silenceMasterImmediately();
      stopAllVoices();
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
        // The closed master gate keeps a failed background suspension silent.
      }
    },

    lifeLost,
    playCorrectTap
  };
}
