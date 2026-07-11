const SOUND_FILES = Object.freeze({
  hum: "./assets/audio/fluorescent-hum.mp3",
  oops: "./assets/audio/oops.mp3"
});

export function createSoundController({
  AudioClass = globalThis.Audio,
  haveFutureData = globalThis.HTMLMediaElement?.HAVE_FUTURE_DATA ?? 3
} = {}) {
  let enabled = false;
  let generation = 0;
  let hum = null;
  let humRequested = false;
  let oops = null;
  let effectRequestId = 0;

  function createAudio(source, { loop = false, volume = 1 } = {}) {
    const audio = new AudioClass();
    audio.preload = "auto";
    audio.loop = loop;
    audio.volume = volume;
    audio.src = source;
    audio.load();
    return audio;
  }

  function ensureAudio() {
    if (!enabled) return [];
    oops ??= createAudio(SOUND_FILES.oops, { volume: 0.8 });
    hum ??= createAudio(SOUND_FILES.hum, { loop: true, volume: 0.42 });
    return [oops, hum];
  }

  function pauseAndReset(audio) {
    if (!audio) return;
    audio.pause();
    audio.currentTime = 0;
  }

  function release(audio) {
    if (!audio) return;
    pauseAndReset(audio);
    audio.removeAttribute("src");
    audio.load();
  }

  function play(audio) {
    if (!enabled || !audio || audio.readyState < haveFutureData) return;
    audio.muted = false;
    audio.currentTime = 0;
    audio.play().catch(() => {});
  }

  return {
    setEnabled(value) {
      const nextEnabled = Boolean(value);
      if (enabled === nextEnabled) return;
      enabled = nextEnabled;
      generation += 1;
      if (enabled) return;

      humRequested = false;
      release(hum);
      release(oops);
      hum = null;
      oops = null;
    },

    unlock() {
      if (!enabled) return;
      const sounds = ensureAudio();
      const unlockGeneration = generation;
      const effectRequestAtStart = effectRequestId;

      for (const audio of sounds) {
        audio.muted = true;
        let playAttempt;
        try {
          playAttempt = audio.play();
        } catch {
          audio.muted = false;
          continue;
        }

        Promise.resolve(playAttempt).then(() => {
          if (!enabled || unlockGeneration !== generation) {
            pauseAndReset(audio);
            return;
          }
          if (audio === hum && humRequested) {
            audio.muted = false;
            return;
          }
          if (audio === oops && effectRequestId !== effectRequestAtStart) {
            audio.muted = false;
            return;
          }
          pauseAndReset(audio);
          audio.muted = false;
        }).catch(() => {
          if (enabled && unlockGeneration === generation) audio.muted = false;
        });
      }
    },

    tileOn() {
      if (!enabled || !hum) return;
      humRequested = true;
      if (hum.readyState < haveFutureData) return;
      play(hum);
    },

    tileOff() {
      humRequested = false;
      if (!enabled || !hum) return;
      pauseAndReset(hum);
      hum.muted = false;
    },

    lifeLost() {
      if (!enabled || !oops) return;
      effectRequestId += 1;
      play(oops);
    }
  };
}
