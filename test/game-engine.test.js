import assert from "node:assert/strict";
import test from "node:test";

import { GAME_CONFIG, GAME_MODES } from "../src/config.js";
import {
  GameEngine,
  GAME_STATES,
  ROUND_KINDS,
  orthogonalNeighbors,
  resolveDifficulty,
  scoreReaction
} from "../src/game-engine.js";

function makeEngine(random = () => 0.99) {
  return new GameEngine({ random });
}

function sequenceRandom(values, fallback = 0.99) {
  let index = 0;
  return () => values[index++] ?? fallback;
}

function hitRound(engine, activeAt, reactionMs = 50) {
  const active = engine.activateRound(activeAt);
  assert.equal(active.type, "round-active");
  assert.notEqual(active.snapshot.targetIndex, null);
  return engine.tap(active.snapshot.targetIndex, activeAt + reactionMs);
}

test("warm-up begins with one cell, one target, and a 1000 ms lifetime", () => {
  const engine = makeEngine();
  engine.start(0);
  const result = engine.activateRound(100);

  assert.equal(result.snapshot.difficulty.gridDimension, 1);
  assert.equal(result.snapshot.difficulty.responseWindowMs, 1_000);
  assert.equal(result.snapshot.roundKind, ROUND_KINDS.TARGET);
  assert.equal(result.snapshot.cells.filter((cell) => cell.kind === "target").length, 1);
});

test("the board grows to 2x2 after four taps but keeps the 1000 ms lifetime", () => {
  const engine = makeEngine();
  engine.start(0);

  for (let hit = 0; hit < 4; hit += 1) hitRound(engine, 100 + hit * 200);

  assert.equal(engine.getSnapshot(9_000).difficulty.gridDimension, 2);
  assert.equal(engine.getSnapshot(15_000).difficulty.responseWindowMs, 1_000);
});

test("a lone wrong color awards average points when dodged", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);

  const active = engine.activateRound(10_100);
  assert.equal(active.snapshot.roundKind, ROUND_KINDS.WRONG_ONLY);
  assert.equal(active.snapshot.targetIndex, null);
  assert.equal(active.snapshot.cells.filter((cell) => cell.kind === "wrong-only").length, 1);
  assert.equal(active.snapshot.difficulty.responseWindowMs, 1_000);

  const result = engine.expireRound(11_100);
  assert.equal(result.type, "ignored-color");
  assert.equal(result.pointsAwarded, GAME_CONFIG.dodgePoints);
  assert.equal(result.snapshot.points, GAME_CONFIG.dodgePoints);
  assert.equal(result.snapshot.lives, 3);
  assert.equal(result.snapshot.dodges, 1);
  assert.equal(result.snapshot.hits, 0);
  assert.equal(result.snapshot.fastestReactionMs, null);
  assert.equal(result.snapshot.averageReactionMs, null);
});

test("the player color changes after a correct post-warm-up tap", () => {
  const engine = makeEngine();
  engine.start(0);
  engine.hits = 4;
  const oldColor = engine.playerColorIndex;

  const active = engine.activateRound(10_100);
  const result = engine.tap(active.snapshot.targetIndex, 10_200);

  assert.equal(result.type, "hit");
  assert.notEqual(result.snapshot.playerColorIndex, oldColor);
});

test("the gentle phase moves gradually from 1000 ms to 750 ms", () => {
  assert.equal(resolveDifficulty(4, 20_000).responseWindowMs, 1_000);
  assert.equal(resolveDifficulty(4, 25_000).responseWindowMs, 875);
  assert.equal(resolveDifficulty(4, 29_000).responseWindowMs, 775);
  assert.equal(resolveDifficulty(4, 30_000).responseWindowMs, 750);
});

test("a rare mixed round has only one adjacent decoy", () => {
  const random = sequenceRandom([0.2, 0.3, 0.5]);
  const engine = makeEngine(random);
  engine.start(0);
  engine.hits = 4;

  const active = engine.activateRound(35_000);
  const target = active.snapshot.targetIndex;
  const decoyIndexes = active.snapshot.cells
    .map((cell, index) => (cell.kind === "decoy" ? index : null))
    .filter((index) => index !== null);

  assert.equal(active.snapshot.roundKind, ROUND_KINDS.MIXED);
  assert.equal(decoyIndexes.length, 1);
  assert.equal(orthogonalNeighbors(target, 2).includes(decoyIndexes[0]), true);
});

test("the switch to 16 cells resets lifetime to 1000 ms and removes mixed decoys", () => {
  const difficulty = resolveDifficulty(20, 40_000);
  assert.equal(difficulty.gridDimension, 4);
  assert.equal(difficulty.phaseId, "four-by-four-reset");
  assert.equal(difficulty.responseWindowMs, 1_000);
  assert.equal(difficulty.mixedDecoyChance, 0);
});

