export const COLORS = Object.freeze([
  Object.freeze({ id: "cyan", name: "Cyan", value: "#35e6df", ink: "#062524", glyph: "●" }),
  Object.freeze({ id: "yellow", name: "Yellow", value: "#ffd84d", ink: "#2b2100", glyph: "▲" }),
  Object.freeze({ id: "magenta", name: "Pink", value: "#ff5ba8", ink: "#320018", glyph: "■" }),
  Object.freeze({ id: "lime", name: "Lime", value: "#8ee85a", ink: "#132707", glyph: "◆" }),
  Object.freeze({ id: "orange", name: "Orange", value: "#ff914d", ink: "#321300", glyph: "✚" }),
  Object.freeze({ id: "violet", name: "Violet", value: "#a987ff", ink: "#180c37", glyph: "★" })
]);

export const GAME_CONFIG = Object.freeze({
  startingLives: 3,
  warmupDurationMs: 10_000,
  gridThresholds: Object.freeze([
    Object.freeze({ minHits: 12, dimension: 4 }),
    Object.freeze({ minHits: 4, dimension: 2 }),
    Object.freeze({ minHits: 0, dimension: 1 })
  ]),
  rapidGridStartsAtHits: 12,
  hitsPerSpeedTier: 8,
  responseWindowsMs: Object.freeze([300, 250, 200, 150, 100]),
  warmupResponseWindowMs: 1_000,
  colorResponseWindowMs: 500,
  spawnDelayRangesMs: Object.freeze({
    warmup: Object.freeze([500, 1_100]),
    color: Object.freeze([350, 800]),
    rapid: Object.freeze([
      Object.freeze([300, 650]),
      Object.freeze([260, 550]),
      Object.freeze([220, 450]),
      Object.freeze([180, 360]),
      Object.freeze([140, 280])
    ])
  }),
  maximumDecoys: 6,
  scoreFloor: 100,
  scoreCeiling: 1_000
});
