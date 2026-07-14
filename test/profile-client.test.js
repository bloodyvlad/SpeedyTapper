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

test("Google login initializes CSRF and sends only the credential as JSON", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return calls.length === 1
        ? response({ authenticated: false, csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ authenticated: true });
    }
  });
  const credential = "header.payload.signature";

  await client.loginWithGoogleCredential(credential);
  assert.equal(calls[0][0], "/api/session");
  assert.equal(calls[1][0], "/api/auth/google");
  assert.equal(calls[1][1].method, "POST");
  assert.equal(
    calls[1][1].headers["X-SpeedyTapper-CSRF"],
    "csrf-token-with-more-than-thirty-two-characters"
  );
  assert.deepEqual(JSON.parse(calls[1][1].body), { credential });
});

test("verified run submissions contain proof rather than authoritative score aggregates", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return calls.length === 1
        ? response({ csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ rank: 1 });
    }
  });
  const result = {
    runId: "4f27f9de-37de-4c31-8090-279a037bf76a",
    mode: "normal",
    proofVersion: 1,
    ruleset: "reaction-proof-v2",
    buildId: "20260714-11",
    events: [[2, 100, 101, 0, 0], [2, 200, 201, 0, 0], [2, 300, 301, 0, 0], [5, 300, 301]]
  };

  await client.submitResult(result);
  assert.equal(calls[1][0], "/api/runs/finish");
  const payload = JSON.parse(calls[1][1].body);
  assert.deepEqual(payload, result);
  for (const forbidden of ["score", "hits", "dodges", "survivalMs", "name", "email", "password"]) {
    assert.equal(forbidden in payload, false);
  }
});

test("run lifecycle uses server-issued start and explicit abandon endpoints", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return calls.length === 1
        ? response({ csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ runId: "4f27f9de-37de-4c31-8090-279a037bf76a" });
    }
  });

  await client.startRun("normal", "20260714-11");
  await client.abandonRun("4f27f9de-37de-4c31-8090-279a037bf76a");

  assert.equal(calls[1][0], "/api/runs");
  assert.deepEqual(JSON.parse(calls[1][1].body), { mode: "normal", buildId: "20260714-11" });
  assert.equal(calls[2][0], "/api/runs/abandon");
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

test("pet catalog reads plus selection and visibility mutations use dedicated same-origin routes", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return args[0] === "/api/session"
        ? response({ csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ pets: [] });
    }
  });

  await client.getPets();
  await client.selectPet("misha");
  await client.setPetVisibility("misha", false);
  assert.equal(calls[0][0], "/api/pets");
  assert.equal(calls[1][0], "/api/session");
  assert.equal(calls[2][0], "/api/pets/select");
  assert.equal(calls[2][1].method, "POST");
  assert.equal(calls[2][1].credentials, "same-origin");
  assert.equal(
    calls[2][1].headers["X-SpeedyTapper-CSRF"],
    "csrf-token-with-more-than-thirty-two-characters"
  );
  assert.deepEqual(JSON.parse(calls[2][1].body), { petId: "misha" });
  assert.equal(calls[3][0], "/api/pets/selection");
  assert.equal(calls[3][1].method, "PATCH");
  assert.deepEqual(JSON.parse(calls[3][1].body), { petId: "misha", visible: false });
  assert.throws(() => client.selectPet(""), TypeError);
  assert.throws(() => client.setPetVisibility("", true), TypeError);
  assert.throws(() => client.setPetVisibility("misha", "yes"), TypeError);
});

test("theme catalog reads and atomic buy-or-select mutations use dedicated same-origin routes", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return args[0] === "/api/session"
        ? response({ csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ themes: [] });
    }
  });

  await client.getThemes();
  await client.selectTheme("light");
  assert.equal(calls[0][0], "/api/themes");
  assert.equal(calls[1][0], "/api/session");
  assert.equal(calls[2][0], "/api/themes/select");
  assert.equal(calls[2][1].method, "POST");
  assert.equal(calls[2][1].credentials, "same-origin");
  assert.equal(
    calls[2][1].headers["X-SpeedyTapper-CSRF"],
    "csrf-token-with-more-than-thirty-two-characters"
  );
  assert.deepEqual(JSON.parse(calls[2][1].body), { themeId: "light" });
  assert.throws(() => client.selectTheme(""), TypeError);
});

