export const MUSIC_STAGES = Object.freeze({
  MENU: "menu",
  GRID_2: "grid-2",
  GRID_4: "grid-4",
  CHALLENGE: "challenge"
});

const MUSIC_FILE = "./assets/audio/neon-circuit-v1.m4a";
const MUSIC_GAIN = 0.22;
const CROSSFADE_SECONDS = 0.12;
const RELEASE_FADE_SECONDS = 0.06;
const RELEASE_DELAY_MS = 75;
const SEGMENTS = Object.freeze({
  [MUSIC_STAGES.MENU]: Object.freeze({ offset: 0, duration: 640_000 / 48_000 }),
  [MUSIC_STAGES.GRID_2]: Object.freeze({ offset: 640_000 / 48_000, duration: 523_636 / 48_000 }),
  [MUSIC_STAGES.GRID_4]: Object.freeze({ offset: 1_163_636 / 48_000, duration: 480_000 / 48_000 }),
  [MUSIC_STAGES.CHALLENGE]: Object.freeze({ offset: 1_643_636 / 48_000, duration: 274_286 / 48_000 })
});

function ignoreFailure(promise) {
  Promise.resolve(promise).catch(() => {});
}

export function createMusicController({
  AudioContextClass = globalThis.AudioContext,
  fetchImpl = globalThis.fetch?.bind(globalThis),
  setTimeoutImpl = globalThis.setTimeout?.bind(globalThis),
  clearTimeoutImpl = globalThis.clearTimeout?.bind(globalThis)
} = {}) {
  let enabled = false;
  let desiredRunning = false;
  let desiredStage = MUSIC_STAGES.MENU;
  let generation = 0;
  let context = null;
  let masterGain = null;
  let buffer = null;
  let preparation = null;
  let loadController = null;
  let currentVoice = null;
  let suspendTimer = null;
  let suspendSequence = 0;
  const voices = new Set();

  function cleanupVoice(voice) {
    if (!voice || !voices.delete(voice)) return;
    if (currentVoice === voice) currentVoice = null;
    try {
      voice.source.disconnect();
    } catch {
      // The source may already be detached.
    }
    try {
      voice.gain.disconnect();
    } catch {
      // The gain may already be detached.
    }
  }

  function stopVoicesImmediately(targetVoices = [...voices]) {
    for (const voice of targetVoices) {
      voice.source.onended = null;
      try {
        voice.source.stop();
      } catch {
        // The source may already be stopped.
      }
      cleanupVoice(voice);
    }
    if (targetVoices.includes(currentVoice)) currentVoice = null;
  }

  function fadeAndStopVoices(activeContext = context, targetVoices = [...voices]) {
    if (!activeContext || activeContext.state !== "running") {
      stopVoicesImmediately(targetVoices);
      return;
    }

    const time = activeContext.currentTime;
    for (const voice of targetVoices) {
      const parameter = voice.gain.gain;
      parameter.cancelScheduledValues(time);
      parameter.setValueAtTime(Math.max(0, parameter.value), time);
      parameter.linearRampToValueAtTime(0, time + RELEASE_FADE_SECONDS);
      try {
        voice.source.stop(time + RELEASE_FADE_SECONDS + 0.01);
      } catch {
        cleanupVoice(voice);
      }
    }
    if (targetVoices.includes(currentVoice)) currentVoice = null;
  }

  function cancelPendingSuspend() {
    if (suspendTimer === null) return;
    clearTimeoutImpl?.(suspendTimer);
    suspendTimer = null;
  }

  function canPlay(activeGeneration = generation, activeContext = context) {
    return (
      enabled &&
      desiredRunning &&
      activeGeneration === generation &&
      activeContext === context &&
      activeContext?.state === "running" &&
      buffer &&
      masterGain
    );
  }

  function startDesiredStage() {
    if (!canPlay() || currentVoice?.stage === desiredStage) return;
    const segment = SEGMENTS[desiredStage];
    if (!segment) return;

    const time = context.currentTime;
    const source = context.createBufferSource();
    const gain = context.createGain();
    source.buffer = buffer;
    source.loop = true;
    source.loopStart = segment.offset;
    source.loopEnd = segment.offset + segment.duration;
    gain.gain.setValueAtTime(0, time);
    gain.gain.linearRampToValueAtTime(1, time + CROSSFADE_SECONDS);
    source.connect(gain);
    gain.connect(masterGain);

    const voice = { gain, source, stage: desiredStage };
    voices.add(voice);
    source.onended = () => cleanupVoice(voice);
    source.start(time, segment.offset);

    const previousVoice = currentVoice;
    currentVoice = voice;
    if (!previousVoice) return;
    previousVoice.gain.gain.cancelScheduledValues(time);
    previousVoice.gain.gain.setValueAtTime(previousVoice.gain.gain.value, time);
    previousVoice.gain.gain.linearRampToValueAtTime(0, time + CROSSFADE_SECONDS);
    try {
      previousVoice.source.stop(time + CROSSFADE_SECONDS + 0.01);
    } catch {
      cleanupVoice(previousVoice);
    }
  }

  function prepare(activeGeneration, activeContext) {
    if (
      preparation ||
      buffer ||
      !enabled ||
      activeGeneration !== generation ||
      activeContext !== context ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }

    loadController = new AbortController();
    const activeController = loadController;
    const work = fetchImpl(MUSIC_FILE, { cache: "no-store", signal: activeController.signal })
      .then((response) => {
        if (!response?.ok) throw new Error("Unable to load adaptive music.");
        return response.arrayBuffer();
      })
      .then((encodedAudio) => activeContext.decodeAudioData(encodedAudio))
      .then((decodedAudio) => {
        if (
          !enabled ||
          activeGeneration !== generation ||
          activeContext !== context ||
          activeContext.state === "closed"
        ) {
          return;
        }
        buffer = decodedAudio;
        startDesiredStage();
      })
      .catch(() => {})
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
        context = new AudioContextClass({ latencyHint: "playback" });
        masterGain = context.createGain();
        masterGain.gain.value = MUSIC_GAIN;
        masterGain.connect(context.destination);
      } catch {
        context = null;
        masterGain = null;
        return null;
      }
    }
    prepare(generation, context);
    return context;
  }

  function release() {
    cancelPendingSuspend();
    loadController?.abort();
    loadController = null;
    preparation = null;
    buffer = null;
    const closingVoices = [...voices];
    const closingMaster = masterGain;
    const closingContext = context;
    masterGain = null;
    context = null;
    currentVoice = null;

    const closeResources = () => {
      stopVoicesImmediately(closingVoices);
      try {
        closingMaster?.disconnect();
      } catch {
        // The master gain may already be detached.
      }
      if (closingContext && closingContext.state !== "closed") {
        try {
          ignoreFailure(closingContext.close());
        } catch {
          // Music remains optional when audio output is unavailable.
        }
      }
    };

    if (closingContext?.state === "running" && closingVoices.length > 0) {
      fadeAndStopVoices(closingContext, closingVoices);
      if (typeof setTimeoutImpl === "function") {
        setTimeoutImpl(closeResources, RELEASE_DELAY_MS);
      } else {
        closeResources();
      }
      return;
    }
    closeResources();
  }

  return {
    setEnabled(value) {
      const nextEnabled = Boolean(value);
      if (enabled === nextEnabled) return;
      enabled = nextEnabled;
      desiredRunning = false;
      generation += 1;
      suspendSequence += 1;
      if (!enabled) {
        release();
        return;
      }
      ensureContext();
    },

    setStage(stage) {
      if (!SEGMENTS[stage] || desiredStage === stage) return;
      desiredStage = stage;
      startDesiredStage();
    },

    unlock() {
      if (!enabled) return Promise.resolve(false);
      cancelPendingSuspend();
      suspendSequence += 1;
      const activeContext = ensureContext();
      if (!activeContext) return Promise.resolve(false);
      const activeGeneration = generation;
      desiredRunning = true;
      if (activeContext.state === "running") {
        startDesiredStage();
        return Promise.resolve(true);
      }
      try {
        return Promise.resolve(activeContext.resume())
          .then(() => {
            if (
              !enabled ||
              !desiredRunning ||
              activeGeneration !== generation ||
              activeContext !== context ||
              activeContext.state !== "running"
            ) {
              return false;
            }
            startDesiredStage();
            return true;
          })
          .catch(() => false);
      } catch {
        return Promise.resolve(false);
      }
    },

    suspend() {
      desiredRunning = false;
      suspendSequence += 1;
      cancelPendingSuspend();
      const activeContext = context;
      const activeSequence = suspendSequence;
      fadeAndStopVoices(activeContext);
      if (!activeContext || activeContext.state !== "running") return;

      const suspendContext = () => {
        suspendTimer = null;
        if (
          !enabled ||
          desiredRunning ||
          activeSequence !== suspendSequence ||
          activeContext !== context ||
          activeContext.state !== "running"
        ) {
          return;
        }
        try {
          Promise.resolve(activeContext.suspend())
            .then(() => {
              if (!enabled || !desiredRunning || activeContext !== context) return;
              return Promise.resolve(activeContext.resume()).then(() => startDesiredStage());
            })
            .catch(() => {});
        } catch {
          // A failed suspension does not affect gameplay.
        }
      };
      if (typeof setTimeoutImpl === "function") {
        suspendTimer = setTimeoutImpl(suspendContext, RELEASE_DELAY_MS);
      } else {
        suspendContext();
      }
    }
  };
}