test("configured round chances are absolute rather than reduced by a second random roll", () => {
  const activateWithRoll = (roundKindRoll) => {
    const engine = makeEngine(sequenceRandom([0.2, roundKindRoll, 0.5]));
    engine.start(0);
    engine.hits = 4;
    return engine.activateRound(35_000).snapshot.roundKind;
  };

  assert.equal(activateWithRoll(0.1), ROUND_KINDS.WRONG_ONLY);
  assert.equal(activateWithRoll(0.3), ROUND_KINDS.MIXED);
  assert.equal(activateWithRoll(0.9), ROUND_KINDS.TARGET);
});

test("the 16-cell challenge decreases lifetime by only 10 ms per successful tap", () => {
  assert.equal(resolveDifficulty(20, 50_000, 0).responseWindowMs, 1_000);
  assert.equal(resolveDifficulty(21, 50_000, 1).responseWindowMs, 990);
  assert.equal(resolveDifficulty(30, 55_000, 10).responseWindowMs, 900);
  assert.equal(resolveDifficulty(99, 90_000, 79).responseWindowMs, 210);
  assert.equal(resolveDifficulty(100, 90_000, 80).responseWindowMs, 200);
  assert.equal(resolveDifficulty(500, 90_000, 500).responseWindowMs, 200);
});

test("the endless challenge adds visible decoy pressure after the gentle reset", () => {
  const start = resolveDifficulty(20, 50_000, 0);
  const fiveHits = resolveDifficulty(25, 55_000, 5);
  const tenHits = resolveDifficulty(30, 60_000, 10);
  const twentyHits = resolveDifficulty(40, 70_000, 20);
  const fortyHits = resolveDifficulty(60, 90_000, 40);
  const capped = resolveDifficulty(70, 100_000, 50);

  assert.equal(start.decoyCount, 1);
  assert.equal(start.mixedDecoyChance, 0.2);
  assert.equal(fiveHits.responseWindowMs, 950);
  assert.equal(fiveHits.mixedDecoyChance, 0.275);
  assert.equal(tenHits.decoyCount, 2);
  assert.equal(tenHits.responseWindowMs, 900);
  assert.equal(tenHits.mixedDecoyChance, 0.35);
  assert.equal(twentyHits.decoyCount, 3);
  assert.equal(twentyHits.responseWindowMs, 800);
  assert.equal(twentyHits.mixedDecoyChance, 0.5);
  assert.equal(fortyHits.decoyCount, 5);
  assert.equal(fortyHits.mixedDecoyChance, 0.8);
  assert.equal(capped.decoyCount, 6);
  assert.equal(capped.mixedDecoyChance, 0.8);
  assert.ok(capped.spawnDelayRangeMs[0] < start.spawnDelayRangeMs[0]);
});

test("higher challenge tiers can place several decoys around one correct target", () => {
  const random = sequenceRandom([0.2, 0.2, 0.5]);
  const engine = makeEngine(random);
  engine.start(0);
  engine.hits = 20;
  engine.challengeStartHits = 0;

  const active = engine.activateRound(60_000);
  assert.equal(active.snapshot.roundKind, ROUND_KINDS.MIXED);
  assert.equal(active.snapshot.difficulty.decoyCount, 3);
  assert.equal(active.snapshot.cells.filter((cell) => cell.kind === "decoy").length, 3);
  assert.equal(active.snapshot.cells.filter((cell) => cell.kind === "target").length, 1);
});

test("normal mode loses one life for tapping a lone wrong color", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;
  const active = engine.activateRound(10_000);
  const wrongIndex = active.snapshot.cells.findIndex((cell) => cell.kind === "wrong-only");

  const result = engine.tap(wrongIndex, 10_100);
  assert.equal(result.type, "miss");
  assert.equal(result.lifeLost, true);
  assert.equal(result.snapshot.lives, 2);
  assert.equal(result.snapshot.state, GAME_STATES.WAITING);
  assert.equal(engine.isRunComplete(), false);
});

test("tapping the empty board while waiting loses a Normal life", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  const result = engine.tap(0, 100);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "empty");
  assert.equal(result.lifeLost, true);
  assert.equal(result.snapshot.lives, 2);
  assert.equal(result.snapshot.state, GAME_STATES.WAITING);
});

test("an inactive cell during a target round remains a life-losing mistake", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;
  const active = engine.activateRound(5_000);
  const idleIndex = active.snapshot.cells.findIndex((cell) => cell.kind === "idle");

  const result = engine.tap(idleIndex, 5_100);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "wrong");
  assert.equal(result.snapshot.lives, 2);
});

