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

export const INTERACTIVE_MUSIC_TRACKS = Object.freeze([
  Object.freeze({
    id: "neon-circuit-refined",
    backingFile: "./assets/audio/interactive-neon-circuit-refined.m4a",
    notesFile: "./assets/audio/interactive-notes-neon-circuit-refined.wav",
    noteScaleDegreeCount: 5,
    motif: Object.freeze([0, 3, 5, 1, 1, 4, 0, 2, 2, 5, 1, 3, 3, 0, 2, 4])
  }),
  Object.freeze({
    id: "deep-current",
    backingFile: "./assets/audio/interactive-deep-current.m4a",
    notesFile: "./assets/audio/interactive-notes-deep-current.wav",
    noteScaleDegreeCount: 5,
    motif: Object.freeze([0, 3, 5, 1, 2, 5, 1, 3, 4, 1, 3, 5, 0, 3, 5, 1])
  }),
  Object.freeze({
    id: "power-grid",
    backingFile: "./assets/audio/interactive-power-grid.m4a",
    notesFile: "./assets/audio/interactive-notes-power-grid.wav",
    noteScaleDegreeCount: 5,
    motif: Object.freeze([0, 3, 5, 1, 3, 0, 2, 4, 0, 3, 5, 1, 3, 0, 2, 4])
  })
]);

const SAMPLE_RATE = 48_000;
const framesToSeconds = (frames) => frames / SAMPLE_RATE;

export const INTERACTIVE_MUSIC_SECTIONS = Object.freeze([
  Object.freeze({ id: "opening", bpm: 100, richness: 0, beatFrames: 28_800, offsetFrames: 4_096, durationFrames: 460_800 }),
  Object.freeze({ id: "grid-2", bpm: 104, richness: 1, beatFrames: 27_692, offsetFrames: 468_992, durationFrames: 443_072 }),
  Object.freeze({ id: "grid-2-ramp", bpm: 108, richness: 2, beatFrames: 26_667, offsetFrames: 916_160, durationFrames: 426_672 }),
  Object.freeze({ id: "grid-2-late", bpm: 112, richness: 2, beatFrames: 25_714, offsetFrames: 1_346_928, durationFrames: 411_424 }),
  Object.freeze({ id: "grid-4", bpm: 112, richness: 3, beatFrames: 25_714, offsetFrames: 1_762_448, durationFrames: 411_424 }),
  Object.freeze({ id: "challenge", bpm: 120, richness: 4, beatFrames: 24_000, offsetFrames: 2_177_968, durationFrames: 384_000 }),
  Object.freeze({ id: "challenge-1", bpm: 124, richness: 4, beatFrames: 23_226, offsetFrames: 2_566_064, durationFrames: 371_616 }),
  Object.freeze({ id: "challenge-2", bpm: 128, richness: 5, beatFrames: 22_500, offsetFrames: 2_941_776, durationFrames: 360_000 }),
  Object.freeze({ id: "challenge-3", bpm: 136, richness: 5, beatFrames: 21_176, offsetFrames: 3_305_872, durationFrames: 338_816 }),
  Object.freeze({ id: "challenge-4", bpm: 144, richness: 6, beatFrames: 20_000, offsetFrames: 3_648_784, durationFrames: 320_000 }),
  Object.freeze({ id: "challenge-5", bpm: 156, richness: 6, beatFrames: 18_462, offsetFrames: 3_972_880, durationFrames: 295_392 }),
  Object.freeze({ id: "endurance", bpm: 168, richness: 7, beatFrames: 17_143, offsetFrames: 4_272_368, durationFrames: 274_288 })
]);

