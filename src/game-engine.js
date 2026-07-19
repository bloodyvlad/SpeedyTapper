import { COLORS, GAME_CONFIG, GAME_MODES } from "./config.js?v=20260719-1";

export const GAME_STATES = Object.freeze({
  IDLE: "idle",
  WAITING: "waiting",
  ACTIVE: "active",
  GAME_OVER: "game-over"
});

export const ROUND_KINDS = Object.freeze({
  TARGET: "target"
});

export const SPEED_RATING_IDS = Object.freeze({
  GODLIKE: "godlike",
  PERFECT: "perfect",
  GREAT: "great",
  GOOD: "good"
});

export const SPEED_RATINGS = Object.freeze([
  Object.freeze({ id: SPEED_RATING_IDS.GODLIKE, label: "Godlike", maximumExclusiveMs: 250 }),
  Object.freeze({ id: SPEED_RATING_IDS.PERFECT, label: "Perfect", maximumExclusiveMs: 350 }),
  Object.freeze({ id: SPEED_RATING_IDS.GREAT, label: "Great", maximumExclusiveMs: 450 }),
  Object.freeze({ id: SPEED_RATING_IDS.GOOD, label: "Good", maximumExclusiveMs: Infinity })
]);

export const MAX_DECOY_LIFETIME_MS = 750;

const EMPTY_CELL = Object.freeze({ kind: "idle", colorIndex: null });

function clamp(value, minimum, maximum) {
  return Math.min(maximum, Math.max(minimum, value));
}

function randomInteger(random, maximumExclusive) {
  return Math.floor(clamp(random(), 0, 0.999999999) * maximumExclusive);
}

export function orthogonalNeighbors(index, dimension) {
  const row = Math.floor(index / dimension);
  const column = index % dimension;
  const neighbors = [];

  if (row > 0) neighbors.push(index - dimension);
  if (column < dimension - 1) neighbors.push(index + 1);
  if (row < dimension - 1) neighbors.push(index + dimension);
  if (column > 0) neighbors.push(index - 1);

  return neighbors;
}

