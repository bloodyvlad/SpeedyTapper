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

    getLeaderboard(mode) {
      return request(`/api/leaderboard?mode=${encodeURIComponent(mode)}`);
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
