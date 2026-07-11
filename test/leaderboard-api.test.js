import assert from "node:assert/strict";
import test from "node:test";

import { normalizeBlobEtag } from "../api/leaderboard.js";

test("weak Blob ETags are normalized before conditional leaderboard writes", () => {
  assert.equal(normalizeBlobEtag('W/"score-version"'), '"score-version"');
  assert.equal(normalizeBlobEtag('"score-version"'), '"score-version"');
});