export function resolveDifficulty(hits, elapsedMs, challengeHits = 0, config = GAME_CONFIG) {
  // The ranked proof uses integer milliseconds. Resolve every phase and
  // response window from that same clock so the browser and PHP replay cannot
  // disagree around a sub-millisecond phase boundary.
  elapsedMs = Math.round(Math.max(0, elapsedMs));
  const { phases, responseWindowsMs, spawnDelayRangesMs } = config;
  const gridDimension =
    elapsedMs >= phases.fourByFourStartsAtMs
      ? 4
      : hits >= config.twoByTwoStartsAtHits
        ? 2
        : 1;

  let phaseId = "warmup";
  let phaseName = "Warm-up";
  let responseWindowMs = responseWindowsMs.comfortable;
  let spawnDelayRangeMs = spawnDelayRangesMs.warmup;
  let decoySpawnDelayRangeMs = null;
  let maximumActiveDecoys = 0;
  let challengeTier = 0;

  if (elapsedMs >= phases.colorPatienceStartsAtMs) {
    phaseId = "color-patience";
    phaseName = "Color patience";
    spawnDelayRangeMs = spawnDelayRangesMs.colorPatience;
    decoySpawnDelayRangeMs = config.decoys.spawnDelayRangesMs.colorPatience;
    maximumActiveDecoys = 1;
  }

  if (elapsedMs >= phases.gentleRampStartsAtMs) {
    const rampDuration = phases.rareDecoysStartAtMs - phases.gentleRampStartsAtMs;
    const rampProgress = clamp((elapsedMs - phases.gentleRampStartsAtMs) / rampDuration, 0, 1);
    phaseId = "gentle-ramp";
    phaseName = "Gentle pace";
    responseWindowMs = Math.round(
      responseWindowsMs.comfortable -
        (responseWindowsMs.comfortable - responseWindowsMs.gentleMinimum) * rampProgress
    );
    spawnDelayRangeMs = spawnDelayRangesMs.gentleRamp;
    decoySpawnDelayRangeMs = config.decoys.spawnDelayRangesMs.gentleRamp;
    maximumActiveDecoys = 1;
  }

  if (elapsedMs >= phases.rareDecoysStartAtMs) {
    phaseId = "rare-decoys";
    phaseName = "Rare decoys";
    responseWindowMs = responseWindowsMs.gentleMinimum;
    spawnDelayRangeMs = spawnDelayRangesMs.rareDecoys;
    decoySpawnDelayRangeMs = config.decoys.spawnDelayRangesMs.rareDecoys;
    maximumActiveDecoys = 2;
  }

  if (elapsedMs >= phases.fourByFourStartsAtMs) {
    phaseId = "four-by-four-reset";
    phaseName = "16-cell reset";
    responseWindowMs = responseWindowsMs.fourByFourStart;
    spawnDelayRangeMs = spawnDelayRangesMs.fourByFourReset;
    decoySpawnDelayRangeMs = config.decoys.spawnDelayRangesMs.fourByFourReset;
    maximumActiveDecoys = 1;
  }

  if (elapsedMs >= phases.fourByFourChallengeStartsAtMs) {
    const endless = config.endlessDifficulty;
    challengeTier = Math.floor(challengeHits / endless.hitsPerTier);
    phaseId = "four-by-four-challenge";
    phaseName = [
      "16-cell focus",
      "Twin decoys",
      "Triple threat",
      "Pressure",
      "Overdrive",
      "Endurance"
    ][Math.min(challengeTier, 5)];
    responseWindowMs = Math.max(
      responseWindowsMs.fourByFourMinimum,
      responseWindowsMs.fourByFourStart -
        challengeHits * responseWindowsMs.fourByFourDecreasePerHit
    );
    spawnDelayRangeMs = [
      Math.max(
        endless.minimumSpawnDelayMs,
        spawnDelayRangesMs.fourByFourChallenge[0] -
          challengeTier * endless.spawnMinimumDecreasePerTierMs
      ),
      Math.max(
        endless.maximumSpawnDelayFloorMs,
        spawnDelayRangesMs.fourByFourChallenge[1] -
          challengeTier * endless.spawnMaximumDecreasePerTierMs
      )
    ];
    const decoyMaximumDelayMs = Math.max(
      endless.decoyMaximumDelayFloorMs,
      config.decoys.spawnDelayRangesMs.fourByFourChallenge[1] -
        challengeTier * endless.decoyMaximumDecreasePerTierMs
    );
    decoySpawnDelayRangeMs = [
      endless.decoyMinimumDelayMs,
      decoyMaximumDelayMs
    ];
    maximumActiveDecoys = Math.min(endless.maximumDecoys, 2 + challengeTier);
  }

  const paceLevel = gridDimension === 1
    ? 0
    : phaseId === "warmup" || phaseId === "color-patience"
      ? 1
      : phaseId === "gentle-ramp"
        ? 2
        : phaseId === "rare-decoys"
          ? 3
          : phaseId === "four-by-four-reset"
            ? 4
            : Math.min(11, 5 + challengeTier);

  return Object.freeze({
    gridDimension,
    phaseId,
    phaseName,
    responseWindowMs,
    spawnDelayRangeMs,
    decoySpawnDelayRangeMs,
    maximumActiveDecoys,
    challengeTier,
    paceLevel
  });
}

export function scoreReaction(reactionMs, responseWindowMs, config = GAME_CONFIG) {
  const remainingRatio = clamp(1 - reactionMs / responseWindowMs, 0, 1);
  const range = config.scoreCeiling - config.scoreFloor;
  return Math.round(config.scoreFloor + range * remainingRatio ** 2);
}

export function classifyReaction(reactionMs) {
  const displayedMs = Math.round(Math.max(0, reactionMs));
  const rating = SPEED_RATINGS.find(({ maximumExclusiveMs }) => displayedMs < maximumExclusiveMs);
  return Object.freeze({
    id: rating.id,
    label: rating.label,
    displayedMs
  });
}

function emptySpeedRatingCounts() {
  return {
    [SPEED_RATING_IDS.GODLIKE]: 0,
    [SPEED_RATING_IDS.PERFECT]: 0,
    [SPEED_RATING_IDS.GREAT]: 0,
    [SPEED_RATING_IDS.GOOD]: 0
  };
}

function emptyMultiplierHitCounts(maximumMultiplier) {
  return Object.fromEntries(
    Array.from({ length: maximumMultiplier }, (_, index) => [index + 1, 0])
  );
}

export class GameEngine {
  #runProofEvents = [];
  #runProofFinished = false;
  #runProofEnabled = false;
  #proofClockFloor = 0;

  constructor({ config = GAME_CONFIG, colors = COLORS, random = Math.random } = {}) {
    if (colors.length < 2) {
      throw new Error("PimPoPom needs at least two colors.");
    }

    this.config = config;
    this.colors = colors;
    this.random = random;
    this.reset();
  }

