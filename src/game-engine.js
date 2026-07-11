import { COLORS, GAME_CONFIG, GAME_MODES } from "./config.js?v=20260711-4";

export const GAME_STATES = Object.freeze({
  IDLE: "idle",
  WAITING: "waiting",
  ACTIVE: "active",
  GAME_OVER: "game-over"
});

export const ROUND_KINDS = Object.freeze({
  TARGET: "target",
  WRONG_ONLY: "wrong-only",
  MIXED: "mixed"
});

const EMPTY_CELL = Object.freeze({ kind: "idle", colorIndex: null });

function clamp(value, minimum, maximum) {
  return Math.min(maximum, Math.max(minimum, value));
}

function randomInteger(random, maximumExclusive) {
  return Math.floor(clamp(random(), 0, 0.999999999) * maximumExclusive);
}

function shuffled(values, random) {
  const result = [...values];
  for (let index = result.length - 1; index > 0; index -= 1) {
    const otherIndex = randomInteger(random, index + 1);
    [result[index], result[otherIndex]] = [result[otherIndex], result[index]];
  }
  return result;
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
  const { phases, responseWindowsMs, chances, spawnDelayRangesMs } = config;
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
  let wrongOnlyChance = 0;
  let mixedDecoyChance = 0;
  let decoyCount = 0;
  let challengeTier = 0;

  if (elapsedMs >= phases.colorPatienceStartsAtMs) {
    phaseId = "color-patience";
    phaseName = "Color patience";
    spawnDelayRangeMs = spawnDelayRangesMs.colorPatience;
    wrongOnlyChance = chances.soloWrongColor;
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
  }

  if (elapsedMs >= phases.rareDecoysStartAtMs) {
    phaseId = "rare-decoys";
    phaseName = "Rare decoys";
    responseWindowMs = responseWindowsMs.gentleMinimum;
    spawnDelayRangeMs = spawnDelayRangesMs.rareDecoys;
    wrongOnlyChance = chances.rarePhaseWrongColor;
    mixedDecoyChance = chances.rarePhaseMixedDecoy;
    decoyCount = 1;
  }

  if (elapsedMs >= phases.fourByFourStartsAtMs) {
    phaseId = "four-by-four-reset";
    phaseName = "16-cell reset";
    responseWindowMs = responseWindowsMs.fourByFourStart;
    spawnDelayRangeMs = spawnDelayRangesMs.fourByFourReset;
    wrongOnlyChance = chances.fourByFourWrongColor;
    mixedDecoyChance = 0;
    decoyCount = 0;
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
    wrongOnlyChance = Math.max(
      endless.minimumWrongColorChance,
      chances.fourByFourChallengeWrongColor -
        challengeTier * endless.wrongColorDecreasePerTier
    );
    mixedDecoyChance = Math.min(
      endless.maximumMixedDecoyChance,
      chances.fourByFourChallengeMixedDecoy +
        challengeHits * endless.mixedChanceIncreasePerHit
    );
    decoyCount = Math.min(endless.maximumDecoys, 1 + challengeTier);
  }

  return Object.freeze({
    gridDimension,
    phaseId,
    phaseName,
    responseWindowMs,
    spawnDelayRangeMs,
    wrongOnlyChance,
    mixedDecoyChance,
    decoyCount,
    challengeTier
  });
}

export function scoreReaction(reactionMs, responseWindowMs, config = GAME_CONFIG) {
  const remainingRatio = clamp(1 - reactionMs / responseWindowMs, 0, 1);
  const range = config.scoreCeiling - config.scoreFloor;
  return Math.round(config.scoreFloor + range * remainingRatio ** 2);
}