const INTERACTIVE_TRANSITION_DATA = Object.freeze([
  ["opening", "grid-2", 4_550_752, 28_246],
  ["grid-2", "grid-2-ramp", 4_583_094, 27_180],
  ["grid-2-ramp", "grid-2-late", 4_614_370, 26_190],
  ["grid-2-late", "grid-4", 4_644_656, 25_714],
  ["grid-4", "challenge", 4_674_466, 24_857],
  ["challenge", "challenge-1", 4_703_419, 23_613],
  ["challenge-1", "challenge-2", 4_731_128, 22_863],
  ["challenge-2", "challenge-3", 4_758_087, 21_838],
  ["challenge-3", "challenge-4", 4_784_021, 20_588],
  ["challenge-4", "challenge-5", 4_808_705, 19_231],
  ["challenge-5", "endurance", 4_832_032, 17_802],
  ["endurance", "challenge-5", 4_853_930, 17_802],
  ["challenge-5", "challenge-4", 4_875_828, 19_231],
  ["challenge-4", "challenge-3", 4_899_155, 20_588],
  ["challenge-3", "challenge-2", 4_923_839, 21_838],
  ["challenge-2", "challenge-1", 4_949_773, 22_863],
  ["challenge-1", "challenge", 4_976_732, 23_613],
  ["challenge", "grid-4", 5_004_441, 24_857],
  ["grid-4", "grid-2-late", 5_033_394, 25_714],
  ["grid-2-late", "grid-2-ramp", 5_063_204, 26_190],
  ["grid-2-ramp", "grid-2", 5_093_490, 27_180],
  ["grid-2", "opening", 5_124_766, 28_246]
]);

export const INTERACTIVE_MUSIC_TRANSITIONS = Object.freeze(
  INTERACTIVE_TRANSITION_DATA.map(([from, to, offsetFrames, durationFrames]) =>
    Object.freeze({ from, to, offsetFrames, durationFrames })
  )
);

const LEGACY_SEGMENTS = Object.freeze({
  [MUSIC_STAGES.MENU]: Object.freeze({ offset: 0, duration: 460_800 / SAMPLE_RATE }),
  [MUSIC_STAGES.GRID_2]: Object.freeze({ offset: 460_800 / SAMPLE_RATE, duration: 384_000 / SAMPLE_RATE }),
  [MUSIC_STAGES.GRID_4]: Object.freeze({ offset: 844_800 / SAMPLE_RATE, duration: 329_143 / SAMPLE_RATE }),
  [MUSIC_STAGES.CHALLENGE]: Object.freeze({ offset: 1_173_943 / SAMPLE_RATE, duration: 274_265 / SAMPLE_RATE })
});

const TRANSITION_BY_PAIR = new Map(
  INTERACTIVE_MUSIC_TRANSITIONS.map((transition) => [
    `${transition.from}:${transition.to}`,
    transition
  ])
);
const MUSIC_GAIN = 0.45;
const NOTE_GAIN = 0.58;
const NOTE_SLOT_SECONDS = 24_000 / SAMPLE_RATE;
const MAX_NOTE_VOICES = 2;
const CROSSFADE_SECONDS = 0.12;
const TRANSITION_CROSSFADE_SECONDS = 0.024;
const NOTE_RELEASE_SECONDS = 0.012;
const RELEASE_FADE_SECONDS = 0.06;
const RELEASE_DELAY_MS = 75;
const TRANSITION_SETTLE_MS = 20;

export function resolveInteractiveMusicSection(snapshot) {
  const paceLevel = Number.isInteger(snapshot?.difficulty?.paceLevel)
    ? snapshot.difficulty.paceLevel
    : 0;
  return Math.max(0, Math.min(INTERACTIVE_MUSIC_SECTIONS.length - 1, paceLevel));
}

export function resolveMusicStage(snapshot) {
  const paceLevel = resolveInteractiveMusicSection(snapshot);
  if (paceLevel === 0) return MUSIC_STAGES.MENU;
  if (paceLevel >= 10) return MUSIC_STAGES.CHALLENGE;
  if (paceLevel >= 8) return MUSIC_STAGES.GRID_4;
  return MUSIC_STAGES.GRID_2;
}