  reset() {
    this.state = GAME_STATES.IDLE;
    this.mode = GAME_MODES.NORMAL;
    this.points = 0;
    this.lives = this.config.startingLives;
    this.hits = 0;
    this.misses = 0;
    this.dodges = 0;
    this.reactionTotalMs = 0;
    this.fastestReactionMs = null;
    this.speedRatings = emptySpeedRatingCounts();
    this.multiplier = 1;
    this.maximumMultiplierUsed = 1;
    this.streakProgress = 0;
    this.reactionBasePoints = 0;
    this.multiplierBonusPoints = 0;
    this.multiplierHitCounts = emptyMultiplierHitCounts(
      this.config.streak.maximumMultiplier
    );
    this.multiplierBasePoints = emptyMultiplierHitCounts(
      this.config.streak.maximumMultiplier
    );
    this.startedAt = null;
    this.endedAt = null;
    this.endReason = null;
    this.playerColorIndex = 0;
    this.roundDifficulty = null;
    this.roundKind = null;
    this.activeAt = null;
    this.targetIndex = null;
    this.activeDecoys = [];
    this.recentlyExpiredDecoyIndexes = new Set();
    this.nextDecoyId = 1;
    this.challengeStartHits = null;
    this.recoveryUntil = null;
    this.proofTargetAt = null;
    this.#runProofEvents = [];
    this.#runProofFinished = false;
    this.#runProofEnabled = false;
    this.#proofClockFloor = 0;
    this.zenTargetDelayMs = this.config.zen.initialTargetDelayMs;
  }

  start(now = 0, mode = GAME_MODES.NORMAL) {
    if (!Object.values(GAME_MODES).includes(mode)) {
      throw new Error(`Unknown game mode: ${mode}`);
    }

    this.reset();
    this.state = GAME_STATES.WAITING;
    this.mode = mode;
    this.#runProofEnabled = mode === GAME_MODES.NORMAL;
    this.startedAt = now;
    this.playerColorIndex = randomInteger(this.random, this.colors.length);
    return this.getSnapshot(now);
  }

  getElapsedMs(now) {
    if (this.startedAt === null) return 0;
    return Math.max(0, (this.endedAt ?? now) - this.startedAt);
  }

  getRunProofEvents() {
    return this.#runProofEvents.map((event) => [...event]);
  }

  getRemainingMs() {
    return null;
  }

  getChallengeHits() {
    return this.challengeStartHits === null ? 0 : Math.max(0, this.hits - this.challengeStartHits);
  }

  getRecoveryRemainingMs(now) {
    if (this.recoveryUntil === null) return 0;
    return Math.max(0, this.recoveryUntil - now);
  }

  getNextDelayMs(now) {
    if (this.mode === GAME_MODES.ZEN) {
      return this.zenTargetDelayMs;
    }

    const recoveryRemainingMs = this.getRecoveryRemainingMs(now);
    const difficultyAt = now + recoveryRemainingMs;
    const difficulty = resolveDifficulty(
      this.hits,
      this.getElapsedMs(difficultyAt),
      this.getChallengeHits(),
      this.config
    );
    const [minimum, maximum] = difficulty.spawnDelayRangeMs;
    const quietDelayMs = Math.round(minimum + this.random() * (maximum - minimum));
    return Math.ceil(recoveryRemainingMs) + quietDelayMs;
  }

  getNextDecoyDelayMs(now) {
    if (this.state === GAME_STATES.IDLE || this.state === GAME_STATES.GAME_OVER) {
      return null;
    }
    if (this.mode === GAME_MODES.ZEN) {
      return null;
    }

    const recoveryRemainingMs = this.getRecoveryRemainingMs(now);
    const difficultyAt = now + recoveryRemainingMs;
    const elapsedMs = this.#proofElapsed(difficultyAt);
    if (elapsedMs < this.config.phases.colorPatienceStartsAtMs) {
      return Math.ceil(
        recoveryRemainingMs + this.config.phases.colorPatienceStartsAtMs - elapsedMs
      );
    }

    const difficulty = this.#currentDifficulty(difficultyAt);
    if (difficulty.gridDimension < 2 || difficulty.decoySpawnDelayRangeMs === null) {
      return Math.ceil(recoveryRemainingMs) + this.config.decoys.retryDelayMs;
    }

    const [minimum, maximum] = difficulty.decoySpawnDelayRangeMs;
    const quietDelayMs = Math.round(minimum + this.random() * (maximum - minimum));
    return Math.ceil(recoveryRemainingMs) + quietDelayMs;
  }

  getNextDecoyExpiryAt() {
    if (this.activeDecoys.length === 0) return null;
    return Math.min(...this.activeDecoys.map(({ expiresAt }) => expiresAt));
  }

