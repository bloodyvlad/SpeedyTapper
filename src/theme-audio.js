export const THEME_AUDIO = Object.freeze({
  classic: Object.freeze({
    menuUrl: "./assets/audio/background-daylight-circuit-menu.m4a",
    runUrl: "./assets/audio/background-daylight-circuit.m4a",
    toneBankUrl: "./assets/audio/tap-tones.wav"
  }),
  disco: Object.freeze({
    menuUrl: "./assets/audio/themes/disco/menu.m4a",
    runUrl: "./assets/audio/themes/disco/background.m4a",
    toneBankUrl: "./assets/audio/themes/disco/tap-tones.wav"
  }),
  light: Object.freeze({
    menuUrl: "./assets/audio/themes/light/menu.m4a",
    runUrl: "./assets/audio/themes/light/background.m4a",
    toneBankUrl: "./assets/audio/themes/light/tap-tones.wav"
  }),
  pixel: Object.freeze({
    menuUrl: "./assets/audio/themes/pixel/menu.m4a",
    runUrl: "./assets/audio/themes/pixel/background.m4a",
    toneBankUrl: "./assets/audio/themes/pixel/tap-tones.wav"
  })
});

export function getThemeAudio(themeId) {
  return THEME_AUDIO[themeId] ?? THEME_AUDIO.classic;
}

export function normalizeThemeAudioId(themeId) {
  return typeof themeId === "string" && Object.hasOwn(THEME_AUDIO, themeId)
    ? themeId
    : "classic";
}
