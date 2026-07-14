export class ProfileApiError extends Error {
  constructor(message, { status = 0, code = "request-failed" } = {}) {
    super(message);
    this.name = "ProfileApiError";
    this.status = status;
    this.code = code;
  }
}

async function readJson(response) {
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ProfileApiError(body.error || "SpeedyTapper services are temporarily unavailable.", {
      status: response.status,
      code: body.code || "request-failed"
    });
  }
  return body;
}

function jsonRequest(method, body, csrfToken) {
  return {
    method,
    cache: "no-store",
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-SpeedyTapper-CSRF": csrfToken
    },
    body: JSON.stringify(body ?? {})
  };
}

function adminMutationBody({ reason, expectedStatus, confirm, confirmPlayerId } = {}, requirePlayerId = false) {
  if (typeof reason !== "string" || reason.trim().length < 8) {
    throw new TypeError("A moderation reason of at least 8 characters is required.");
  }
  if (typeof expectedStatus !== "string" || expectedStatus.length === 0) {
    throw new TypeError("The expected leaderboard status is required.");
  }
  if (confirm !== true) {
    throw new TypeError("The moderation action must be explicitly confirmed.");
  }
  if (requirePlayerId && (typeof confirmPlayerId !== "string" || confirmPlayerId.length === 0)) {
    throw new TypeError("The target player confirmation is required.");
  }
  return {
    reason: reason.trim(),
    expectedStatus,
    confirm: true,
    ...(requirePlayerId ? { confirmPlayerId } : {})
  };
}

export function createProfileClient({ fetchImpl = globalThis.fetch } = {}) {
  if (typeof fetchImpl !== "function") {
    throw new TypeError("A fetch implementation is required.");
  }

  let csrfToken = null;

  const request = async (path, options = {}) => {
    const response = await fetchImpl(path, {
      cache: "no-store",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      ...options
    });
    const body = await readJson(response);
    if (typeof body.csrfToken === "string" && body.csrfToken.length >= 32) {
      csrfToken = body.csrfToken;
    }
    return body;
  };

  const mutation = async (path, method, body = {}) => {
    if (csrfToken === null) await request("/api/session");
    return request(path, jsonRequest(method, body, csrfToken));
  };

  return Object.freeze({
    getSession() {
      return request("/api/session");
    },

    loginWithGoogleCredential(credential) {
      if (typeof credential !== "string" || credential.length < 20) {
        throw new TypeError("A Google credential is required.");
      }
      return mutation("/api/auth/google", "POST", { credential });
    },

    logout() {
      return mutation("/api/logout", "POST");
    },

    getProfile(mode) {
      return request(`/api/profile?mode=${encodeURIComponent(mode)}`);
    },

    updateNickname(nickname) {
      return mutation("/api/profile", "PATCH", { nickname });
    },

    getPets() {
      return request("/api/pets");
    },

    selectPet(petId) {
      if (typeof petId !== "string" || petId.length === 0) {
        throw new TypeError("A pet id is required.");
      }
      return mutation("/api/pets/select", "POST", { petId });
    },

    setPetVisibility(petId, visible) {
      if (typeof petId !== "string" || petId.length === 0) {
        throw new TypeError("A pet id is required.");
      }
      if (typeof visible !== "boolean") {
        throw new TypeError("Pet visibility must be true or false.");
      }
      return mutation("/api/pets/selection", "PATCH", { petId, visible });
    },

    getThemes() {
      return request("/api/themes");
    },

    selectTheme(themeId) {
      if (typeof themeId !== "string" || themeId.length === 0) {
        throw new TypeError("A theme id is required.");
      }
      return mutation("/api/themes/select", "POST", { themeId });
    },

    getAchievements() {
      return request("/api/achievements");
    },

    claimAchievement(id) {
      if (typeof id !== "string" || id.length === 0) {
        throw new TypeError("An achievement ID is required.");
      }
      return mutation("/api/achievements/claim", "POST", { id });
    },

    getLeaderboard(mode) {
      return request(`/api/leaderboard?mode=${encodeURIComponent(mode)}`);
    },

    getAdminLeaderboard({ view = "all", mode = "all", status = "all", offset = 0, limit = 100 } = {}) {
      const query = new URLSearchParams({
        view,
        mode,
        status,
        offset: String(offset),
        limit: String(limit)
      });
      return request(`/api/admin/leaderboard?${query}`);
    },

    getAdminLeaderboardEntry(entryId) {
      if (typeof entryId !== "string" || entryId.length === 0) {
        throw new TypeError("A leaderboard entry id is required.");
      }
      return request(`/api/admin/leaderboard/entries/${encodeURIComponent(entryId)}`);
    },

    quarantineLeaderboardEntry(entryId, options = {}) {
      if (typeof entryId !== "string" || entryId.length === 0) {
        throw new TypeError("A leaderboard entry id is required.");
      }
      return mutation(
        `/api/admin/leaderboard/entries/${encodeURIComponent(entryId)}/quarantine`,
        "POST",
        adminMutationBody(options)
      );
    },

    deleteLeaderboardEntryAndReset(entryId, options = {}) {
      if (typeof entryId !== "string" || entryId.length === 0) {
        throw new TypeError("A leaderboard entry id is required.");
      }
      return mutation(
        `/api/admin/leaderboard/entries/${encodeURIComponent(entryId)}/delete-reset`,
        "POST",
        adminMutationBody(options, true)
      );
    },

    startRun(mode, buildId) {
      return mutation("/api/runs", "POST", { mode, buildId });
    },

    abandonRun(runId) {
      return mutation("/api/runs/abandon", "POST", { runId });
    },

    submitResult(result) {
      return mutation("/api/runs/finish", "POST", result);
    }
  });
}