  activateRound(now) {
    if (this.state !== GAME_STATES.WAITING) {
      return Object.freeze({ type: "ignored", reason: "not-waiting", snapshot: this.getSnapshot(now) });
    }
    const recoveryGuard = this.#recoveryGuard(now);
    if (recoveryGuard) return recoveryGuard;

    const settled = this.#settleExpiredDecoys(now);

    const elapsedMs = this.#proofElapsed(now);
    if (
      elapsedMs >= this.config.phases.fourByFourChallengeStartsAtMs &&
      this.challengeStartHits === null
    ) {
      this.challengeStartHits = this.hits;
    }

    const difficulty = resolveDifficulty(
      this.hits,
      elapsedMs,
      this.getChallengeHits(),
      this.config
    );
    const cellCount = difficulty.gridDimension ** 2;
    const occupiedIndexes = new Set([
      ...this.recentlyExpiredDecoyIndexes,
      ...this.activeDecoys.map(({ cellIndex }) => cellIndex)
    ]);
    let availableIndexes = Array.from({ length: cellCount }, (_, index) => index).filter(
      (index) => !occupiedIndexes.has(index)
    );
    if (availableIndexes.length === 0) {
      const activeDecoyIndexes = new Set(
        this.activeDecoys.map(({ cellIndex }) => cellIndex)
      );
      availableIndexes = Array.from({ length: cellCount }, (_, index) => index).filter(
        (index) => !activeDecoyIndexes.has(index)
      );
    }
    if (availableIndexes.length === 0) {
      return Object.freeze({
        type: "ignored",
        reason: "no-target-cell",
        dodgesAwarded: settled.count,
        dodgePointsAwarded: settled.pointsAwarded,
        snapshot: this.getSnapshot(now)
      });
    }

    const targetIndex = availableIndexes[randomInteger(this.random, availableIndexes.length)];
    this.recentlyExpiredDecoyIndexes.clear();

    this.state = GAME_STATES.ACTIVE;
    this.roundDifficulty = difficulty;
    this.roundKind = ROUND_KINDS.TARGET;
    this.activeAt = now;
    this.targetIndex = targetIndex;
    this.proofTargetAt = this.#proofElapsed(now);
    this.#recordProofEvent([0, this.proofTargetAt, targetIndex]);

    return Object.freeze({
      type: "round-active",
      dodgesAwarded: settled.count,
      dodgePointsAwarded: settled.pointsAwarded,
      snapshot: this.getSnapshot(now)
    });
  }

