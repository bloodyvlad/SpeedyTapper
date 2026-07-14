const BACKGROUND_MUSIC_URL = "./assets/audio/background-deep-current.m4a";

const BACKGROUND_GAIN = 0.28;
const LOOP_DURATION_SECONDS = 9.6;
const FADE_IN_SECONDS = 0.12;
const FADE_OUT_SECONDS = 0.08;
const STOP_PADDING_SECONDS = 0.01;

function ignoreFailure(promise) {
  Promise.resolve(promise).catch(() => {});
}

export function createMusicController({
  AudioContextClass = globalThis.AudioContext,
  fetchImpl = globalThis.fetch?.bind(globalThis)
} = {}) {
  let enabled = false;
  let desiredPlaying = false;
  let generation = 0;
  let context = null;
  let contextStateHandler = null;
  let masterGain = null;
  let buffer = null;
  let preparation = null;
  let loadController = null;
  let voice = null;

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
      // Already-disconnected nodes require no additional cleanup.
    }
  }

  function cleanupVoice(target = voice) {
    if (!target || target.cleaned) return;
    target.cleaned = true;
    if (voice === target) voice = null;
    target.source.onended = null;
    disconnectNode(target.source);
  }

  function stopVoiceImmediately(target = voice) {
    if (!target || target.cleaned) return;
    try {
      target.source.stop();
    } catch {
      // The source may already have ended or been scheduled to stop.
    }
    cleanupVoice(target);
  }

  function silenceMasterImmediately() {
    if (!context || !masterGain) return;
    const time = context.currentTime;
    masterGain.gain.cancelScheduledValues(time);
    masterGain.gain.setValueAtTime(0, time);
    masterGain.gain.value = 0;
  }

  function fadeAndStopVoice() {
    const target = voice;
    if (!target || target.cleaned || target.closing) return;
    target.closing = true;

    if (!context || context.state !== "running" || !masterGain) {
      stopVoiceImmediately(target);
      return;
    }

    const time = context.currentTime;
    masterGain.gain.cancelScheduledValues(time);
    masterGain.gain.setValueAtTime(BACKGROUND_GAIN, time);
    masterGain.gain.linearRampToValueAtTime(0, time + FADE_OUT_SECONDS);
    try {
      target.source.stop(time + FADE_OUT_SECONDS + STOP_PADDING_SECONDS);
    } catch {
      cleanupVoice(target);
    }
  }

  function startLoop() {
    if (
      !enabled ||
      !desiredPlaying ||
      !buffer ||
      !context ||
      context.state !== "running" ||
      !masterGain
    ) {
      return false;
    }
    if (voice && !voice.cleaned && !voice.closing) return true;
    stopVoiceImmediately();

    const source = context.createBufferSource();
    source.buffer = buffer;
    source.loop = true;
    source.loopStart = 0;
    source.loopEnd = Math.min(LOOP_DURATION_SECONDS, buffer.duration);
    source.connect(masterGain);

    const nextVoice = { cleaned: false, closing: false, source };
    voice = nextVoice;
    source.onended = () => cleanupVoice(nextVoice);

    const time = context.currentTime;
    masterGain.gain.cancelScheduledValues(time);
    masterGain.gain.setValueAtTime(0, time);
    masterGain.gain.linearRampToValueAtTime(BACKGROUND_GAIN, time + FADE_IN_SECONDS);
    try {
      source.start(time);
      return true;
    } catch {
      cleanupVoice(nextVoice);
      silenceMasterImmediately();
      return false;
    }
  }

  async function fetchAndDecode(
    signal,
    preparationGeneration,
    preparationContext
  ) {
    const response = await fetchImpl(BACKGROUND_MUSIC_URL, {
      cache: "no-store",
      signal
    });
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    if (!response?.ok) throw new Error(`Unable to load ${BACKGROUND_MUSIC_URL}`);

    const encodedAudio = await response.arrayBuffer();
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    const decodedAudio = await preparationContext.decodeAudioData(encodedAudio);
    if (!isCurrent(preparationGeneration, preparationContext)) return null;
    if (
      !Number.isFinite(decodedAudio?.duration) ||
      decodedAudio.duration < LOOP_DURATION_SECONDS - 0.001
    ) {
      throw new Error("The background loop is malformed or incomplete.");
    }
    return decodedAudio;
  }

  function prepareAudio(preparationGeneration, preparationContext) {
    if (
      preparation ||
      buffer ||
      !isCurrent(preparationGeneration, preparationContext) ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }

    loadController = new AbortController();
    const activeController = loadController;
    const work = fetchAndDecode(
      activeController.signal,
      preparationGeneration,
      preparationContext
    )
      .then((decodedAudio) => {
        if (!decodedAudio || !isCurrent(preparationGeneration, preparationContext)) return;
        buffer = decodedAudio;
        startLoop();
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
        masterGain.gain.value = 0;
        masterGain.connect(context.destination);
        const observedContext = context;
        contextStateHandler = () => {
          if (context !== observedContext || observedContext.state === "running") return;
          silenceMasterImmediately();
          stopVoiceImmediately();
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
    buffer = null;
    stopVoiceImmediately();
    disconnectNode(masterGain);
    masterGain = null;

    const closingContext = context;
    if (closingContext && contextStateHandler) {
      closingContext.removeEventListener?.("statechange", contextStateHandler);
    }
    contextStateHandler = null;
    context = null;
    if (closingContext && closingContext.state !== "closed") {
      try {
        ignoreFailure(closingContext.close());
      } catch {
        // Music remains optional if the browser cannot close audio output.
      }
    }
  }

  function resumeFromGesture() {
    if (!enabled) return Promise.resolve(false);
    const activeContext = ensureContext();
    if (!activeContext) return Promise.resolve(false);
    const unlockGeneration = generation;

    if (activeContext.state === "running") {
      return Promise.resolve(desiredPlaying ? startLoop() : true);
    }
    try {
      return Promise.resolve(activeContext.resume())
        .then(() => {
          if (!isCurrent(unlockGeneration, activeContext) || activeContext.state !== "running") {
            return false;
          }
          if (desiredPlaying) startLoop();
          return true;
        })
        .catch(() => false);
    } catch {
      return Promise.resolve(false);
    }
  }

  return {
    setEnabled(value) {
      const nextEnabled = Boolean(value);
      if (enabled === nextEnabled) return;
      enabled = nextEnabled;
      generation += 1;
      desiredPlaying = false;
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
      desiredPlaying = true;
      stopVoiceImmediately();
      return resumeFromGesture();
    },

    stopRun() {
      desiredPlaying = false;
      fadeAndStopVoice();
    },

    suspend() {
      desiredPlaying = false;
      silenceMasterImmediately();
      stopVoiceImmediately();
      if (!enabled || !context || context.state !== "running") return;
      try {
        ignoreFailure(context.suspend());
      } catch {
        // The silent master keeps a failed background suspension inaudible.
      }
    }
  };
}
