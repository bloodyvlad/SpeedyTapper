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

function jsonRequest(method, body) {
  return {
    method,
    cache: "no-store",
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json"
    },
    body: JSON.stringify(body ?? {})
  };
}

export function createProfileClient({ fetchImpl = globalThis.fetch } = {}) {
  if (typeof fetchImpl !== "function") {
    throw new TypeError("A fetch implementation is required.");
  }

  const request = async (path, options = {}) => {
    const response = await fetchImpl(path, {
      cache: "no-store",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      ...options
    });
    return readJson(response);
  };

  return Object.freeze({
    getSession() {
      return request("/api/session");
    },

    loginWithGoogleCredential(credential) {
      if (typeof credential !== "string" || credential.length < 20) {
        throw new TypeError("A Google credential is required.");
      }
      return request("/api/auth/google", jsonRequest("POST", { credential }));
    },

    logout() {
      return request("/api/logout", jsonRequest("POST"));
    },

    getProfile(mode) {
      return request(`/api/profile?mode=${encodeURIComponent(mode)}`);
    },

    updateNickname(nickname) {
      return request("/api/profile", jsonRequest("PATCH", { nickname }));
    },

    getAchievements() {
      return request("/api/achievements");
    },

    claimAchievement(id) {
      if (typeof id !== "string" || id.length === 0) {
        throw new TypeError("An achievement ID is required.");
      }
      return request("/api/achievements/claim", jsonRequest("POST", { id }));
    },

    getLeaderboard(mode) {
      return request(`/api/leaderboard?mode=${encodeURIComponent(mode)}`);
    },

    submitResult(result) {
      return request("/api/leaderboard", jsonRequest("POST", result));
    }
  });
}