  activateDecoy(now) {
    if (this.state === GAME_STATES.IDLE || this.state === GAME_STATES.GAME_OVER) {
      return Object.freeze({ type: "ignored", reason: "not-running", snapshot: this.getSnapshot(now) });
    }
    if (this.mode === GAME_MODES.ZEN) {
      return Object.freeze({
        type: "ignored",
        reason: "decoys-disabled",
        dodgesAwarded: 0,
        dodgePointsAwarded: 0,
        snapshot: this.getSnapshot(now)
      });
    }
    const recoveryGuard = this.#recoveryGuard(now);
    if (recoveryGuard) return recoveryGuard;

    const settled = this.#settleExpiredDecoys(now);
    const difficulty = this.#currentDifficulty(now);
    const cellCount = difficulty.gridDimension ** 2;
    const maximumActiveDecoys = Math.min(
      difficulty.maximumActiveDecoys,
      Math.max(0, cellCount - 1)
    );

    if (difficulty.decoySpawnDelayRangeMs === null || maximumActiveDecoys === 0) {
      this.#recordProofEvent([6, this.#proofElapsed(now)]);
      return Object.freeze({
        type: "ignored",
        reason: "decoys-disabled",
        dodgesAwarded: settled.count,
        dodgePointsAwarded: settled.pointsAwarded,
        snapshot: this.getSnapshot(now)
      });
    }

    if (this.activeDecoys.length >= maximumActiveDecoys) {
      this.#recordProofEvent([6, this.#proofElapsed(now)]);
      return Object.freeze({
        type: "ignored",
        reason: "decoy-capacity",
        dodgesAwarded: settled.count,
        dodgePointsAwarded: settled.pointsAwarded,
        snapshot: this.getSnapshot(now)
      });
    }

    const occupiedIndexes = new Set(this.activeDecoys.map(({ cellIndex }) => cellIndex));
    if (this.targetIndex !== null) occupiedIndexes.add(this.targetIndex);
    const availableIndexes = Array.from({ length: cellCount }, (_, index) => index).filter(
      (index) => !occupiedIndexes.has(index)
    );
    if (availableIndexes.length === 0) {
      this.#recordProofEvent([6, this.#proofElapsed(now)]);
      return Object.freeze({
        type: "ignored",
        reason: "no-decoy-cell",
        dodgesAwarded: settled.count,
        dodgePointsAwarded: settled.pointsAwarded,
        snapshot: this.getSnapshot(now)
      });
    }

    const [configuredMinimum, configuredMaximum] = this.config.decoys.lifetimeRangeMs;
    const maximum = Math.min(
      MAX_DECOY_LIFETIME_MS,
      this.config.decoys.maximumLifetimeMs,
      configuredMaximum
    );
    const minimum = Math.min(maximum, Math.max(0, configuredMinimum));
    const lifetimeMs = Math.round(minimum + this.random() * (maximum - minimum));
    const decoy = {
      id: this.nextDecoyId,
      cellIndex: availableIndexes[randomInteger(this.random, availableIndexes.length)],
      colorIndex: this.#differentColorIndex(),
      visibleAt: now,
      expiresAt: now + lifetimeMs
    };
    this.nextDecoyId += 1;
    this.activeDecoys.push(decoy);
    this.#recordProofEvent([
      3,
      this.#proofElapsed(now),
      decoy.id,
      decoy.cellIndex,
      lifetimeMs
    ]);

    return Object.freeze({
      type: "decoy-active",
      decoy: Object.freeze({ ...decoy }),
      lifetimeMs,
      dodgesAwarded: settled.count,
      dodgePointsAwarded: settled.pointsAwarded,
      snapshot: this.getSnapshot(now)
    });
  }

  expireDecoys(now) {
    if (this.state === GAME_STATES.IDLE || this.state === GAME_STATES.GAME_OVER) {
      return Object.freeze({ type: "ignored", reason: "not-running", snapshot: this.getSnapshot(now) });
    }

    const settled = this.#settleExpiredDecoys(now);
    if (settled.count === 0) {
      return Object.freeze({
        type: "ignored",
        reason: "not-expired",
        nextExpiryAt: this.getNextDecoyExpiryAt(),
        snapshot: this.getSnapshot(now)
      });
    }

    return Object.freeze({
      type: "decoys-dodged",
      decoyIds: Object.freeze([...settled.decoyIds]),
      dodgesAwarded: settled.count,
      pointsAwarded: settled.pointsAwarded,
      snapshot: this.getSnapshot(now)
    });
  }

  tap(cellIndex, now, resolvedAt = now) {
    // A decoy earns a dodge only when its own expiry transition removes it.
    // If a still-present decoy is cleared by input, it was not visibly dodged.
    const settled = { count: 0, pointsAwarded: 0, decoyIds: [] };

    if (this.state === GAME_STATES.WAITING) {
      return this.#miss("empty", now, null, settled, resolvedAt, cellIndex);
    }

    if (this.state !== GAME_STATES.ACTIVE) {
      return Object.freeze({ type: "ignored", reason: "not-active", snapshot: this.getSnapshot(now) });
    }

    const reactionMs = Math.max(0, now - this.activeAt);
    if (
      this.mode !== GAME_MODES.ZEN &&
      reactionMs >= this.roundDifficulty.responseWindowMs
    ) {
      return this.#miss("late", now, reactionMs, settled, resolvedAt, cellIndex);
    }

    if (cellIndex !== this.targetIndex) {
      return this.#miss("wrong", now, reactionMs, settled, resolvedAt, cellIndex);
    }

    const speedRating = classifyReaction(reactionMs);
    const scoredReactionMs = speedRating.displayedMs;
    const proofInputAt = (this.proofTargetAt ?? this.#proofElapsed(this.activeAt)) + scoredReactionMs;
    this.#recordProofEvent([
      1,
      proofInputAt,
      Math.max(proofInputAt, this.#proofElapsed(resolvedAt)),
      cellIndex
    ]);
    const multiplierUsed = this.multiplier;
    this.maximumMultiplierUsed = Math.max(this.maximumMultiplierUsed, multiplierUsed);
    const basePointsAwarded = scoreReaction(
      scoredReactionMs,
      this.roundDifficulty.responseWindowMs,
      this.config
    );
    const pointsAwarded = basePointsAwarded * multiplierUsed;
    this.points += pointsAwarded;
    this.reactionBasePoints += basePointsAwarded;
    this.multiplierBonusPoints += pointsAwarded - basePointsAwarded;
    this.multiplierHitCounts[multiplierUsed] += 1;
    this.multiplierBasePoints[multiplierUsed] += basePointsAwarded;
    this.hits += 1;
    this.reactionTotalMs += scoredReactionMs;
    this.fastestReactionMs =
      this.fastestReactionMs === null
        ? scoredReactionMs
        : Math.min(this.fastestReactionMs, scoredReactionMs);
    this.speedRatings[speedRating.id] += 1;
    const multiplierBeforeAdvance = this.multiplier;
    const streakSteps = this.config.streak.ratingSteps[speedRating.id] ?? 0;
    if (streakSteps > 0) this.#advanceStreak(streakSteps);
    const multiplierRaised = this.multiplier > multiplierBeforeAdvance;
    if (this.mode === GAME_MODES.ZEN) {
      const adaptation = this.config.zen.cadenceAdaptation;
      this.zenTargetDelayMs += adaptation * (reactionMs - this.zenTargetDelayMs);
    }
    this.#finishRound();

    const shouldChangeColor =
      this.#proofElapsed(now) >= this.config.phases.colorPatienceStartsAtMs;
    if (shouldChangeColor) {
      this.playerColorIndex = this.#differentColorIndex();
    }

    return Object.freeze({
      type: "hit",
      basePointsAwarded,
      multiplierUsed,
      multiplierAfter: this.multiplier,
      multiplierRaised,
      pointsAwarded,
      reactionMs,
      displayedReactionMs: speedRating.displayedMs,
      speedRating,
      dodgesAwarded: settled.count,
      dodgePointsAwarded: settled.pointsAwarded,
      colorChanged: shouldChangeColor,
      snapshot: this.getSnapshot(now)
    });
  }

  expireRound(now) {
    if (this.state !== GAME_STATES.ACTIVE) {
      return Object.freeze({ type: "ignored", reason: "not-active", snapshot: this.getSnapshot(now) });
    }

    if (this.mode === GAME_MODES.ZEN) {
      return Object.freeze({
        type: "ignored",
        reason: "target-does-not-expire",
        snapshot: this.getSnapshot(now)
      });
    }

    // Target expiry clears every visible decoy as part of the failed round.
    const settled = { count: 0, pointsAwarded: 0, decoyIds: [] };
    const reactionMs = Math.max(0, now - this.activeAt);
    if (reactionMs < this.roundDifficulty.responseWindowMs) {
      return Object.freeze({
        type: "ignored",
        reason: "not-expired",
        remainingMs: this.roundDifficulty.responseWindowMs - reactionMs,
        dodgesAwarded: settled.count,
        dodgePointsAwarded: settled.pointsAwarded,
        snapshot: this.getSnapshot(now)
      });
    }

    return this.#miss("late", now, reactionMs, settled, now, -1);
  }

  finishTimedRun(now) {
    return Object.freeze({ type: "ignored", reason: "not-timed", snapshot: this.getSnapshot(now) });
  }

  endZenRun(now) {
    if (this.state === GAME_STATES.IDLE) {
      return Object.freeze({
        type: "ignored",
        reason: "not-running",
        snapshot: this.getSnapshot(now)
      });
    }
    if (this.state === GAME_STATES.GAME_OVER) {
      return Object.freeze({
        type: "ignored",
        reason: "already-ended",
        snapshot: this.getSnapshot(now)
      });
    }
    if (this.mode !== GAME_MODES.ZEN) {
      return Object.freeze({
        type: "ignored",
        reason: "not-zen",
        snapshot: this.getSnapshot(now)
      });
    }

    this.state = GAME_STATES.GAME_OVER;
    this.endedAt = Math.max(this.startedAt ?? now, now);
    this.endReason = "manual";
    this.activeDecoys = [];
    this.recentlyExpiredDecoyIndexes.clear();
    this.targetIndex = null;
    this.activeAt = null;
    this.roundKind = null;
    this.roundDifficulty = null;
    this.recoveryUntil = null;
    this.proofTargetAt = null;

    return Object.freeze({
      type: "zen-ended",
      reason: "manual",
      snapshot: this.getSnapshot(now)
    });
  }

  isRunComplete() {
    if (this.state !== GAME_STATES.GAME_OVER) return false;
    return (
      (this.mode === GAME_MODES.NORMAL && this.lives === 0 && this.endReason === "lives") ||
      (this.mode === GAME_MODES.ZEN && this.endReason === "manual")
    );
  }

  getSnapshot(now = this.startedAt ?? 0) {
    const snapshotAt = this.endedAt ?? now;
    const elapsedMs = this.getElapsedMs(snapshotAt);
    const difficulty = this.#currentDifficulty(snapshotAt);
    const expectedCellCount = difficulty.gridDimension ** 2;
    const cells = Array.from({ length: expectedCellCount }, () => ({ ...EMPTY_CELL }));
    const visibleDecoys = this.activeDecoys.filter(
      ({ cellIndex, expiresAt }) => cellIndex < expectedCellCount && expiresAt > snapshotAt
    );
    for (const decoy of visibleDecoys) {
      cells[decoy.cellIndex] = { kind: "decoy", colorIndex: decoy.colorIndex };
    }
    if (this.state === GAME_STATES.ACTIVE && this.targetIndex !== null) {
      cells[this.targetIndex] = { kind: "target", colorIndex: this.playerColorIndex };
    }
    const reactionProgress =
      this.mode !== GAME_MODES.ZEN &&
      this.state === GAME_STATES.ACTIVE &&
      this.activeAt !== null
        ? clamp(1 - (snapshotAt - this.activeAt) / difficulty.responseWindowMs, 0, 1)
        : null;

    return Object.freeze({
      state: this.state,
      mode: this.mode,
      points: this.points,
      lives: this.lives,
      hits: this.hits,
      misses: this.misses,
      dodges: this.dodges,
      fastestReactionMs: this.fastestReactionMs,
      averageReactionMs: this.hits > 0 ? this.reactionTotalMs / this.hits : null,
      speedRatings: Object.freeze({ ...this.speedRatings }),
      multiplier: this.multiplier,
      streakProgress: this.streakProgress,
      streakTarget: this.config.streak.stepsPerMultiplier,
      maximumMultiplier: this.config.streak.maximumMultiplier,
      maximumMultiplierUsed: this.maximumMultiplierUsed,
      reactionBasePoints: this.reactionBasePoints,
      multiplierBonusPoints: this.multiplierBonusPoints,
      multiplierHitCounts: Object.freeze({ ...this.multiplierHitCounts }),
      multiplierBasePoints: Object.freeze({ ...this.multiplierBasePoints }),
      reactionProgress,
      nextTargetDelayMs: this.mode === GAME_MODES.ZEN ? this.zenTargetDelayMs : null,
      recoveryRemainingMs: this.getRecoveryRemainingMs(snapshotAt),
      elapsedMs,
      remainingMs: this.getRemainingMs(snapshotAt),
      endReason: this.endReason,
      playerColorIndex: this.playerColorIndex,
      playerColor: this.colors[this.playerColorIndex],
      targetIndex: this.targetIndex,
      activeDecoys: Object.freeze(visibleDecoys.map((decoy) => Object.freeze({ ...decoy }))),
      nextDecoyExpiryAt: this.getNextDecoyExpiryAt(),
      roundKind: this.roundKind,
      difficulty,
      cells
    });
  }

  #differentColorIndex() {
    const offset = 1 + randomInteger(this.random, this.colors.length - 1);
    return (this.playerColorIndex + offset) % this.colors.length;
  }

  #advanceStreak(steps) {
    if (this.multiplier >= this.config.streak.maximumMultiplier) {
      this.streakProgress = this.config.streak.stepsPerMultiplier;
      return;
    }

    this.streakProgress += steps;
    while (
      this.streakProgress >= this.config.streak.stepsPerMultiplier &&
      this.multiplier < this.config.streak.maximumMultiplier
    ) {
      this.streakProgress -= this.config.streak.stepsPerMultiplier;
      this.multiplier += 1;
    }
    if (this.multiplier >= this.config.streak.maximumMultiplier) {
      this.streakProgress = this.config.streak.stepsPerMultiplier;
    }
  }

  #resetStreak() {
    this.multiplier = 1;
    this.streakProgress = 0;
  }

  #finishRound() {
    this.state = GAME_STATES.WAITING;
    this.activeDecoys = [];
    this.targetIndex = null;
    this.activeAt = null;
    this.roundKind = null;
    this.roundDifficulty = null;
    this.recoveryUntil = null;
    this.proofTargetAt = null;
  }

  #miss(
    reason,
    now,
    reactionMs,
    settled = { count: 0, pointsAwarded: 0 },
    resolvedAt = now,
    cellIndex = -1
  ) {
    const reasonCode = { empty: 0, wrong: 1, late: 2 }[reason];
    const proofInputAt = reactionMs === null
      ? this.#proofElapsed(now)
      : (this.proofTargetAt ?? this.#proofElapsed(now - reactionMs)) + Math.round(reactionMs);
    const proofHandledAt = Math.max(proofInputAt, this.#proofElapsed(resolvedAt));
    this.#recordProofEvent([
      2,
      proofInputAt,
      proofHandledAt,
      reasonCode,
      cellIndex
    ]);
    this.#resetStreak();
    const lifeLost = this.mode === GAME_MODES.NORMAL;
    if (lifeLost) {
      this.lives = Math.max(0, this.lives - 1);
    }
    this.misses += 1;

    const targetRetained =
      this.mode === GAME_MODES.ZEN &&
      this.state === GAME_STATES.ACTIVE &&
      this.targetIndex !== null;

    if (targetRetained) {
      this.activeDecoys = [];
      return Object.freeze({
        type: "miss",
        reason,
        reactionMs,
        lifeLost,
        targetRetained: true,
        dodgesAwarded: settled.count,
        dodgePointsAwarded: settled.pointsAwarded,
        snapshot: this.getSnapshot(Math.max(now, resolvedAt))
      });
    }

    if (lifeLost && this.lives === 0) {
      this.state = GAME_STATES.GAME_OVER;
      // Use the same integer logical instant recorded in the proof. Adding two
      // independently rounded browser intervals can differ by one millisecond,
      // which would otherwise make the result UI disagree with PHP replay.
      this.endedAt = (this.startedAt ?? 0) + proofInputAt;
      this.endReason = "lives";
      this.activeDecoys = [];
      this.targetIndex = null;
      this.activeAt = null;
      this.roundKind = null;
      this.roundDifficulty = null;
      this.recoveryUntil = null;
      this.proofTargetAt = null;
      this.#recordFinishElapsed(proofInputAt, proofHandledAt);
    } else {
      this.#finishRound();
      if (lifeLost) {
        this.recoveryUntil = Math.max(now, resolvedAt) + this.config.lifeLossRecoveryMs;
      }
    }

    return Object.freeze({
      type: "miss",
      reason,
      reactionMs,
      lifeLost,
      targetRetained: false,
      dodgesAwarded: settled.count,
      dodgePointsAwarded: settled.pointsAwarded,
      snapshot: this.getSnapshot(Math.max(now, resolvedAt))
    });
  }

  #currentDifficulty(now) {
    const difficulty = this.state === GAME_STATES.ACTIVE && this.roundDifficulty
      ? this.roundDifficulty
      : resolveDifficulty(
          this.hits,
          this.#proofElapsed(now),
          this.getChallengeHits(),
          this.config
        );
    if (this.mode !== GAME_MODES.ZEN) return difficulty;
    return Object.freeze({
      ...difficulty,
      decoySpawnDelayRangeMs: null,
      maximumActiveDecoys: 0
    });
  }

  #proofElapsed(now) {
    if (this.startedAt === null) return 0;
    return Math.max(
      this.#proofClockFloor,
      Math.round(Math.max(0, now - this.startedAt))
    );
  }

  #recordProofEvent(event) {
    if (!this.#runProofEnabled) return;
    this.#runProofEvents.push(Object.freeze([...event]));
    const clockIndex = [1, 2, 5].includes(event[0]) ? 2 : 1;
    this.#proofClockFloor = Math.max(this.#proofClockFloor, event[clockIndex] ?? 0);
  }

  #recordFinish(logicalAt, handledAt) {
    this.#recordFinishElapsed(this.#proofElapsed(logicalAt), this.#proofElapsed(handledAt));
  }

  #recordFinishElapsed(logicalElapsed, handledElapsed) {
    if (this.#runProofFinished) return;
    this.#recordProofEvent([
      5,
      logicalElapsed,
      Math.max(logicalElapsed, handledElapsed)
    ]);
    this.#runProofFinished = true;
  }

  #recoveryGuard(now) {
    const remainingMs = this.getRecoveryRemainingMs(now);
    if (remainingMs <= 0) {
      this.recoveryUntil = null;
      return null;
    }
    return Object.freeze({
      type: "ignored",
      reason: "recovering",
      remainingMs,
      snapshot: this.getSnapshot(now)
    });
  }

  #settleExpiredDecoys(now) {
    if (
      this.state === GAME_STATES.IDLE ||
      this.state === GAME_STATES.GAME_OVER ||
      this.activeDecoys.length === 0
    ) {
      return { count: 0, pointsAwarded: 0, decoyIds: [] };
    }

    const expired = [];
    const retained = [];
    for (const decoy of this.activeDecoys) {
      if (decoy.expiresAt <= now) {
        expired.push(decoy);
      } else {
        retained.push(decoy);
      }
    }

    if (expired.length === 0) {
      return { count: 0, pointsAwarded: 0, decoyIds: [] };
    }

    this.activeDecoys = retained;
    for (const decoy of expired) {
      this.recentlyExpiredDecoyIndexes.add(decoy.cellIndex);
    }
    const pointsAwarded = this.mode === GAME_MODES.ZEN
      ? 0
      : expired.length * this.config.dodgePoints;
    this.points += pointsAwarded;
    this.dodges += expired.length;
    const decoyIds = expired.map(({ id }) => id);
    this.#recordProofEvent([4, this.#proofElapsed(now), ...decoyIds]);
    return {
      count: expired.length,
      pointsAwarded,
      decoyIds
    };
  }
}
