import { COLORS, GAME_CONFIG } from "./config.js";

export const GAME_STATES = Object.freeze({
  IDLE: "idle",
  WAITING: "waiting",
  ACTIVE: "active",
  GAME_OVER: "game-over"
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

export function resolveDifficulty(hits, elapsedMs, config = GAME_CONFIG) {
  const gridDimension = config.gridThresholds.find((threshold) => hits >= threshold.minHits).dimension;
  const usesColorChoice = elapsedMs >= config.warmupDurationMs;
  let speedTier = 0;
  let responseWindowMs = config.warmupResponseWindowMs;
  let spawnDelayRangeMs = config.spawnDelayRangesMs.warmup;
  let decoyCount = 0;
  let phaseName = "Warm-up";

  if (usesColorChoice && gridDimension < 4) {
    responseWindowMs = config.colorResponseWindowMs;
    spawnDelayRangeMs = config.spawnDelayRangesMs.color;
    decoyCount = gridDimension > 1 ? 1 : 0;
    phaseName = "Color shift";
  }

  if (usesColorChoice && gridDimension >= 4) {
    speedTier = clamp(
      Math.floor((hits - config.rapidGridStartsAtHits) / config.hitsPerSpeedTier),
      0,
      config.responseWindowsMs.length - 1
    );
    responseWindowMs = config.responseWindowsMs[speedTier];
    spawnDelayRangeMs = config.spawnDelayRangesMs.rapid[speedTier];
    decoyCount = Math.min(config.maximumDecoys, 2 + speedTier);
    phaseName = ["Grid rush", "Faster", "Rapid fire", "Overdrive", "Limit test"][speedTier];
  }

  return Object.freeze({
    gridDimension,
    usesColorChoice,
    speedTier,
    responseWindowMs,
    spawnDelayRangeMs,
    decoyCount,
    phaseName
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
    this.points = 0;
    this.lives = this.config.startingLives;
    this.hits = 0;
    this.misses = 0;
    this.startedAt = null;
    this.playerColorIndex = 0;
    this.roundDifficulty = null;
    this.activeAt = null;
    this.targetIndex = null;
    this.cells = [];
  }

  start(now = 0) {
    this.reset();
    this.state = GAME_STATES.WAITING;
    this.startedAt = now;
    this.playerColorIndex = randomInteger(this.random, this.colors.length);
    return this.getSnapshot(now);
  }

  getElapsedMs(now) {
    if (this.startedAt === null) return 0;
    return Math.max(0, now - this.startedAt);
  }

  getNextDelayMs(now) {
    const difficulty = resolveDifficulty(this.hits, this.getElapsedMs(now), this.config);
    const [minimum, maximum] = difficulty.spawnDelayRangeMs;
    return Math.round(minimum + this.random() * (maximum - minimum));
  }

  activateRound(now) {
    if (this.state !== GAME_STATES.WAITING) {
      return Object.freeze({ type: "ignored", reason: "not-waiting", snapshot: this.getSnapshot(now) });
    }

    const difficulty = resolveDifficulty(this.hits, this.getElapsedMs(now), this.config);
    const cellCount = difficulty.gridDimension ** 2;
    const targetIndex = randomInteger(this.random, cellCount);
    const cells = Array.from({ length: cellCount }, () => ({ ...EMPTY_CELL }));
    cells[targetIndex] = { kind: "target", colorIndex: this.playerColorIndex };

    if (difficulty.decoyCount > 0) {
      const neighborCandidates = shuffled(
        orthogonalNeighbors(targetIndex, difficulty.gridDimension),
        this.random
      );
      const allOtherCandidates = shuffled(
        Array.from({ length: cellCount }, (_, index) => index).filter(
          (index) => index !== targetIndex && !neighborCandidates.includes(index)
        ),
        this.random
      );
      const decoyIndexes = [...neighborCandidates, ...allOtherCandidates].slice(0, difficulty.decoyCount);

      for (const decoyIndex of decoyIndexes) {
        cells[decoyIndex] = { kind: "decoy", colorIndex: this.#differentColorIndex() };
      }
    }

    this.state = GAME_STATES.ACTIVE;
    this.roundDifficulty = difficulty;
    this.activeAt = now;
    this.targetIndex = targetIndex;
    this.cells = cells;

    return Object.freeze({ type: "round-active", snapshot: this.getSnapshot(now) });
  }

  tap(cellIndex, now) {
    if (this.state !== GAME_STATES.ACTIVE) {
      return Object.freeze({ type: "ignored", reason: "not-active", snapshot: this.getSnapshot(now) });
    }

    const reactionMs = Math.max(0, now - this.activeAt);
    if (reactionMs >= this.roundDifficulty.responseWindowMs) {
      return this.#loseLife("late", now, reactionMs);
    }

    if (cellIndex !== this.targetIndex) {
      return this.#loseLife("wrong", now, reactionMs);
    }

    const pointsAwarded = scoreReaction(
      reactionMs,
      this.roundDifficulty.responseWindowMs,
      this.config
    );
    this.points += pointsAwarded;
    this.hits += 1;
    this.state = GAME_STATES.WAITING;
    this.cells = [];
    this.targetIndex = null;
    this.activeAt = null;
    this.roundDifficulty = null;

    const shouldChangeColor = this.getElapsedMs(now) >= this.config.warmupDurationMs;
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

    return this.#loseLife("late", now, reactionMs);
  }

  getSnapshot(now = this.startedAt ?? 0) {
    const elapsedMs = this.getElapsedMs(now);
    const difficulty =
      this.state === GAME_STATES.ACTIVE && this.roundDifficulty
        ? this.roundDifficulty
        : resolveDifficulty(this.hits, elapsedMs, this.config);
    const expectedCellCount = difficulty.gridDimension ** 2;
    const cells =
      this.state === GAME_STATES.ACTIVE && this.cells.length === expectedCellCount
        ? this.cells.map((cell) => ({ ...cell }))
        : Array.from({ length: expectedCellCount }, () => ({ ...EMPTY_CELL }));

    return Object.freeze({
      state: this.state,
      points: this.points,
      lives: this.lives,
      hits: this.hits,
      misses: this.misses,
      elapsedMs,
      playerColorIndex: this.playerColorIndex,
      playerColor: this.colors[this.playerColorIndex],
      targetIndex: this.targetIndex,
      difficulty,
      cells
    });
  }

  #differentColorIndex() {
    const offset = 1 + randomInteger(this.random, this.colors.length - 1);
    return (this.playerColorIndex + offset) % this.colors.length;
  }

  #loseLife(reason, now, reactionMs) {
    this.lives = Math.max(0, this.lives - 1);
    this.misses += 1;
    this.state = this.lives === 0 ? GAME_STATES.GAME_OVER : GAME_STATES.WAITING;
    this.cells = [];
    this.targetIndex = null;
    this.activeAt = null;
    this.roundDifficulty = null;

    return Object.freeze({
      type: "miss",
      reason,
      reactionMs,
      snapshot: this.getSnapshot(now)
    });
  }
}