export class GameEngine {
  constructor({ config = GAME_CONFIG, colors = COLORS, random = Math.random } = {}) {
    if (colors.length < 2) {
      throw new Error("SpeedyTapper needs at least two colors.");
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
    this.startedAt = null;
    this.endedAt = null;
    this.endReason = null;
    this.playerColorIndex = 0;
    this.roundDifficulty = null;
    this.roundKind = null;
    this.activeAt = null;
    this.targetIndex = null;
    this.cells = [];
    this.challengeStartHits = null;
  }

  start(now = 0, mode = GAME_MODES.NORMAL) {
    if (!Object.values(GAME_MODES).includes(mode)) {
      throw new Error(`Unknown game mode: ${mode}`);
    }

    this.reset();
    this.state = GAME_STATES.WAITING;
    this.mode = mode;
    this.startedAt = now;
    this.playerColorIndex = randomInteger(this.random, this.colors.length);
    return this.getSnapshot(now);
  }

  getElapsedMs(now) {
    if (this.startedAt === null) return 0;
    return Math.max(0, (this.endedAt ?? now) - this.startedAt);
  }

  getRemainingMs(now) {
    if (this.mode !== GAME_MODES.ZEN || this.startedAt === null) return null;
    return Math.max(0, this.config.zenDurationMs - this.getElapsedMs(now));
  }

  getChallengeHits() {
    return this.challengeStartHits === null ? 0 : Math.max(0, this.hits - this.challengeStartHits);
  }

  getNextDelayMs(now) {
    const difficulty = resolveDifficulty(
      this.hits,
      this.getElapsedMs(now),
      this.getChallengeHits(),
      this.config
    );
    const [minimum, maximum] = difficulty.spawnDelayRangeMs;
    return Math.round(minimum + this.random() * (maximum - minimum));
  }

  activateRound(now) {
    if (this.state !== GAME_STATES.WAITING) {
      return Object.freeze({ type: "ignored", reason: "not-waiting", snapshot: this.getSnapshot(now) });
    }

    const elapsedMs = this.getElapsedMs(now);
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
    const cells = Array.from({ length: cellCount }, () => ({ ...EMPTY_CELL }));
    let roundKind = ROUND_KINDS.TARGET;

    const roundKindRoll = this.random();
    if (difficulty.wrongOnlyChance > 0 && roundKindRoll < difficulty.wrongOnlyChance) {
      roundKind = ROUND_KINDS.WRONG_ONLY;
    } else if (
      difficulty.mixedDecoyChance > 0 &&
      roundKindRoll < difficulty.wrongOnlyChance + difficulty.mixedDecoyChance
    ) {
      roundKind = ROUND_KINDS.MIXED;
    }

    const litIndex = randomInteger(this.random, cellCount);
    let targetIndex = litIndex;

    if (roundKind === ROUND_KINDS.WRONG_ONLY) {
      targetIndex = null;
      cells[litIndex] = { kind: "wrong-only", colorIndex: this.#differentColorIndex() };
    } else {
      cells[targetIndex] = { kind: "target", colorIndex: this.playerColorIndex };
    }

    if (roundKind === ROUND_KINDS.MIXED) {
      const adjacent = shuffled(
        orthogonalNeighbors(targetIndex, difficulty.gridDimension),
        this.random
      );
      const fallback = shuffled(
        Array.from({ length: cellCount }, (_, index) => index).filter(
          (index) => index !== targetIndex && !adjacent.includes(index)
        ),
        this.random
      );
      const decoyIndexes = [...adjacent, ...fallback].slice(
        0,
        Math.min(difficulty.decoyCount, cellCount - 1)
      );
      for (const decoyIndex of decoyIndexes) {
        cells[decoyIndex] = { kind: "decoy", colorIndex: this.#differentColorIndex() };
      }
    }

    this.state = GAME_STATES.ACTIVE;
    this.roundDifficulty = difficulty;
    this.roundKind = roundKind;
    this.activeAt = now;
    this.targetIndex = targetIndex;
    this.cells = cells;

    return Object.freeze({ type: "round-active", snapshot: this.getSnapshot(now) });
  }

  tap(cellIndex, now) {
    if (this.state === GAME_STATES.WAITING) {
      return this.#miss("empty", now, null);
    }

    if (this.state !== GAME_STATES.ACTIVE) {
      return Object.freeze({ type: "ignored", reason: "not-active", snapshot: this.getSnapshot(now) });
    }

    const reactionMs = Math.max(0, now - this.activeAt);
    if (reactionMs >= this.roundDifficulty.responseWindowMs) {
      return this.#miss("late", now, reactionMs);
    }

    if (this.roundKind === ROUND_KINDS.WRONG_ONLY || cellIndex !== this.targetIndex) {
      return this.#miss("wrong", now, reactionMs);
    }

    const pointsAwarded = scoreReaction(
      reactionMs,
      this.roundDifficulty.responseWindowMs,
      this.config
    );
    this.points += pointsAwarded;
    this.hits += 1;
    this.reactionTotalMs += reactionMs;
    this.fastestReactionMs =
      this.fastestReactionMs === null
        ? reactionMs
        : Math.min(this.fastestReactionMs, reactionMs);
    this.#finishRound();

    const shouldChangeColor =
      this.getElapsedMs(now) >= this.config.phases.colorPatienceStartsAtMs;
    if (shouldChangeColor) {
      this.playerColorIndex = this.#differentColorIndex();
    }

    return Object.freeze({
      type: "hit",
      pointsAwarded,
      reactionMs,
      colorChanged: shouldChangeColor,
      snapshot: this.getSnapshot(now)
    });
  }

  expireRound(now) {
    if (this.state !== GAME_STATES.ACTIVE) {
      return Object.freeze({ type: "ignored", reason: "not-active", snapshot: this.getSnapshot(now) });
    }

    const reactionMs = Math.max(0, now - this.activeAt);
    if (reactionMs < this.roundDifficulty.responseWindowMs) {
      return Object.freeze({
        type: "ignored",
        reason: "not-expired",
        remainingMs: this.roundDifficulty.responseWindowMs - reactionMs,
        snapshot: this.getSnapshot(now)
      });
    }

    if (this.roundKind === ROUND_KINDS.WRONG_ONLY) {
      const pointsAwarded = this.config.dodgePoints;
      this.points += pointsAwarded;
      this.dodges += 1;
      this.#finishRound();
      return Object.freeze({
        type: "ignored-color",
        pointsAwarded,
        reactionMs,
        snapshot: this.getSnapshot(now)
      });
    }

    return this.#miss("late", now, reactionMs);
  }

  finishTimedRun(now) {
    if (this.mode !== GAME_MODES.ZEN) {
      return Object.freeze({ type: "ignored", reason: "not-timed", snapshot: this.getSnapshot(now) });
    }

    if (this.getElapsedMs(now) < this.config.zenDurationMs) {
      return Object.freeze({
        type: "ignored",
        reason: "time-remaining",
        remainingMs: this.getRemainingMs(now),
        snapshot: this.getSnapshot(now)
      });
    }

    this.state = GAME_STATES.GAME_OVER;
    this.endedAt = this.startedAt + this.config.zenDurationMs;
    this.endReason = "time";
    this.cells = [];
    this.targetIndex = null;
    this.activeAt = null;
    this.roundKind = null;
    this.roundDifficulty = null;
    return Object.freeze({ type: "time-up", snapshot: this.getSnapshot(now) });
  }

  isRunComplete() {
    if (this.state !== GAME_STATES.GAME_OVER) return false;
    if (this.mode === GAME_MODES.NORMAL) {
      return this.lives === 0 && this.endReason === "lives";
    }
    return this.getRemainingMs(this.endedAt ?? this.startedAt) === 0 && this.endReason === "time";
  }

  getSnapshot(now = this.startedAt ?? 0) {
    const elapsedMs = this.getElapsedMs(now);
    const difficulty =
      this.state === GAME_STATES.ACTIVE && this.roundDifficulty
        ? this.roundDifficulty
        : resolveDifficulty(
            this.hits,
            elapsedMs,
            this.getChallengeHits(),
            this.config
          );
    const expectedCellCount = difficulty.gridDimension ** 2;
    const cells =
      this.state === GAME_STATES.ACTIVE && this.cells.length === expectedCellCount
        ? this.cells.map((cell) => ({ ...cell }))
        : Array.from({ length: expectedCellCount }, () => ({ ...EMPTY_CELL }));
    const reactionProgress =
      this.state === GAME_STATES.ACTIVE && this.activeAt !== null
        ? clamp(1 - (now - this.activeAt) / difficulty.responseWindowMs, 0, 1)
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
      reactionProgress,
      elapsedMs,
      remainingMs: this.getRemainingMs(now),
      endReason: this.endReason,
      playerColorIndex: this.playerColorIndex,
      playerColor: this.colors[this.playerColorIndex],
      targetIndex: this.targetIndex,
      roundKind: this.roundKind,
      difficulty,
      cells
    });
  }

  #differentColorIndex() {
    const offset = 1 + randomInteger(this.random, this.colors.length - 1);
    return (this.playerColorIndex + offset) % this.colors.length;
  }

  #finishRound() {
    this.state = GAME_STATES.WAITING;
    this.cells = [];
    this.targetIndex = null;
    this.activeAt = null;
    this.roundKind = null;
    this.roundDifficulty = null;
  }

  #miss(reason, now, reactionMs) {
    const lifeLost = this.mode === GAME_MODES.NORMAL;
    if (lifeLost) {
      this.lives = Math.max(0, this.lives - 1);
    }
    this.misses += 1;

    if (lifeLost && this.lives === 0) {
      this.state = GAME_STATES.GAME_OVER;
      this.endedAt = now;
      this.endReason = "lives";
      this.cells = [];
      this.targetIndex = null;
      this.activeAt = null;
      this.roundKind = null;
      this.roundDifficulty = null;
    } else {
      this.#finishRound();
    }

    return Object.freeze({
      type: "miss",
      reason,
      reactionMs,
      lifeLost,
      snapshot: this.getSnapshot(now)
    });
  }
}
