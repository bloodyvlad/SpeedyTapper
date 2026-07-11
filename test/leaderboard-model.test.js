import assert from "node:assert/strict";
import test from "node:test";

import {
  DODGE_POINTS,
  LeaderboardValidationError,
  addEntryToLeaderboard,
  emptyLeaderboardDocument,
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

test("rankings stay mode-specific and retain only the best 20 entries", () => {
  const normalEntries = Array.from({ length: 25 }, (_, index) => makeEntry({ index }));
  const zenEntry = makeEntry({ index: 99, mode: "zen", score: 999_999, hits: 1_000 });
  const ranked = rankEntries([...normalEntries, zenEntry], "normal");

  assert.equal(ranked.length, 20);
  assert.equal(ranked[0].score, 1_024);
  assert.equal(ranked.at(-1).score, 1_005);
  assert.equal(ranked.some((entry) => entry.mode === "zen"), false);
});

test("a submission reports its rank or null when it misses the Top 20", () => {
  let document = emptyLeaderboardDocument();
  for (let index = 0; index < 20; index += 1) {
    document = addEntryToLeaderboard(document, makeEntry({ index })).document;
  }

  const winner = addEntryToLeaderboard(
    document,
    makeEntry({ index: 50, score: 50_000, hits: 50 })
  );
  assert.equal(winner.rank, 1);
  assert.equal(winner.entries.length, 20);

  const outside = addEntryToLeaderboard(
    winner.document,
    makeEntry({ index: 51, score: 100, hits: 1 })
  );
  assert.equal(outside.rank, null);
  assert.equal(outside.entries.length, 20);
});
