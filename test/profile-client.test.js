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
    ruleset: "reaction-proof-v1",
    buildId: "20260713-16",
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

  await client.startRun("zen", "20260713-16");
  await client.abandonRun("4f27f9de-37de-4c31-8090-279a037bf76a");

  assert.equal(calls[1][0], "/api/runs");
  assert.deepEqual(JSON.parse(calls[1][1].body), { mode: "zen", buildId: "20260713-16" });
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

test("pet catalog reads and buy-or-change mutations use the dedicated same-origin routes", async () => {
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
  assert.throws(() => client.selectPet(""), TypeError);
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
