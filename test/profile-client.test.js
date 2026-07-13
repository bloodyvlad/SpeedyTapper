import assert from "node:assert/strict";
import test from "node:test";

import { createProfileClient, ProfileApiError } from "../src/profile-client.js";

function response(body, { ok = true, status = 200 } = {}) {
  return {
    ok,
    status,
    async json() {
      return body;
    }
  };
}

test("profile client keeps authenticated requests same-origin and uncached", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return response({ authenticated: false });
    }
  });

  await client.getSession();
  assert.equal(calls[0][0], "/api/session");
  assert.equal(calls[0][1].credentials, "same-origin");
  assert.equal(calls[0][1].cache, "no-store");
});

test("Google login sends only the credential as JSON", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return response({ authenticated: true });
    }
  });
  const credential = "header.payload.signature";

  await client.loginWithGoogleCredential(credential);
  assert.equal(calls[0][0], "/api/auth/google");
  assert.equal(calls[0][1].method, "POST");
  assert.deepEqual(JSON.parse(calls[0][1].body), { credential });
});

test("score submissions contain no name, email, or password fields", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return response({ rank: 1 });
    }
  });
  const result = {
    mode: "normal",
    score: 12_345,
    hits: 30,
    dodges: 4,
    fastestReactionMs: 148,
    averageReactionMs: 287,
    survivalMs: 82_000,
    speedRatings: { godlike: 2, perfect: 11, great: 13, good: 4 }
  };

  await client.submitResult(result);
  const payload = JSON.parse(calls[0][1].body);
  assert.deepEqual(payload, result);
  assert.equal("name" in payload, false);
  assert.equal("email" in payload, false);
  assert.equal("password" in payload, false);
});

test("profile context requests are mode-specific", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return response({ profile: { nickname: "Player" } });
    }
  });

  await client.getProfile("zen");
  assert.equal(calls[0][0], "/api/profile?mode=zen");
});

test("achievement reads and claims stay same-origin and send only the achievement ID", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return response({ achievements: [], coinBalance: 6 });
    }
  });

  await client.getAchievements();
  await client.claimAchievement("complete_zen");

  assert.equal(calls[0][0], "/api/achievements");
  assert.equal(calls[0][1].credentials, "same-origin");
  assert.equal(calls[0][1].cache, "no-store");
  assert.equal(calls[1][0], "/api/achievements/claim");
  assert.equal(calls[1][1].method, "POST");
  assert.equal(calls[1][1].credentials, "same-origin");
  assert.deepEqual(JSON.parse(calls[1][1].body), { id: "complete_zen" });
});

test("achievement claims require a stable achievement ID", async () => {
  const client = createProfileClient({
    fetchImpl: async () => response({})
  });

  assert.throws(() => client.claimAchievement(""), /achievement ID/i);
  assert.throws(() => client.claimAchievement(null), /achievement ID/i);
});

test("API failures retain server error codes for login and ranking UX", async () => {
  const client = createProfileClient({
    fetchImpl: async () => response(
      { error: "Sign in first.", code: "authentication-required" },
      { ok: false, status: 401 }
    )
  });

  await assert.rejects(
    client.getLeaderboard("normal"),
    (error) =>
      error instanceof ProfileApiError &&
      error.status === 401 &&
      error.code === "authentication-required"
  );
});
