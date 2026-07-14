import { getThemeAudio, normalizeThemeAudioId } from "./theme-audio.js?v=20260714-12";

const BACKGROUND_GAIN = 0.42;
const LOOP_DURATION_SECONDS = 12;
const FADE_IN_SECONDS = 0.12;
const FADE_OUT_SECONDS = 0.08;
const VOLUME_RAMP_SECONDS = 0.025;
const STOP_PADDING_SECONDS = 0.01;

function clampVolume(value) {
  const numericValue = Number(value);
  if (!Number.isFinite(numericValue)) return 1;
  return Math.max(0, Math.min(1, numericValue));
}

function ignoreFailure(promise) {
  Promise.resolve(promise).catch(() => {});
}

export function createMusicController({
  AudioContextClass = globalThis.AudioContext,
  fetchImpl = globalThis.fetch?.bind(globalThis)
} = {}) {
  let enabled = false;
  let themeId = "classic";
  let themeAudio = getThemeAudio(themeId);
  let desiredScene = null;
  let generation = 0;
  let context = null;
  let contextStateHandler = null;
  let masterGain = null;
  let volumeGain = null;
  let volume = 1;
  let buffers = { menu: null, run: null };
  let loadAttempted = false;
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

  function applyVolume({ immediate = false } = {}) {
    if (!context || !volumeGain) return;
    const time = context.currentTime;
    const target = BACKGROUND_GAIN * volume;
    const gain = volumeGain.gain;
    gain.cancelScheduledValues(time);
    if (immediate || context.state !== "running") {
      gain.setValueAtTime(target, time);
      gain.value = target;
      return;
    }
    gain.setValueAtTime(gain.value, time);
    gain.linearRampToValueAtTime(target, time + VOLUME_RAMP_SECONDS);
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
    masterGain.gain.setValueAtTime(1, time);
    masterGain.gain.linearRampToValueAtTime(0, time + FADE_OUT_SECONDS);
    try {
      target.source.stop(time + FADE_OUT_SECONDS + STOP_PADDING_SECONDS);
    } catch {
      cleanupVoice(target);
    }
  }

  function startLoop() {
    const buffer = desiredScene ? buffers[desiredScene] : null;
    if (
      !enabled ||
      !desiredScene ||
      !buffer ||
      !context ||
      context.state !== "running" ||
      !masterGain
    ) {
      return false;
    }
    if (
      voice &&
      !voice.cleaned &&
      !voice.closing &&
      voice.scene === desiredScene &&
      voice.themeId === themeId
    ) {
      return true;
    }
    silenceMasterImmediately();
    stopVoiceImmediately();

    const source = context.createBufferSource();
    source.buffer = buffer;
    source.loop = true;
    source.loopStart = 0;
    source.loopEnd = Math.min(LOOP_DURATION_SECONDS, buffer.duration);
    source.connect(masterGain);

    const nextVoice = {
      cleaned: false,
      closing: false,
      scene: desiredScene,
      themeId,
      source
    };
    voice = nextVoice;
    source.onended = () => cleanupVoice(nextVoice);

    const time = context.currentTime;
    masterGain.gain.cancelScheduledValues(time);
    masterGain.gain.setValueAtTime(0, time);
    masterGain.gain.linearRampToValueAtTime(1, time + FADE_IN_SECONDS);
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
    url,
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
      decodedAudio.duration < LOOP_DURATION_SECONDS - 0.001
    ) {
      throw new Error("The background loop is malformed or incomplete.");
    }
    return decodedAudio;
  }

  function prepareAudio(preparationGeneration, preparationContext) {
    if (
      preparation ||
      loadAttempted ||
      !isCurrent(preparationGeneration, preparationContext) ||
      typeof fetchImpl !== "function"
    ) {
      return;
    }

    loadAttempted = true;
    loadController = new AbortController();
    const activeController = loadController;
    const entries = [
      ["menu", themeAudio.menuUrl],
      ["run", themeAudio.runUrl]
    ];
    const work = Promise.allSettled(
      entries.map(([scene, url]) =>
        fetchAndDecode(
          url,
          activeController.signal,
          preparationGeneration,
          preparationContext
        ).then((decodedAudio) => ({ decodedAudio, scene }))
      )
    )
      .then((results) => {
        if (!isCurrent(preparationGeneration, preparationContext)) return;
        for (const result of results) {
          if (result.status !== "fulfilled" || !result.value.decodedAudio) continue;
          buffers[result.value.scene] = result.value.decodedAudio;
        }
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
        volumeGain = context.createGain();
        volumeGain.gain.value = BACKGROUND_GAIN * volume;
        masterGain.connect(volumeGain);
        volumeGain.connect(context.destination);
        applyVolume({ immediate: true });
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
    buffers = { menu: null, run: null };
    loadAttempted = false;
    stopVoiceImmediately();
    disconnectNode(masterGain);
    masterGain = null;
    disconnectNode(volumeGain);
    volumeGain = null;

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

  function replaceThemeAssets() {
    loadController?.abort();
    loadController = null;
    preparation = null;
    buffers = { menu: null, run: null };
    loadAttempted = false;
    if (context && context.state !== "closed") {
      prepareAudio(generation, context);
    }
  }

  function resumeFromGesture() {
    if (!enabled) return Promise.resolve(false);
    const activeContext = ensureContext();
    if (!activeContext) return Promise.resolve(false);
    const unlockGeneration = generation;

    if (activeContext.state === "running") {
      return Promise.resolve(desiredScene ? startLoop() : true);
    }
    try {
      return Promise.resolve(activeContext.resume())
        .then(() => {
          if (!isCurrent(unlockGeneration, activeContext) || activeContext.state !== "running") {
            return false;
          }
          if (desiredScene) startLoop();
          return true;
        })
        .catch(() => false);
    } catch {
      return Promise.resolve(false);
    }
  }

  return {
    setTheme(value) {
      const nextThemeId = normalizeThemeAudioId(value);
      const nextThemeAudio = getThemeAudio(nextThemeId);
      if (themeId === nextThemeId) return themeId;
      themeId = nextThemeId;
      themeAudio = nextThemeAudio;
      generation += 1;
      if (enabled) {
        replaceThemeAssets();
        ensureContext();
      }
      return themeId;
    },

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
      if (!enabled) {
        releaseAudio();
        return;
      }
      ensureContext();
    },

    unlock() {
      return resumeFromGesture();
    },

    startMenu({ resume = true } = {}) {
      desiredScene = "menu";
      if (!enabled) return Promise.resolve(false);
      if (resume) return resumeFromGesture();
      const activeContext = ensureContext();
      if (!activeContext || activeContext.state !== "running") {
        return Promise.resolve(Boolean(activeContext));
      }
      return Promise.resolve(startLoop());
    },

    startRun() {
      desiredScene = "run";
      if (!enabled) return Promise.resolve(false);
      return resumeFromGesture();
    },

    stopRun() {
      desiredScene = null;
      fadeAndStopVoice();
    },

    suspend() {
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