export function resolveInteractiveNoteCue(track, noteIndex, sectionIndex) {
  const section = INTERACTIVE_MUSIC_SECTIONS[sectionIndex] ?? INTERACTIVE_MUSIC_SECTIONS[0];
  const scaleDegreeCount = Number.isInteger(track?.noteScaleDegreeCount)
    ? Math.max(1, track.noteScaleDegreeCount)
    : 5;
  const safeNoteIndex = Number.isInteger(noteIndex) && noteIndex >= 0 ? noteIndex : 0;
  const liftDegrees = Math.floor(section.richness / 2);
  if (liftDegrees === 0) {
    return Object.freeze({ noteIndex: safeNoteIndex, playbackRate: 1 });
  }
  const absoluteDegree = safeNoteIndex + liftDegrees;
  return Object.freeze({
    noteIndex: absoluteDegree % scaleDegreeCount,
    playbackRate: 2 ** Math.floor(absoluteDegree / scaleDegreeCount)
  });
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
  let interactive = false;
  let desiredRunning = false;
  let desiredStage = MUSIC_STAGES.MENU;
  let desiredInteractiveSection = 0;
  let desiredTrackIndex = 0;
  let generation = 0;
  let assetGeneration = 0;
  let context = null;
  let masterGain = null;
  let currentVoice = null;
  let pendingTransition = null;
  let transitionTimer = null;
  let suspendTimer = null;
  let suspendSequence = 0;
  let pendingSuspendContext = null;
  let pendingSuspendWork = null;
  const legacyBuffers = new Map();
  const interactiveBackingBuffers = new Map();
  const interactiveNoteBuffers = new Map();
  const preparations = new Map();
  const loadControllers = new Map();
  const voices = new Set();
  const noteVoices = new Set();

  function cleanupVoice(voice) {
    if (!voice || !voices.delete(voice)) return;
    noteVoices.delete(voice);
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

  function voiceGainAtTime(voice, time) {
    const envelope = voice?.envelope;
    if (!envelope) return 1;
    if (time <= envelope.startedAt) return envelope.from;
    if (time >= envelope.endsAt) return envelope.to;
    const progress = (time - envelope.startedAt) / (envelope.endsAt - envelope.startedAt);
    return envelope.from + (envelope.to - envelope.from) * progress;
  }

  function fadeVoiceToZero(voice, time, duration) {
    voice.closing = true;
    if (voice.startedAt > time) {
      try {
        voice.source.stop();
      } catch {
        // A future source may already have been cancelled.
      }
      cleanupVoice(voice);
      return;
    }
    const heldGain = voiceGainAtTime(voice, time);
    const parameter = voice.gain.gain;
    parameter.cancelScheduledValues(time);
    parameter.setValueAtTime(heldGain, time);
    parameter.linearRampToValueAtTime(0, time + duration);
    voice.envelope = Object.freeze({
      from: heldGain,
      to: 0,
      startedAt: time,
      endsAt: time + duration
    });
  }

  function fadeAndStopVoices(activeContext = context, targetVoices = null, duration = RELEASE_FADE_SECONDS) {
    const scopedVoices = targetVoices ?? [...voices].filter(
      (voice) => voice.audioContext === activeContext
    );
    if (!activeContext || activeContext.state !== "running") {
      stopVoicesImmediately(scopedVoices);
      return;
    }

    const time = activeContext.currentTime;
    for (const voice of scopedVoices) {
      fadeVoiceToZero(voice, time, duration);
      if (!voices.has(voice)) continue;
      try {
        voice.source.stop(time + duration + 0.01);
      } catch {
        cleanupVoice(voice);
      }
    }
    if (scopedVoices.includes(currentVoice)) currentVoice = null;
  }

  function cancelPendingSuspend() {
    if (suspendTimer === null) return;
    clearTimeoutImpl?.(suspendTimer);
    suspendTimer = null;
  }

  function cancelTransitionTimer() {
    if (transitionTimer !== null) clearTimeoutImpl?.(transitionTimer);
    transitionTimer = null;
    pendingTransition = null;
  }

  function abortPreparations() {
    for (const controller of loadControllers.values()) controller.abort();
    loadControllers.clear();
    preparations.clear();
  }

  function hasDesiredBacking() {
    return interactive
      ? interactiveBackingBuffers.has(desiredTrackIndex)
      : legacyBuffers.has(desiredTrackIndex);
  }

  function canPlay(activeGeneration = generation, activeContext = context) {
    return (
      enabled &&
      desiredRunning &&
      activeGeneration === generation &&
      activeContext === context &&
      activeContext?.state === "running" &&
      hasDesiredBacking() &&
      masterGain
    );
  }

  function createVoice({ buffer, gainValue = 1, kind, startedAt }) {
    const source = context.createBufferSource();
    const gain = context.createGain();
    source.buffer = buffer;
    gain.gain.setValueAtTime(gainValue, startedAt);
    source.connect(gain);
    gain.connect(masterGain);
    const voice = {
      audioContext: context,
      closing: false,
      envelope: null,
      gain,
      kind,
      source,
      startedAt
    };
    voices.add(voice);
    source.onended = () => cleanupVoice(voice);
    return voice;
  }

  function startLegacyStage() {
    if (
      interactive ||
      !canPlay() ||
      (currentVoice?.stage === desiredStage && currentVoice?.trackIndex === desiredTrackIndex)
    ) {
      return;
    }
    const segment = LEGACY_SEGMENTS[desiredStage];
    if (!segment) return;

    const time = context.currentTime;
    const voice = createVoice({
      buffer: legacyBuffers.get(desiredTrackIndex),
      gainValue: 0,
      kind: "legacy-backing",
      startedAt: time
    });
    voice.source.loop = true;
    voice.source.loopStart = segment.offset;
    voice.source.loopEnd = segment.offset + segment.duration;
    voice.gain.gain.linearRampToValueAtTime(1, time + CROSSFADE_SECONDS);
    voice.envelope = Object.freeze({
      from: 0,
      to: 1,
      startedAt: time,
      endsAt: time + CROSSFADE_SECONDS
    });
    voice.stage = desiredStage;
    voice.trackIndex = desiredTrackIndex;
    voice.source.start(time, segment.offset);

    const previousVoice = currentVoice;
    currentVoice = voice;
    if (!previousVoice) return;
    fadeVoiceToZero(previousVoice, time, CROSSFADE_SECONDS);
    try {
      previousVoice.source.stop(time + CROSSFADE_SECONDS + 0.01);
    } catch {
      cleanupVoice(previousVoice);
    }
  }

  function interactiveSection(index) {
    return INTERACTIVE_MUSIC_SECTIONS[index] ?? INTERACTIVE_MUSIC_SECTIONS[0];
  }

  function createInteractiveLoopVoice(sectionIndex, startedAt, fadeIn = false) {
    const section = interactiveSection(sectionIndex);
    const voice = createVoice({
      buffer: interactiveBackingBuffers.get(desiredTrackIndex),
      gainValue: fadeIn ? 0 : 1,
      kind: "interactive-backing",
      startedAt
    });
    const offset = framesToSeconds(section.offsetFrames);
    voice.source.loop = true;
    voice.source.loopStart = offset;
    voice.source.loopEnd = offset + framesToSeconds(section.durationFrames);
    voice.sectionIndex = sectionIndex;
    voice.trackIndex = desiredTrackIndex;
    if (fadeIn) {
      voice.gain.gain.linearRampToValueAtTime(1, startedAt + CROSSFADE_SECONDS);
      voice.envelope = Object.freeze({
        from: 0,
        to: 1,
        startedAt,
        endsAt: startedAt + CROSSFADE_SECONDS
      });
    }
    voice.source.start(startedAt, offset);
    return voice;
  }

  function startInteractiveImmediately() {
    if (!interactive || !canPlay()) return;
    if (
      currentVoice?.kind === "interactive-backing" &&
      currentVoice.trackIndex === desiredTrackIndex &&
      currentVoice.sectionIndex === desiredInteractiveSection &&
      !pendingTransition
    ) {
      return;
    }
    const previousVoices = [...voices].filter(
      (voice) =>
        voice.audioContext === context &&
        !voice.closing &&
        voice.kind !== "interactive-note"
    );
    cancelTransitionTimer();
    const time = context.currentTime;
    const voice = createInteractiveLoopVoice(desiredInteractiveSection, time, true);
    currentVoice = voice;
    for (const previousVoice of previousVoices) {
      fadeVoiceToZero(previousVoice, time, CROSSFADE_SECONDS);
      if (!voices.has(previousVoice)) continue;
      try {
        previousVoice.source.stop(time + CROSSFADE_SECONDS + 0.01);
      } catch {
        cleanupVoice(previousVoice);
      }
    }
  }

  function nextBeatTime(voice, time) {
    const beatSeconds = framesToSeconds(interactiveSection(voice.sectionIndex).beatFrames);
    const elapsed = Math.max(0, time - voice.startedAt);
    const nextBeat = Math.floor(elapsed / beatSeconds) + 1;
    return voice.startedAt + nextBeat * beatSeconds;
  }

  function scheduleInteractiveStep() {
    if (
      !interactive ||
      !canPlay() ||
      pendingTransition ||
      currentVoice?.kind !== "interactive-backing" ||
      currentVoice.trackIndex !== desiredTrackIndex ||
      currentVoice.sectionIndex === desiredInteractiveSection
    ) {
      return;
    }

    const fromIndex = currentVoice.sectionIndex;
    const direction = desiredInteractiveSection > fromIndex ? 1 : -1;
    const toIndex = fromIndex + direction;
    const fromSection = interactiveSection(fromIndex);
    const toSection = interactiveSection(toIndex);
    const transition = TRANSITION_BY_PAIR.get(`${fromSection.id}:${toSection.id}`);
    if (!transition) {
      startInteractiveImmediately();
      return;
    }

    const activeGeneration = generation;
    const activeContext = context;
    const oldVoice = currentVoice;
    const switchAt = nextBeatTime(oldVoice, activeContext.currentTime);
    const transitionDuration = framesToSeconds(transition.durationFrames);
    const transitionEndsAt = switchAt + transitionDuration;
    const bridgeVoice = createVoice({
      buffer: interactiveBackingBuffers.get(desiredTrackIndex),
      gainValue: 0,
      kind: "interactive-bridge",
      startedAt: switchAt
    });
    bridgeVoice.trackIndex = desiredTrackIndex;
    bridgeVoice.gain.gain.linearRampToValueAtTime(
      1,
      switchAt + TRANSITION_CROSSFADE_SECONDS
    );
    bridgeVoice.envelope = Object.freeze({
      from: 0,
      to: 1,
      startedAt: switchAt,
      endsAt: switchAt + TRANSITION_CROSSFADE_SECONDS
    });
    bridgeVoice.source.start(
      switchAt,
      framesToSeconds(transition.offsetFrames),
      transitionDuration
    );
    const targetVoice = createInteractiveLoopVoice(toIndex, transitionEndsAt, false);

    fadeVoiceToZero(oldVoice, switchAt, TRANSITION_CROSSFADE_SECONDS);
    try {
      oldVoice.source.stop(switchAt + TRANSITION_CROSSFADE_SECONDS + 0.002);
    } catch {
      cleanupVoice(oldVoice);
    }

    const pending = {
      activeContext,
      activeGeneration,
      bridgeVoice,
      fromIndex,
      oldVoice,
      targetVoice,
      toIndex,
      transitionEndsAt,
      trackIndex: desiredTrackIndex
    };
    pendingTransition = pending;
    const finalize = () => {
      transitionTimer = null;
      if (
        pendingTransition !== pending ||
        activeGeneration !== generation ||
        activeContext !== context ||
        !enabled ||
        !interactive ||
        !desiredRunning ||
        desiredTrackIndex !== pending.trackIndex
      ) {
        return;
      }
      cleanupVoice(oldVoice);
      cleanupVoice(bridgeVoice);
      pendingTransition = null;
      currentVoice = targetVoice;
      scheduleInteractiveStep();
    };
    if (typeof setTimeoutImpl === "function") {
      transitionTimer = setTimeoutImpl(
        finalize,
        Math.max(0, (transitionEndsAt - activeContext.currentTime) * 1_000) +
          TRANSITION_SETTLE_MS
      );
    } else {
      currentVoice = targetVoice;
      pendingTransition = null;
    }
  }

  function startDesiredBacking() {
    if (interactive) {
      if (!currentVoice) startInteractiveImmediately();
      else scheduleInteractiveStep();
      return;
    }
    startLegacyStage();
  }

  function prepareDecodedAsset(
    key,
    file,
    activeGeneration,
    activeAssetGeneration,
    activeContext,
    onDecoded
  ) {
    if (
      preparations.has(key) ||
      !enabled ||
      activeGeneration !== generation ||
      activeAssetGeneration !== assetGeneration ||
      activeContext !== context ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }
    const activeController = new AbortController();
    loadControllers.set(key, activeController);
    const work = fetchImpl(file, { cache: "no-store", signal: activeController.signal })
      .then((response) => {
        if (!response?.ok) throw new Error("Unable to load adaptive music.");
        return response.arrayBuffer();
      })
      .then((encodedAudio) => activeContext.decodeAudioData(encodedAudio))
      .then((decodedAudio) => {
        if (
          !enabled ||
          activeGeneration !== generation ||
          activeAssetGeneration !== assetGeneration ||
          activeContext !== context ||
          activeContext.state === "closed"
        ) {
          return;
        }
        onDecoded(decodedAudio);
      })
      .catch(() => {})
      .finally(() => {
        if (preparations.get(key) === work) preparations.delete(key);
        if (loadControllers.get(key) === activeController) loadControllers.delete(key);
      });
    preparations.set(key, work);
  }

  function prepareLegacyTrack(
    trackIndex,
    activeGeneration,
    activeAssetGeneration,
    activeContext
  ) {
    const track = MUSIC_TRACKS[trackIndex];
    if (!track || legacyBuffers.has(trackIndex)) return;
    prepareDecodedAsset(
      `legacy:${trackIndex}`,
      track.file,
      activeGeneration,
      activeAssetGeneration,
      activeContext,
      (decodedAudio) => {
        legacyBuffers.set(trackIndex, decodedAudio);
        if (!interactive && trackIndex === desiredTrackIndex) startDesiredBacking();
      }
    );
  }

  function prepareInteractiveTrack(
    trackIndex,
    activeGeneration,
    activeAssetGeneration,
    activeContext
  ) {
    const track = INTERACTIVE_MUSIC_TRACKS[trackIndex];
    if (!track) return;
    if (!interactiveBackingBuffers.has(trackIndex)) {
      prepareDecodedAsset(
        `interactive-backing:${trackIndex}`,
        track.backingFile,
        activeGeneration,
        activeAssetGeneration,
        activeContext,
        (decodedAudio) => {
          interactiveBackingBuffers.clear();
          interactiveBackingBuffers.set(trackIndex, decodedAudio);
          if (interactive && trackIndex === desiredTrackIndex) startDesiredBacking();
        }
      );
    }
    if (!interactiveNoteBuffers.has(trackIndex)) {
      prepareDecodedAsset(
        `interactive-notes:${trackIndex}`,
        track.notesFile,
        activeGeneration,
        activeAssetGeneration,
        activeContext,
        (decodedAudio) => {
          interactiveNoteBuffers.clear();
          interactiveNoteBuffers.set(trackIndex, decodedAudio);
        }
      );
    }
  }

  function prepareDesiredAssets(activeGeneration, activeAssetGeneration, activeContext) {
    if (interactive) {
      prepareInteractiveTrack(
        desiredTrackIndex,
        activeGeneration,
        activeAssetGeneration,
        activeContext
      );
      return;
    }
    for (const trackIndex of MUSIC_TRACKS.keys()) {
      prepareLegacyTrack(
        trackIndex,
        activeGeneration,
        activeAssetGeneration,
        activeContext
      );
    }
  }

  function ensureContext() {
    if (!enabled || typeof AudioContextClass !== "function" || typeof fetchImpl !== "function") {
      return null;
    }
    if (!context || context.state === "closed") {
      try {
        context = new AudioContextClass({ latencyHint: interactive ? "interactive" : "playback" });
        masterGain = context.createGain();
        masterGain.gain.value = MUSIC_GAIN;
        masterGain.connect(context.destination);
      } catch {
        context = null;
        masterGain = null;
        return null;
      }
    }
    prepareDesiredAssets(generation, assetGeneration, context);
    return context;
  }

  function release() {
    cancelPendingSuspend();
    cancelTransitionTimer();
    abortPreparations();
    legacyBuffers.clear();
    interactiveBackingBuffers.clear();
    interactiveNoteBuffers.clear();
    const closingVoices = [...voices];
    const closingMaster = masterGain;
    const closingContext = context;
    if (pendingSuspendContext === closingContext) {
      pendingSuspendContext = null;
      pendingSuspendWork = null;
    }
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
      if (typeof setTimeoutImpl === "function") setTimeoutImpl(closeResources, RELEASE_DELAY_MS);
      else closeResources();
      return;
    }
    closeResources();
  }

  function rotateInteractiveTrack() {
    assetGeneration += 1;
    cancelTransitionTimer();
    abortPreparations();
    interactiveBackingBuffers.clear();
    interactiveNoteBuffers.clear();
    fadeAndStopVoices(context);
    currentVoice = null;
    if (context) {
      prepareInteractiveTrack(desiredTrackIndex, generation, assetGeneration, context);
    }
  }

  function audibleInteractiveSectionIndex(atTime) {
    if (pendingTransition) {
      return atTime < pendingTransition.transitionEndsAt
        ? pendingTransition.fromIndex
        : pendingTransition.toIndex;
    }
    if (currentVoice?.kind === "interactive-backing") {
      return currentVoice.sectionIndex;
    }
    return desiredInteractiveSection;
  }

  function playCorrectTap(hitNumber) {
    if (
      !enabled ||
      !interactive ||
      !desiredRunning ||
      context?.state !== "running" ||
      !masterGain ||
      !interactiveNoteBuffers.has(desiredTrackIndex)
    ) {
      return false;
    }
    const track = INTERACTIVE_MUSIC_TRACKS[desiredTrackIndex];
    const safeHitNumber = Number.isInteger(hitNumber) && hitNumber > 0 ? hitNumber : 1;
    const motifIndex = (safeHitNumber - 1) % track.motif.length;
    const time = context.currentTime;
    const cue = resolveInteractiveNoteCue(
      track,
      track.motif[motifIndex],
      audibleInteractiveSectionIndex(time)
    );

    let activeNoteVoices = [...noteVoices].filter(
      (voice) => voice.audioContext === context
    );
    while (activeNoteVoices.length >= MAX_NOTE_VOICES) {
      const oldest = activeNoteVoices.shift();
      noteVoices.delete(oldest);
      fadeVoiceToZero(oldest, time, NOTE_RELEASE_SECONDS);
      if (voices.has(oldest)) {
        try {
          oldest.source.stop(time + NOTE_RELEASE_SECONDS + 0.002);
        } catch {
          cleanupVoice(oldest);
        }
      }
    }

    const accent = motifIndex % 4 === 0 ? 1.08 : 1;
    const voice = createVoice({
      buffer: interactiveNoteBuffers.get(desiredTrackIndex),
      gainValue: NOTE_GAIN * accent,
      kind: "interactive-note",
      startedAt: time
    });
    noteVoices.add(voice);
    voice.source.playbackRate.setValueAtTime(cue.playbackRate, time);
    voice.envelope = Object.freeze({
      from: NOTE_GAIN * accent,
      to: NOTE_GAIN * accent,
      startedAt: time,
      endsAt: time + NOTE_SLOT_SECONDS / cue.playbackRate
    });
    voice.source.start(time, cue.noteIndex * NOTE_SLOT_SECONDS, NOTE_SLOT_SECONDS);
    return true;
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

    setInteractive(value) {
      const nextInteractive = Boolean(value);
      if (interactive === nextInteractive) return;
      interactive = nextInteractive;
      desiredRunning = false;
      desiredStage = MUSIC_STAGES.MENU;
      desiredInteractiveSection = 0;
      generation += 1;
      suspendSequence += 1;
      if (context) release();
      if (enabled) ensureContext();
    },

    setStage(stage) {
      if (!LEGACY_SEGMENTS[stage]) return;
      desiredStage = stage;
      if (stage === MUSIC_STAGES.MENU) desiredInteractiveSection = 0;
      if (interactive) {
        if (stage === MUSIC_STAGES.MENU) {
          startInteractiveImmediately();
          return;
        }
        scheduleInteractiveStep();
        return;
      }
      startLegacyStage();
    },

    setInteractiveSection(sectionIndex) {
      if (!Number.isInteger(sectionIndex) || !INTERACTIVE_MUSIC_SECTIONS[sectionIndex]) return;
      desiredInteractiveSection = sectionIndex;
      if (interactive) {
        if (!currentVoice) startInteractiveImmediately();
        else scheduleInteractiveStep();
      }
    },

    startRun() {
      if (!interactive) return;
      for (const voice of [...noteVoices].filter(
        (candidate) => candidate.audioContext === context
      )) {
        fadeAndStopVoices(context, [voice], NOTE_RELEASE_SECONDS);
      }
      desiredInteractiveSection = 0;
      startInteractiveImmediately();
    },

    playCorrectTap,

    advanceTrack(stage = MUSIC_STAGES.MENU) {
      if (LEGACY_SEGMENTS[stage]) desiredStage = stage;
      if (stage === MUSIC_STAGES.MENU) desiredInteractiveSection = 0;
      desiredTrackIndex = (desiredTrackIndex + 1) % MUSIC_TRACKS.length;
      if (interactive) {
        rotateInteractiveTrack();
      } else {
        if (!legacyBuffers.has(desiredTrackIndex) && currentVoice) {
          fadeAndStopVoices(context, [currentVoice]);
        }
        startLegacyStage();
      }
      return MUSIC_TRACKS[desiredTrackIndex].id;
    },

    unlock() {
      if (!enabled) return Promise.resolve(false);
      if (pendingSuspendContext && pendingSuspendContext === context) {
        // A suspend already in flight cannot be cancelled. Replace its context
        // inside this trusted gesture so its completion cannot mute the new run.
        generation += 1;
        desiredRunning = false;
        suspendSequence += 1;
        release();
      }
      cancelPendingSuspend();
      suspendSequence += 1;
      const activeContext = ensureContext();
      if (!activeContext) return Promise.resolve(false);
      const activeGeneration = generation;
      desiredRunning = true;
      if (activeContext.state === "running") {
        startDesiredBacking();
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
            startDesiredBacking();
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
      cancelTransitionTimer();
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
          const work = Promise.resolve(activeContext.suspend())
            .catch(() => {})
            .finally(() => {
              if (pendingSuspendWork !== work) return;
              pendingSuspendContext = null;
              pendingSuspendWork = null;
            });
          pendingSuspendContext = activeContext;
          pendingSuspendWork = work;
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
