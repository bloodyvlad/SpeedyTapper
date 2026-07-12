export const COLORS = Object.freeze([
  Object.freeze({ id: "cyan", name: "Cyan", value: "#35e6df", ink: "#062524", glyph: "●" }),
  Object.freeze({ id: "yellow", name: "Yellow", value: "#ffd84d", ink: "#2b2100", glyph: "▲" }),
  Object.freeze({ id: "magenta", name: "Pink", value: "#ff5ba8", ink: "#320018", glyph: "■" }),
  Object.freeze({ id: "lime", name: "Lime", value: "#8ee85a", ink: "#132707", glyph: "◆" }),
  Object.freeze({ id: "orange", name: "Orange", value: "#ff914d", ink: "#321300", glyph: "✚" }),
  Object.freeze({ id: "violet", name: "Violet", value: "#a987ff", ink: "#180c37", glyph: "★" })
]);

export const THEMES = Object.freeze({
  CLASSIC: "classic",
  DISCO: "disco"
});

const DISCO_COLOR_VALUES = Object.freeze({
  cyan: "#65e9f1",
  yellow: "#ffe681",
  magenta: "#ff86bc",
  lime: "#b2ee7c",
  orange: "#ffb06f",
  violet: "#c3a8ff"
});

export const THEME_PALETTES = Object.freeze({
  [THEMES.CLASSIC]: COLORS,
  [THEMES.DISCO]: Object.freeze(
    COLORS.map((color) => Object.freeze({ ...color, value: DISCO_COLOR_VALUES[color.id] }))
  )
});

export const GAME_MODES = Object.freeze({
  NORMAL: "normal",
  ZEN: "zen"
});

export const GAME_CONFIG = Object.freeze({
  startingLives: 3,
  zenDurationMs: 60_000,
  lifeLossRecoveryMs: 1_500,
  twoByTwoStartsAtHits: 4,
  phases: Object.freeze({
    colorPatienceStartsAtMs: 10_000,
    gentleRampStartsAtMs: 20_000,
    rareDecoysStartAtMs: 30_000,
    fourByFourStartsAtMs: 40_000,
    fourByFourChallengeStartsAtMs: 50_000
  }),
  musicStageStartsAtMs: Object.freeze({
    fourByFourPressure: 90_000,
    endurance: 120_000
  }),
  responseWindowsMs: Object.freeze({
    comfortable: 1_000,
    gentleMinimum: 750,
    fourByFourStart: 1_000,
    fourByFourMinimum: 200,
    fourByFourDecreasePerHit: 10
  }),
  chances: Object.freeze({
    soloWrongColor: 0.35,
    rarePhaseWrongColor: 0.25,
    rarePhaseMixedDecoy: 0.1,
    fourByFourWrongColor: 0.25,
    fourByFourChallengeWrongColor: 0.2,
    fourByFourChallengeMixedDecoy: 0.2
  }),
  endlessDifficulty: Object.freeze({
    hitsPerTier: 10,
    maximumDecoys: 6,
    mixedChanceIncreasePerHit: 0.015,
    maximumMixedDecoyChance: 0.8,
    wrongColorDecreasePerTier: 0.02,
    minimumWrongColorChance: 0.06,
    spawnMinimumDecreasePerTierMs: 15,
    spawnMaximumDecreasePerTierMs: 25,
    minimumSpawnDelayMs: 250,
    maximumSpawnDelayFloorMs: 500
  }),
  spawnDelayRangesMs: Object.freeze({
    warmup: Object.freeze([550, 1_100]),
    colorPatience: Object.freeze([550, 1_000]),
    gentleRamp: Object.freeze([500, 950]),
    rareDecoys: Object.freeze([475, 900]),
    fourByFourReset: Object.freeze([525, 950]),
    fourByFourChallenge: Object.freeze([425, 825])
  }),
  dodgePoints: 550,
  scoreFloor: 100,
  scoreCeiling: 1_000
});