test("Normal mode cannot be completed by time while lives remain", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;
  const active = engine.activateRound(10_000);
  const wrongIndex = active.snapshot.cells.findIndex((cell) => cell.kind === "wrong-only");
  engine.tap(wrongIndex, 10_100);

  const timedAttempt = engine.finishTimedRun(10_000_000);
  assert.equal(timedAttempt.type, "ignored");
  assert.equal(timedAttempt.reason, "not-timed");
  assert.equal(engine.lives, 2);
  assert.equal(engine.state, GAME_STATES.WAITING);
  assert.equal(engine.isRunComplete(), false);
});

test("Zen mode records mistakes but never removes lives", () => {
  const wrongEngine = makeEngine(() => 0);
  wrongEngine.start(0, GAME_MODES.ZEN);
  wrongEngine.hits = 4;
  const wrongRound = wrongEngine.activateRound(10_000);
  const wrongIndex = wrongRound.snapshot.cells.findIndex((cell) => cell.kind === "wrong-only");
  const wrongResult = wrongEngine.tap(wrongIndex, 10_100);

  assert.equal(wrongResult.type, "miss");
  assert.equal(wrongResult.lifeLost, false);
  assert.equal(wrongResult.snapshot.lives, 3);

  const lateEngine = makeEngine();
  lateEngine.start(0, GAME_MODES.ZEN);
  const targetRound = lateEngine.activateRound(100);
  const lateResult = lateEngine.expireRound(
    100 + targetRound.snapshot.difficulty.responseWindowMs
  );
  assert.equal(lateResult.type, "miss");
  assert.equal(lateResult.lifeLost, false);
  assert.equal(lateResult.snapshot.lives, 3);

  const emptyEngine = makeEngine();
  emptyEngine.start(0, GAME_MODES.ZEN);
  const emptyResult = emptyEngine.tap(0, 100);
  assert.equal(emptyResult.reason, "empty");
  assert.equal(emptyResult.lifeLost, false);
  assert.equal(emptyResult.snapshot.lives, 3);
});

test("Zen mode ends at exactly one minute", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.ZEN);

  assert.equal(engine.finishTimedRun(59_999).reason, "time-remaining");
  const result = engine.finishTimedRun(60_000);
  assert.equal(result.type, "time-up");
  assert.equal(result.snapshot.state, GAME_STATES.GAME_OVER);
  assert.equal(result.snapshot.remainingMs, 0);
  assert.equal(result.snapshot.endReason, "time");
  assert.equal(engine.isRunComplete(), true);
});

test("the third Normal-mode mistake ends the run", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;

  let finalResult = null;
  for (let miss = 0; miss < 3; miss += 1) {
    const active = engine.activateRound(10_000 + miss * 2_000);
    const wrongIndex = active.snapshot.cells.findIndex((cell) => cell.kind === "wrong-only");
    finalResult = engine.tap(wrongIndex, 10_100 + miss * 2_000);
  }

  assert.equal(engine.state, GAME_STATES.GAME_OVER);
  assert.equal(engine.lives, 0);
  assert.equal(engine.isRunComplete(), true);
  assert.equal(finalResult.snapshot.elapsedMs, 14_100);
  assert.equal(engine.getSnapshot(999_999).elapsedMs, 14_100);
});

test("correct taps track fastest and average reaction times", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  hitRound(engine, 100, 80);
  hitRound(engine, 500, 200);
  const finalHit = hitRound(engine, 1_000, 350);

  assert.equal(finalHit.snapshot.fastestReactionMs, 80);
  assert.equal(finalHit.snapshot.averageReactionMs, 210);
  assert.equal(finalHit.snapshot.hits, 3);
});

test("the active-round snapshot exposes a smooth remaining-time ratio", () => {
  const engine = makeEngine();
  engine.start(0);
  const active = engine.activateRound(100);

  assert.equal(active.snapshot.reactionProgress, 1);
  assert.equal(engine.getSnapshot(350).reactionProgress, 0.75);
  assert.equal(engine.getSnapshot(1_100).reactionProgress, 0);
  engine.tap(active.snapshot.targetIndex, 200);
  assert.equal(engine.getSnapshot(200).reactionProgress, null);
});

test("reset clears dodge and reaction statistics", () => {
  const engine = makeEngine();
  engine.start(0);
  hitRound(engine, 100, 80);
  engine.reset();

  const snapshot = engine.getSnapshot(0);
  assert.equal(snapshot.dodges, 0);
  assert.equal(snapshot.fastestReactionMs, null);
  assert.equal(snapshot.averageReactionMs, null);
});

test("faster reactions still award more points", () => {
  assert.ok(scoreReaction(40, 1_000) > scoreReaction(500, 1_000));
  assert.ok(scoreReaction(500, 1_000) > scoreReaction(900, 1_000));
  assert.equal(scoreReaction(1_000, 1_000), GAME_CONFIG.scoreFloor);
});
