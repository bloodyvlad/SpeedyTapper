export const LEADERBOARD_LIMIT = 20;
export const LEADERBOARD_MODES = Object.freeze(["normal", "zen"]);
export const DODGE_POINTS = 550;

const MAX_NAME_LENGTH = 20;
const MAX_SCORE = 999_999_999;
const MAX_HITS = 1_000_000;
const MAX_DODGES = 1_000_000;
const MAX_SURVIVAL_MS = 7 * 24 * 60 * 60 * 1_000;
const MAX_REACTION_MS = 60_000;
const MIN_POINTS_PER_HIT = 100;
const MAX_POINTS_PER_HIT = 1_000;

export class LeaderboardValidationError extends Error {
  constructor(message) {
    super(message);
    this.name = "LeaderboardValidationError";
  }
}

export function normalizeMode(value) {
  if (!LEADERBOARD_MODES.includes(value)) {
    throw new LeaderboardValidationError("Mode must be normal or zen.");
  }
  return value;
}

export function sanitizePlayerName(value) {
  if (typeof value !== "string") {
    throw new LeaderboardValidationError("Enter a player name.");
  }

  const normalized = value
    .normalize("NFKC")
    .replace(/[\p{Cc}\p{Cf}]/gu, "")
    .replace(/\s+/gu, " ")
    .trim();
  const characters = Array.from(normalized);

  if (characters.length === 0) {
    throw new LeaderboardValidationError("Enter a player name.");
  }
  if (characters.length > MAX_NAME_LENGTH) {
    throw new LeaderboardValidationError(`Player names can have at most ${MAX_NAME_LENGTH} characters.`);
  }

  return normalized;
}

function requireInteger(value, label, maximum) {
  if (!Number.isInteger(value) || value < 0 || value > maximum) {
    throw new LeaderboardValidationError(`${label} is invalid.`);
  }
  return value;
}

function normalizeIdentifier(value) {
  if (typeof value !== "string" || !/^[a-zA-Z0-9-]{1,64}$/.test(value)) {
    throw new LeaderboardValidationError("Score identifier is invalid.");
  }
  return value;
}

function normalizeCreatedAt(value) {
  if (typeof value !== "string" || !Number.isFinite(Date.parse(value))) {
    throw new LeaderboardValidationError("Score date is invalid.");
  }
  return new Date(value).toISOString();
}

function normalizeReactionStats(input, hits) {
  const fastestValue = input.fastestReactionMs ?? null;
  const averageValue = input.averageReactionMs ?? null;

  if ((fastestValue === null) !== (averageValue === null)) {
    throw new LeaderboardValidationError("Reaction statistics must include fastest and average times.");
  }
  if (fastestValue === null) {
    return { fastestReactionMs: null, averageReactionMs: null };
  }

  const fastestReactionMs = requireInteger(fastestValue, "Fastest reaction", MAX_REACTION_MS);
  const averageReactionMs = requireInteger(averageValue, "Average reaction", MAX_REACTION_MS);
  if (hits === 0 || fastestReactionMs > averageReactionMs) {
    throw new LeaderboardValidationError("Reaction statistics are invalid.");
  }

  return { fastestReactionMs, averageReactionMs };
}

export function normalizeScoreSubmission(input, metadata = {}) {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new LeaderboardValidationError("Score data is invalid.");
  }

  const score = requireInteger(input.score, "Score", MAX_SCORE);
  const hits = requireInteger(input.hits, "Tap count", MAX_HITS);
  const dodges = requireInteger(input.dodges ?? 0, "Dodge count", MAX_DODGES);
  const reactionStats = normalizeReactionStats(input, hits);
  const dodgeScore = dodges * DODGE_POINTS;
  const minimumPlausibleScore = hits * MIN_POINTS_PER_HIT + dodgeScore;
  const maximumPlausibleScore = hits * MAX_POINTS_PER_HIT + dodgeScore;
  if (score < minimumPlausibleScore || score > maximumPlausibleScore) {
    throw new LeaderboardValidationError("Score does not match the run statistics.");
  }

  return Object.freeze({
    id: normalizeIdentifier(metadata.id ?? input.id),
    name: sanitizePlayerName(input.name),
    mode: normalizeMode(input.mode),
    score,
    hits,
    dodges,
    ...reactionStats,
    survivalMs: requireInteger(input.survivalMs, "Survival time", MAX_SURVIVAL_MS),
    createdAt: normalizeCreatedAt(metadata.createdAt ?? input.createdAt)
  });
}

function compareEntries(left, right) {
  return (
    right.score - left.score ||
    (left.mode === "normal" ? right.survivalMs - left.survivalMs : 0) ||
    right.hits - left.hits ||
    left.createdAt.localeCompare(right.createdAt) ||
    left.id.localeCompare(right.id)
  );
}

export function rankEntries(entries, mode, limit = LEADERBOARD_LIMIT) {
  const normalizedMode = normalizeMode(mode);
  const normalizedEntries = [];

  for (const entry of Array.isArray(entries) ? entries : []) {
    try {
      const normalized = normalizeScoreSubmission(entry);
      if (normalized.mode === normalizedMode) normalizedEntries.push(normalized);
    } catch {
      // Ignore corrupt legacy rows rather than making the whole board unavailable.
    }
  }

  return normalizedEntries.sort(compareEntries).slice(0, limit);
}

export function emptyLeaderboardDocument() {
  return { version: 2, normal: [], zen: [] };
}

export function normalizeLeaderboardDocument(value) {
  const source = value && typeof value === "object" ? value : {};
  return {
    version: 2,
    normal: rankEntries(source.normal, "normal"),
    zen: rankEntries(source.zen, "zen")
  };
}

export function addEntryToLeaderboard(document, entry) {
  const current = normalizeLeaderboardDocument(document);
  const normalizedEntry = normalizeScoreSubmission(entry);
  const entries = rankEntries([...current[normalizedEntry.mode], normalizedEntry], normalizedEntry.mode);
  const next = { ...current, [normalizedEntry.mode]: entries };
  const rank = entries.findIndex((candidate) => candidate.id === normalizedEntry.id);

  return {
    document: next,
    entries,
    rank: rank === -1 ? null : rank + 1
  };
}
