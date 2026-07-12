import assert from "node:assert/strict";
import test from "node:test";

import { createLeaderboardPayload, normalizeBlobEtag } from "../api/leaderboard.js";
import { rankEntries } from "../lib/leaderboard-model.js";

function makeEntry(index) {
  return {
    id: `api-score-${index}`,
    name: `Player ${index}`,
    mode: "normal",
    score: 1_000 + index,
    hits: 10,
    survivalMs: 20_000 + index,
    createdAt: new Date(Date.UTC(2026, 0, 1, 0, 0, index)).toISOString()
  };
}

test("weak Blob ETags are normalized before conditional leaderboard writes", () => {
  assert.equal(normalizeBlobEtag('W/"score-version"'), '"score-version"');
  assert.equal(normalizeBlobEtag('"score-version"'), '"score-version"');
});

test("leaderboard API payloads never serialize the full retained board", () => {
  const retained = rankEntries(
    Array.from({ length: 1_000 }, (_, index) => makeEntry(index)),
    "normal"
  );

  const summary = createLeaderboardPayload("normal", retained);
  assert.equal(summary.totalEntries, 1_000);
  assert.deepEqual(summary.entries.map((entry) => entry.rank), [1, 2, 3, 4, 5]);
  assert.equal("rank" in summary, false);

  const submitted = createLeaderboardPayload("normal", retained, 500);
  assert.equal(submitted.rank, 500);
  assert.deepEqual(
    submitted.entries.map((entry) => entry.rank),
    [1, 2, 3, 4, 5, 498, 499, 500, 501, 502]
  );
  assert.ok(submitted.entries.length <= 10);
});