test("achievement reads and claims stay same-origin, CSRF-protected, and send only the achievement ID", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return args[0] === "/api/session"
        ? response({ csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ achievements: [], coinBalance: 6 });
    }
  });

  await client.getAchievements();
  await client.claimAchievement("complete_arcade");

  assert.equal(calls[0][0], "/api/achievements");
  assert.equal(calls[1][0], "/api/session");
  assert.equal(calls[2][0], "/api/achievements/claim");
  assert.equal(calls[2][1].method, "POST");
  assert.equal(calls[2][1].credentials, "same-origin");
  assert.equal(
    calls[2][1].headers["X-SpeedyTapper-CSRF"],
    "csrf-token-with-more-than-thirty-two-characters"
  );
  assert.deepEqual(JSON.parse(calls[2][1].body), { id: "complete_arcade" });
});

test("achievement claims require a stable achievement ID", () => {
  const client = createProfileClient({ fetchImpl: async () => response({}) });
  assert.throws(() => client.claimAchievement(""), /achievement ID/i);
  assert.throws(() => client.claimAchievement(null), /achievement ID/i);
});

test("leaderboard administration reads paginated results and exact review details", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return response({ entries: [] });
    }
  });
  const entryId = "d4e98497-9212-475e-8664-283171ce3910";

  await client.getAdminLeaderboard({
    view: "scan",
    mode: "zen",
    status: "review",
    offset: 100,
    limit: 50
  });
  await client.getAdminLeaderboardEntry(entryId);

  assert.equal(
    calls[0][0],
    "/api/admin/leaderboard?view=scan&mode=zen&status=review&offset=100&limit=50"
  );
  assert.equal(calls[1][0], `/api/admin/leaderboard/entries/${entryId}`);
  assert.throws(() => client.getAdminLeaderboardEntry(""), /entry id/i);
});

test("leaderboard moderation is CSRF protected and destructive reset confirms the target player", async () => {
  const calls = [];
  const client = createProfileClient({
    fetchImpl: async (...args) => {
      calls.push(args);
      return args[0] === "/api/session"
        ? response({ csrfToken: "csrf-token-with-more-than-thirty-two-characters" })
        : response({ applied: true });
    }
  });
  const entryId = "d4e98497-9212-475e-8664-283171ce3910";
  const playerId = "6e74ce9b-fef2-4ca2-812f-4de31c971234";

  await client.quarantineLeaderboardEntry(entryId, {
    reason: "Impossible reaction distribution",
    expectedStatus: "review",
    confirm: true
  });
  await client.deleteLeaderboardEntryAndReset(entryId, {
    reason: "Confirmed manipulated run proof",
    expectedStatus: "quarantined",
    confirm: true,
    confirmPlayerId: playerId
  });

  assert.equal(calls[0][0], "/api/session");
  assert.equal(calls[1][0], `/api/admin/leaderboard/entries/${entryId}/quarantine`);
  assert.equal(calls[1][1].method, "POST");
  assert.deepEqual(JSON.parse(calls[1][1].body), {
    reason: "Impossible reaction distribution",
    expectedStatus: "review",
    confirm: true
  });
  assert.equal(calls[2][0], `/api/admin/leaderboard/entries/${entryId}/delete-reset`);
  assert.deepEqual(JSON.parse(calls[2][1].body), {
    reason: "Confirmed manipulated run proof",
    expectedStatus: "quarantined",
    confirm: true,
    confirmPlayerId: playerId
  });
  assert.equal(
    calls[2][1].headers["X-SpeedyTapper-CSRF"],
    "csrf-token-with-more-than-thirty-two-characters"
  );

  assert.throws(
    () => client.quarantineLeaderboardEntry(entryId, {
      reason: "short",
      expectedStatus: "review",
      confirm: true
    }),
    /reason/i
  );
  assert.throws(
    () => client.deleteLeaderboardEntryAndReset(entryId, {
      reason: "Confirmed manipulated run proof",
      expectedStatus: "quarantined",
      confirm: true
    }),
    /target player/i
  );
  assert.throws(
    () => client.quarantineLeaderboardEntry(entryId, {
      reason: "Impossible reaction distribution",
      expectedStatus: "review",
      confirm: false
    }),
    /explicitly confirmed/i
  );
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
