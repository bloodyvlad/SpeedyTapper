import assert from "node:assert/strict";
import test from "node:test";

import {
  DODGE_POINTS,
  LEADERBOARD_LIMIT,
  LeaderboardValidationError,
  addEntryToLeaderboard,
  buildLeaderboardWindow,
  emptyLeaderboardDocument,
  normalizeLeaderboardDocument,
  normalizeScoreSubmission,
  rankEntries,
  sanitizePlayerName
} from "../lib/leaderboard-model.js";

function makeEntry(overrides = {}) {
  const index = overrides.index ?? 0;
  return {
    id: `score-${index}`,
    name: `Player ${index}`,
    mode: "normal",
    score: 1_000 + index,
    hits: 10,
    survivalMs: 20_000 + index,
    createdAt: new Date(Date.UTC(2026, 0, 1, 0, 0, index)).toISOString(),
    ...overrides
  };
}

test("player names are normalized while valid Unicode is preserved", () => {
  assert.equal(sanitizePlayerName("  Zoë   🚀  "), "Zoë 🚀");
  assert.throws(() => sanitizePlayerName("\u0000\u200b"), LeaderboardValidationError);
  assert.throws(() => sanitizePlayerName("123456789012345678901"), LeaderboardValidationError);
});

test("score submissions reject invalid modes and numeric values", () => {
  assert.throws(
    () => normalizeScoreSubmission(makeEntry({ mode: "arcade" })),
    LeaderboardValidationError
  );
  assert.throws(
    () => normalizeScoreSubmission(makeEntry({ score: Number.NaN })),
    LeaderboardValidationError
  );
  assert.throws(
    () => normalizeScoreSubmission(makeEntry({ survivalMs: -1 })),
    LeaderboardValidationError
  );
  assert.throws(
    () => normalizeScoreSubmission(makeEntry({ score: 10_001, hits: 10 })),
    LeaderboardValidationError
  );
  assert.throws(
    () => normalizeScoreSubmission(makeEntry({ fastestReactionMs: 80 })),
    LeaderboardValidationError
  );
  assert.throws(
    () =>
      normalizeScoreSubmission(
        makeEntry({ fastestReactionMs: 300, averageReactionMs: 200 })
      ),
    LeaderboardValidationError
  );
});

test("legacy rows gain safe defaults for dodge and reaction statistics", () => {
  const normalized = normalizeScoreSubmission(makeEntry());
  assert.equal(normalized.dodges, 0);
  assert.equal(normalized.fastestReactionMs, null);
  assert.equal(normalized.averageReactionMs, null);
});

test("dodge points and reaction statistics pass score validation", () => {
  const normalized = normalizeScoreSubmission(
    makeEntry({
      score: 10 * 100 + 2 * DODGE_POINTS,
      hits: 10,
      dodges: 2,
      fastestReactionMs: 80,
      averageReactionMs: 250
    })
  );

  assert.equal(normalized.dodges, 2);
  assert.equal(normalized.fastestReactionMs, 80);
  assert.equal(normalized.averageReactionMs, 250);
  assert.throws(
    () => normalizeScoreSubmission({ ...normalized, score: normalized.score - 1 }),
    LeaderboardValidationError
  );
});

test("Normal rankings use survival time and hits as deterministic tie breakers", () => {
  const entries = [
    makeEntry({ index: 1, score: 5_000, survivalMs: 30_000, hits: 30 }),
    makeEntry({ index: 2, score: 5_000, survivalMs: 40_000, hits: 20 }),
    makeEntry({ index: 3, score: 5_000, survivalMs: 40_000, hits: 40 }),
    makeEntry({ index: 4, score: 6_000, survivalMs: 10_000, hits: 10 })
  ];

  assert.deepEqual(
    rankEntries(entries, "normal").map((entry) => entry.id),
    ["score-4", "score-3", "score-2", "score-1"]
  );
});

test("rankings stay mode-specific and retain only the best 1,000 entries", () => {
  const normalEntries = Array.from({ length: 1_005 }, (_, index) => makeEntry({ index }));
  const zenEntry = makeEntry({ index: 99, mode: "zen", score: 999_999, hits: 1_000 });
  const ranked = rankEntries([...normalEntries, zenEntry], "normal");

  assert.equal(ranked.length, LEADERBOARD_LIMIT);
  assert.equal(ranked[0].score, 2_004);
  assert.equal(ranked.at(-1).score, 1_005);
  assert.equal(ranked.some((entry) => entry.mode === "zen"), false);
});

test("a submission reports its rank or null when it misses the Top 1,000", () => {
  let document = emptyLeaderboardDocument();
  for (let index = 0; index < LEADERBOARD_LIMIT; index += 1) {
    document = addEntryToLeaderboard(document, makeEntry({ index })).document;
  }

  const winner = addEntryToLeaderboard(
    document,
    makeEntry({ index: 50, score: 50_000, hits: 50 })
  );
  assert.equal(winner.rank, 1);
  assert.equal(winner.entries.length, LEADERBOARD_LIMIT);

  const outside = addEntryToLeaderboard(
    winner.document,
    makeEntry({ index: 5_001, score: 100, hits: 1 })
  );
  assert.equal(outside.rank, null);
  assert.equal(outside.entries.length, LEADERBOARD_LIMIT);
});

test("a boundary submission can occupy rank 1,000", () => {
  const existing = Array.from({ length: LEADERBOARD_LIMIT - 1 }, (_, offset) =>
    makeEntry({ index: offset + 1 })
  );
  const document = normalizeLeaderboardDocument({ version: 2, normal: existing, zen: [] });
  const boundary = addEntryToLeaderboard(
    document,
    makeEntry({ index: 5_000, score: 1_000, hits: 10 })
  );

  assert.equal(boundary.rank, LEADERBOARD_LIMIT);
  assert.equal(boundary.entries.at(-1).id, "score-5000");
});

test("leaderboard windows show the top five and a submitted result with two neighbors", () => {
  const ranked = rankEntries(
    Array.from({ length: LEADERBOARD_LIMIT }, (_, index) => makeEntry({ index })),
    "normal"
  );

  assert.deepEqual(
    buildLeaderboardWindow(ranked).map((entry) => entry.rank),
    [1, 2, 3, 4, 5]
  );
  assert.deepEqual(
    buildLeaderboardWindow(ranked, 500).map((entry) => entry.rank),
    [1, 2, 3, 4, 5, 498, 499, 500, 501, 502]
  );
  assert.deepEqual(
    buildLeaderboardWindow(ranked, 999).map((entry) => entry.rank),
    [1, 2, 3, 4, 5, 997, 998, 999, 1_000]
  );
});

test("version 2 documents migrate without the previous 20-row truncation", () => {
  const legacyEntries = Array.from({ length: 25 }, (_, index) => makeEntry({ index }));
  const migrated = normalizeLeaderboardDocument({ version: 2, normal: legacyEntries, zen: [] });

  assert.equal(migrated.version, 3);
  assert.equal(migrated.normal.length, 25);
});
