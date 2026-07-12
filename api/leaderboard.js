import { randomUUID } from "node:crypto";
import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";

import { BlobPreconditionFailedError, get, put } from "@vercel/blob";

import {
  LeaderboardValidationError,
  addEntryToLeaderboard,
  buildLeaderboardWindow,
  emptyLeaderboardDocument,
  normalizeLeaderboardDocument,
  normalizeMode,
  normalizeScoreSubmission
} from "../lib/leaderboard-model.js";

const BLOB_PATH = "leaderboard/v1/scores.json";
const LOCAL_PATH = resolve(process.cwd(), ".data/leaderboard.json");
const MAX_BODY_BYTES = 8_192;
const MAX_WRITE_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW_MS = 60_000;
const MAX_SUBMISSIONS_PER_WINDOW = 10;

const submissionBuckets = new Map();
let localMutationQueue = Promise.resolve();

class LeaderboardRateLimitError extends Error {
  constructor(retryAfterSeconds) {
    super("Too many score submissions. Try again shortly.");
    this.name = "LeaderboardRateLimitError";
    this.retryAfterSeconds = retryAfterSeconds;
  }
}

function isVercelRuntime() {
  return process.env.VERCEL === "1";
}

export function normalizeBlobEtag(etag) {
  return typeof etag === "string" ? etag.replace(/^W\//, "") : etag;
}

export function createLeaderboardPayload(mode, entries, playerRank = undefined) {
  const normalizedMode = normalizeMode(mode);
  const retainedEntries = Array.isArray(entries) ? entries : [];
  const payload = {
    mode: normalizedMode,
    entries: buildLeaderboardWindow(retainedEntries, playerRank),
    totalEntries: retainedEntries.length
  };
  if (playerRank !== undefined) payload.rank = playerRank;
  return payload;
}

async function readRequestBody(request) {
  if (request.body && typeof request.body === "object" && !Buffer.isBuffer(request.body)) {
    return request.body;
  }
  if (typeof request.body === "string") {
    if (Buffer.byteLength(request.body) > MAX_BODY_BYTES) {
      throw new LeaderboardValidationError("Score data is too large.");
    }
    try {
      return JSON.parse(request.body);
    } catch {
      throw new LeaderboardValidationError("Score data must be valid JSON.");
    }
  }

  let body = "";
  for await (const chunk of request) {
    body += chunk;
    if (Buffer.byteLength(body) > MAX_BODY_BYTES) {
      throw new LeaderboardValidationError("Score data is too large.");
    }
  }

  try {
    return JSON.parse(body || "{}");
  } catch {
    throw new LeaderboardValidationError("Score data must be valid JSON.");
  }
}

async function readRemoteDocument() {
  const result = await get(BLOB_PATH, {
    access: "private",
    token: process.env.BLOB_READ_WRITE_TOKEN,
    useCache: false
  });
  if (!result || result.statusCode !== 200) {
    return { document: emptyLeaderboardDocument(), etag: null };
  }

  const raw = await new Response(result.stream).json();
  return {
    document: normalizeLeaderboardDocument(raw),
    etag: normalizeBlobEtag(result.blob.etag)
  };
}

async function writeRemoteDocument(document, etag) {
  await put(BLOB_PATH, JSON.stringify(document), {
    access: "private",
    addRandomSuffix: false,
    allowOverwrite: etag !== null,
    cacheControlMaxAge: 60,
    contentType: "application/json",
    token: process.env.BLOB_READ_WRITE_TOKEN,
    ...(etag ? { ifMatch: etag } : {})
  });
}

async function readLocalDocument() {
  try {
    return normalizeLeaderboardDocument(JSON.parse(await readFile(LOCAL_PATH, "utf8")));
  } catch (error) {
    if (error?.code === "ENOENT") return emptyLeaderboardDocument();
    throw error;
  }
}

async function writeLocalDocument(document) {
  const temporaryPath = `${LOCAL_PATH}.${process.pid}.${randomUUID()}.tmp`;
  await mkdir(dirname(LOCAL_PATH), { recursive: true });
  await writeFile(temporaryPath, JSON.stringify(document, null, 2), "utf8");
  await rename(temporaryPath, LOCAL_PATH);
}

async function getDocument() {
  if (isVercelRuntime()) return (await readRemoteDocument()).document;
  return readLocalDocument();
}

async function submitRemoteEntry(entry) {
  for (let attempt = 0; attempt < MAX_WRITE_ATTEMPTS; attempt += 1) {
    const current = await readRemoteDocument();
    const ranked = addEntryToLeaderboard(current.document, entry);

    try {
      await writeRemoteDocument(ranked.document, current.etag);
      return ranked;
    } catch (error) {
      const canRetry =
        attempt < MAX_WRITE_ATTEMPTS - 1 &&
        (error instanceof BlobPreconditionFailedError || current.etag === null);
      if (!canRetry) throw error;
    }
  }

  throw new Error("Leaderboard update failed after retries.");
}

async function submitEntry(entry) {
  if (isVercelRuntime()) return submitRemoteEntry(entry);
  const mutation = localMutationQueue.then(async () => {
    const ranked = addEntryToLeaderboard(await readLocalDocument(), entry);
    await writeLocalDocument(ranked.document);
    return ranked;
  });
  localMutationQueue = mutation.then(
    () => undefined,
    () => undefined
  );
  return mutation;
}

function enforceSubmissionRate(request) {
  const forwardedAddress = request.headers?.["x-forwarded-for"];
  const clientAddress =
    (Array.isArray(forwardedAddress) ? forwardedAddress[0] : forwardedAddress)
      ?.split(",")[0]
      .trim() ||
    request.socket?.remoteAddress ||
    "unknown";
  const currentTime = Date.now();
  const windowStart = currentTime - RATE_LIMIT_WINDOW_MS;
  const recentSubmissions = (submissionBuckets.get(clientAddress) ?? []).filter(
    (timestamp) => timestamp > windowStart
  );

  if (recentSubmissions.length >= MAX_SUBMISSIONS_PER_WINDOW) {
    const retryAfterSeconds = Math.max(
      1,
      Math.ceil((recentSubmissions[0] + RATE_LIMIT_WINDOW_MS - currentTime) / 1_000)
    );
    throw new LeaderboardRateLimitError(retryAfterSeconds);
  }

  recentSubmissions.push(currentTime);
  submissionBuckets.set(clientAddress, recentSubmissions);

  if (submissionBuckets.size > 500) {
    for (const [address, timestamps] of submissionBuckets) {
      if (timestamps.every((timestamp) => timestamp <= windowStart)) {
        submissionBuckets.delete(address);
      }
    }
  }
}

function sendJson(response, statusCode, body) {
  response.statusCode = statusCode;
  response.setHeader("Cache-Control", "no-store");
  response.setHeader("Content-Type", "application/json; charset=utf-8");
  response.end(JSON.stringify(body));
}

export default async function leaderboardHandler(request, response) {
  try {
    if (request.method === "GET") {
      const requestUrl = new URL(request.url ?? "/api/leaderboard", "http://localhost");
      const mode = normalizeMode(requestUrl.searchParams.get("mode") ?? "normal");
      const document = await getDocument();
      sendJson(response, 200, createLeaderboardPayload(mode, document[mode]));
      return;
    }

    if (request.method === "POST") {
      enforceSubmissionRate(request);
      const body = await readRequestBody(request);
      const entry = normalizeScoreSubmission(body, {
        id: randomUUID(),
        createdAt: new Date().toISOString()
      });
      const result = await submitEntry(entry);
      sendJson(response, 201, createLeaderboardPayload(entry.mode, result.entries, result.rank));
      return;
    }

    response.setHeader("Allow", "GET, POST");
    sendJson(response, 405, { error: "Method not allowed." });
  } catch (error) {
    if (error instanceof LeaderboardRateLimitError) {
      response.setHeader("Retry-After", String(error.retryAfterSeconds));
      sendJson(response, 429, { error: error.message });
      return;
    }
    if (error instanceof LeaderboardValidationError) {
      sendJson(response, 400, { error: error.message });
      return;
    }

    console.error("Leaderboard request failed", error);
    sendJson(response, 503, { error: "Leaderboard is temporarily unavailable." });
  }
}
