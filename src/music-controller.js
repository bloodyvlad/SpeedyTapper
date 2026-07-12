export const MUSIC_STAGES = Object.freeze({
  MENU: "menu",
  GRID_2: "grid-2",
  GRID_4: "grid-4",
  CHALLENGE: "challenge"
});

export const MUSIC_TRACKS = Object.freeze([
  Object.freeze({ id: "neon-circuit-refined", file: "./assets/audio/neon-circuit-refined.m4a" }),
  Object.freeze({ id: "deep-current", file: "./assets/audio/deep-current.m4a" }),
  Object.freeze({ id: "power-grid", file: "./assets/audio/power-grid.m4a" })
]);

const MUSIC_GAIN = 0.22;
const CROSSFADE_SECONDS = 0.12;
const RELEASE_FADE_SECONDS = 0.06;
const RELEASE_DELAY_MS = 75;
const SEGMENTS = Object.freeze({
  [MUSIC_STAGES.MENU]: Object.freeze({ offset: 0, duration: 460_800 / 48_000 }),
  [MUSIC_STAGES.GRID_2]: Object.freeze({ offset: 460_800 / 48_000, duration: 384_000 / 48_000 }),
  [MUSIC_STAGES.GRID_4]: Object.freeze({ offset: 844_800 / 48_000, duration: 329_143 / 48_000 }),
  [MUSIC_STAGES.CHALLENGE]: Object.freeze({ offset: 1_173_943 / 48_000, duration: 274_286 / 48_000 })
});

export function resolveMusicStage(snapshot, stageStartsAtMs) {
  if (snapshot?.difficulty?.gridDimension === 1) return MUSIC_STAGES.MENU;
  const elapsedMs = Number.isFinite(snapshot?.elapsedMs) ? snapshot.elapsedMs : 0;
  const pressureStartsAtMs = stageStartsAtMs?.fourByFourPressure ?? Number.POSITIVE_INFINITY;
  const enduranceStartsAtMs = stageStartsAtMs?.endurance ?? Number.POSITIVE_INFINITY;
  if (
    snapshot?.difficulty?.gridDimension >= 4 &&
    elapsedMs >= enduranceStartsAtMs
  ) {
    return MUSIC_STAGES.CHALLENGE;
  }
  if (
    snapshot?.difficulty?.gridDimension >= 4 &&
    elapsedMs >= pressureStartsAtMs
  ) {
    return MUSIC_STAGES.GRID_4;
  }
  return MUSIC_STAGES.GRID_2;
}

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
  let desiredTrackIndex = 0;
  let generation = 0;
  let context = null;
  let masterGain = null;
  let currentVoice = null;
  let suspendTimer = null;
  let suspendSequence = 0;
  const buffers = new Map();
  const preparations = new Map();
  const loadControllers = new Map();
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
      buffers.has(desiredTrackIndex) &&
      masterGain
    );
  }

  function startDesiredStage() {
    if (
      !canPlay() ||
      (currentVoice?.stage === desiredStage && currentVoice?.trackIndex === desiredTrackIndex)
    ) {
      return;
    }
    const segment = SEGMENTS[desiredStage];
    if (!segment) return;

    const time = context.currentTime;
    const source = context.createBufferSource();
    const gain = context.createGain();
    source.buffer = buffers.get(desiredTrackIndex);
    source.loop = true;
    source.loopStart = segment.offset;
    source.loopEnd = segment.offset + segment.duration;
    gain.gain.setValueAtTime(0, time);
    gain.gain.linearRampToValueAtTime(1, time + CROSSFADE_SECONDS);
    source.connect(gain);
    gain.connect(masterGain);

    const voice = { gain, source, stage: desiredStage, trackIndex: desiredTrackIndex };
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

  function prepareTrack(trackIndex, activeGeneration, activeContext) {
    const track = MUSIC_TRACKS[trackIndex];
    if (
      !track ||
      preparations.has(trackIndex) ||
      buffers.has(trackIndex) ||
      !enabled ||
      activeGeneration !== generation ||
      activeContext !== context ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }

    const activeController = new AbortController();
    loadControllers.set(trackIndex, activeController);
    const work = fetchImpl(track.file, { cache: "no-store", signal: activeController.signal })
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
        buffers.set(trackIndex, decodedAudio);
        if (trackIndex === desiredTrackIndex) startDesiredStage();
      })
      .catch(() => {})
      .finally(() => {
        if (preparations.get(trackIndex) === work) preparations.delete(trackIndex);
        if (loadControllers.get(trackIndex) === activeController) {
          loadControllers.delete(trackIndex);
        }
      });
    preparations.set(trackIndex, work);
  }

  function prepareAll(activeGeneration, activeContext) {
    for (const trackIndex of MUSIC_TRACKS.keys()) {
      prepareTrack(trackIndex, activeGeneration, activeContext);
    }
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
    prepareAll(generation, context);
    return context;
  }

  function release() {
    cancelPendingSuspend();
    for (const controller of loadControllers.values()) controller.abort();
    loadControllers.clear();
    preparations.clear();
    buffers.clear();
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

    advanceTrack(stage = MUSIC_STAGES.MENU) {
      if (SEGMENTS[stage]) desiredStage = stage;
      desiredTrackIndex = (desiredTrackIndex + 1) % MUSIC_TRACKS.length;
      if (!buffers.has(desiredTrackIndex) && currentVoice) {
        fadeAndStopVoices(context, [currentVoice]);
      }
      startDesiredStage();
      return MUSIC_TRACKS[desiredTrackIndex].id;
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
