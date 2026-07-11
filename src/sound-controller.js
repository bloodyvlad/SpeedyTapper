const SOUND_FILES = Object.freeze({
  hum: "./assets/audio/fluorescent-hum.wav",
  oops: "./assets/audio/oops.mp3"
});

const HUM_GAIN = 0.3;
const OOPS_GAIN = 0.68;
const GAIN_RAMP_SECONDS = 0.01;
const ONE_SHOT_ATTACK_SECONDS = 0.008;
const ONE_SHOT_RELEASE_SECONDS = 0.012;

function ignoreFailure(promise) {
  Promise.resolve(promise).catch(() => {});
}

export function createSoundController({
  AudioContextClass = globalThis.AudioContext,
  fetchImpl = globalThis.fetch?.bind(globalThis)
} = {}) {
  let enabled = false;
  let generation = 0;
  let context = null;
  let masterGain = null;
  let humGain = null;
  let humSource = null;
  let buffers = null;
  let preparation = null;
  let loadController = null;
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
        masterGain.gain.value = 1;
        masterGain.connect(context.destination);
      } catch {
        context = null;
        masterGain = null;
        return null;
      }
    }

    prepareAudio(generation, context);
    return context;
  }

  function rampHum(targetGain) {
    if (!enabled || !context || context.state !== "running" || !humSource || !humGain) return;

    const time = context.currentTime;
    const gain = humGain.gain;
    if (typeof gain.cancelAndHoldAtTime === "function") {
      gain.cancelAndHoldAtTime(time);
    } else {
      const currentGain = gain.value;
      gain.cancelScheduledValues(time);
      gain.setValueAtTime(currentGain, time);
    }
    gain.linearRampToValueAtTime(targetGain, time + GAIN_RAMP_SECONDS);
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

    for (const record of oneShots) {
      stopSource(record.source);
      disconnectNode(record.gain);
    }
    oneShots.clear();

    disconnectNode(masterGain);
    masterGain = null;

    const closingContext = context;
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
      if (!enabled) {
        releaseAudio();
        return;
      }
      ensureContext();
    },

    unlock() {
      if (!enabled) return;
      const activeContext = ensureContext();
      if (!activeContext || activeContext.state === "running") return;
      try {
        ignoreFailure(activeContext.resume());
      } catch {
        // Browsers can reject audio output while still allowing gameplay.
      }
    },

    suspend() {
      if (!enabled || !context) return;
      silenceHumImmediately();
      if (context.state !== "running") return;
      try {
        ignoreFailure(context.suspend());
      } catch {
        // A failed suspension is harmless because the hum has already been gated off.
      }
    },

    tileOn() {
      rampHum(HUM_GAIN);
    },

    tileOff() {
      rampHum(0);
    },

    lifeLost() {
      if (!enabled || !context || context.state !== "running" || !buffers?.oops || !masterGain) {
        return;
      }

      const source = context.createBufferSource();
      const gain = context.createGain();
      const time = context.currentTime;
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

      const record = { gain, source };
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
