import assert from "node:assert/strict";
import test from "node:test";

import { GAME_CONFIG, GAME_MODES } from "../src/config.js";
import {
  GameEngine,
  GAME_STATES,
  MAX_DECOY_LIFETIME_MS,
  ROUND_KINDS,
  SPEED_RATING_IDS,
  classifyReaction,
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

test("opening play begins with one target and a 1000 ms lifetime", () => {
  const engine = makeEngine();
  engine.start(0);
  const result = engine.activateRound(100);

  assert.equal(result.snapshot.difficulty.gridDimension, 1);
  assert.equal(result.snapshot.difficulty.responseWindowMs, 1_000);
  assert.equal(result.snapshot.roundKind, ROUND_KINDS.TARGET);
  assert.equal(result.snapshot.cells.filter((cell) => cell.kind === "target").length, 1);
  assert.equal(result.snapshot.activeDecoys.length, 0);
});

test("the board grows to 2x2 after four taps but keeps the 1000 ms lifetime", () => {
  const engine = makeEngine();
  engine.start(0);

  for (let hit = 0; hit < 4; hit += 1) hitRound(engine, 100 + hit * 200);

  assert.equal(engine.getSnapshot(9_000).difficulty.gridDimension, 2);
  assert.equal(engine.getSnapshot(15_000).difficulty.responseWindowMs, 1_000);
});

test("the player color changes only after a correct post-opening tap", () => {
  const engine = makeEngine();
  engine.start(0);
  engine.hits = 4;
  const oldColor = engine.playerColorIndex;

  const result = hitRound(engine, 10_100, 100);

  assert.equal(result.type, "hit");
  assert.notEqual(result.snapshot.playerColorIndex, oldColor);
});

test("the gentle phase moves gradually from 1000 ms to 750 ms", () => {
  assert.equal(resolveDifficulty(4, 20_000).responseWindowMs, 1_000);
  assert.equal(resolveDifficulty(4, 25_000).responseWindowMs, 875);
  assert.equal(resolveDifficulty(4, 29_000).responseWindowMs, 775);
  assert.equal(resolveDifficulty(4, 30_000).responseWindowMs, 750);
});

test("the switch to 16 cells resets target lifetime and eases decoy capacity", () => {
  const difficulty = resolveDifficulty(20, 40_000);
  assert.equal(difficulty.gridDimension, 4);
  assert.equal(difficulty.phaseId, "four-by-four-reset");
  assert.equal(difficulty.responseWindowMs, 1_000);
  assert.equal(difficulty.maximumActiveDecoys, 1);
  assert.deepEqual(difficulty.decoySpawnDelayRangeMs, [900, 1_400]);
});

test("the endless challenge accelerates targets and independent decoy opportunities", () => {
  const start = resolveDifficulty(20, 50_000, 0);
  const tenHits = resolveDifficulty(30, 60_000, 10);
  const fortyHits = resolveDifficulty(60, 90_000, 40);
  const capped = resolveDifficulty(500, 180_000, 480);

  assert.equal(start.responseWindowMs, 1_000);
  assert.equal(start.maximumActiveDecoys, 2);
  assert.deepEqual(start.decoySpawnDelayRangeMs, [350, 700]);
  assert.equal(tenHits.responseWindowMs, 900);
  assert.equal(tenHits.maximumActiveDecoys, 3);
  assert.deepEqual(tenHits.decoySpawnDelayRangeMs, [315, 650]);
  assert.equal(fortyHits.responseWindowMs, 600);
  assert.equal(fortyHits.maximumActiveDecoys, 6);
  assert.deepEqual(fortyHits.decoySpawnDelayRangeMs, [210, 500]);
  assert.equal(capped.responseWindowMs, 200);
  assert.equal(capped.maximumActiveDecoys, 6);
  assert.deepEqual(capped.decoySpawnDelayRangeMs, [100, 250]);
});

test("independent decoy scheduling wakes at the phase boundary and then uses its own range", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);

  assert.equal(engine.getNextDecoyDelayMs(2_500), 7_500);
  engine.hits = 4;
  assert.equal(engine.getNextDecoyDelayMs(10_000), 900);
  assert.equal(engine.getNextDecoyDelayMs(35_000), 600);
});

test("decoys can appear while waiting or during a target and can overlap", () => {
  const engine = makeEngine(() => 0.25);
  engine.start(0);
  engine.hits = 4;

  const waitingDecoy = engine.activateDecoy(35_000);
  assert.equal(waitingDecoy.type, "decoy-active");
  const active = engine.activateRound(35_020);
  assert.equal(active.type, "round-active");
  const activeDecoy = engine.activateDecoy(35_040);
  assert.equal(activeDecoy.type, "decoy-active");
  assert.equal(activeDecoy.snapshot.activeDecoys.length, 2);
  assert.equal(activeDecoy.snapshot.cells.filter((cell) => cell.kind === "decoy").length, 2);
  assert.equal(activeDecoy.snapshot.cells.filter((cell) => cell.kind === "target").length, 1);
});

test("decoys use random free cells rather than adjacency to the target", () => {
  const engine = makeEngine(sequenceRandom([0, 0, 0, 0.999, 0]));
  engine.start(0);
  engine.hits = 20;
  const active = engine.activateRound(50_000);
  const decoy = engine.activateDecoy(50_050);

  assert.equal(active.snapshot.targetIndex, 0);
  assert.equal(decoy.decoy.cellIndex, 15);
  assert.equal(orthogonalNeighbors(0, 4).includes(decoy.decoy.cellIndex), false);
});

test("a decoy is never assigned the player's current color", () => {
  const engine = makeEngine(() => 0.5);
  engine.start(0);
  engine.hits = 4;

  const result = engine.activateDecoy(10_100);
  assert.equal(result.type, "decoy-active");
  assert.notEqual(result.decoy.colorIndex, result.snapshot.playerColorIndex);
  assert.equal(result.snapshot.cells[result.decoy.cellIndex].kind, "decoy");
});

test("decoy lifetimes are hard-capped at 500 ms", () => {
  const engine = makeEngine(() => 0.999999);
  engine.start(0);
  engine.hits = 4;

  const result = engine.activateDecoy(10_100);
  assert.equal(result.lifetimeMs, MAX_DECOY_LIFETIME_MS);
  assert.equal(result.decoy.expiresAt - result.decoy.visibleAt, MAX_DECOY_LIFETIME_MS);
});

test("self-expiring decoys award one dodge and configured average points", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);
  engine.hits = 4;
  const decoy = engine.activateDecoy(10_100);

  assert.equal(engine.expireDecoys(decoy.decoy.expiresAt - 0.01).reason, "not-expired");
  const result = engine.expireDecoys(decoy.decoy.expiresAt);
  assert.equal(result.type, "decoys-dodged");
  assert.equal(result.dodgesAwarded, 1);
  assert.equal(result.pointsAwarded, GAME_CONFIG.dodgePoints);
  assert.equal(result.snapshot.points, GAME_CONFIG.dodgePoints);
  assert.equal(result.snapshot.dodges, 1);
  assert.equal(result.snapshot.activeDecoys.length, 0);
});

test("one expiry callback can settle several independently expired decoys", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);
  engine.hits = 4;
  const first = engine.activateDecoy(35_000);
  const second = engine.activateDecoy(35_010);

  const result = engine.expireDecoys(second.decoy.expiresAt);
  assert.equal(result.type, "decoys-dodged");
  assert.deepEqual(result.decoyIds, [first.decoy.id, second.decoy.id]);
  assert.equal(result.dodgesAwarded, 2);
  assert.equal(result.pointsAwarded, 2 * GAME_CONFIG.dodgePoints);
});

test("a correct target tap clears live decoys without awarding dodge points", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);
  engine.hits = 4;
  const active = engine.activateRound(35_000);
  const decoy = engine.activateDecoy(35_050);

  const result = engine.tap(active.snapshot.targetIndex, 35_100);
  assert.equal(result.type, "hit");
  assert.equal(result.dodgesAwarded, 0);
  assert.equal(result.snapshot.dodges, 0);
  assert.equal(result.snapshot.activeDecoys.length, 0);
  assert.equal(engine.expireDecoys(decoy.decoy.expiresAt).reason, "not-expired");
});

test("a visually present decoy cleared by a correct tap never earns a delayed dodge", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);
  engine.hits = 4;
  const active = engine.activateRound(35_000);
  const decoy = engine.activateDecoy(35_050);
  const inputAt = decoy.decoy.expiresAt + 1;

  const result = engine.tap(active.snapshot.targetIndex, inputAt);
  assert.equal(result.type, "hit");
  assert.equal(result.dodgesAwarded, 0);
  assert.equal(result.dodgePointsAwarded, 0);
  assert.equal(result.snapshot.dodges, 0);
  assert.equal(result.snapshot.activeDecoys.length, 0);
});

test("tapping a live decoy is a mistake and clears it without a dodge", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;
  engine.activateRound(35_000);
  const decoy = engine.activateDecoy(35_050);

  const result = engine.tap(decoy.decoy.cellIndex, 35_100);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "wrong");
  assert.equal(result.lifeLost, true);
  assert.equal(result.snapshot.dodges, 0);
  assert.equal(result.snapshot.activeDecoys.length, 0);
  assert.equal(engine.expireDecoys(decoy.decoy.expiresAt).reason, "not-expired");
});

test("target expiry clears still-live decoys without awarding dodge", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 100;
  engine.challengeStartHits = 20;
  const active = engine.activateRound(130_000);
  const decoy = engine.activateDecoy(130_010);
  assert.equal(active.snapshot.difficulty.responseWindowMs, 200);
  assert.ok(decoy.decoy.expiresAt > 130_200);

  const result = engine.expireRound(130_200);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "late");
  assert.equal(result.dodgesAwarded, 0);
  assert.equal(result.snapshot.dodges, 0);
  assert.equal(result.snapshot.activeDecoys.length, 0);
});

test("tapping the empty board while waiting remains a Normal-mode mistake", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  const result = engine.tap(0, 100);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "empty");
  assert.equal(result.lifeLost, true);
  assert.equal(result.snapshot.lives, 2);
  assert.equal(result.snapshot.state, GAME_STATES.WAITING);
});

test("a lost life creates an engine-enforced quiet recovery for targets and decoys", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;

  const missedAt = 35_000;
  const miss = engine.tap(0, missedAt);
  assert.equal(miss.type, "miss");
  assert.equal(miss.snapshot.recoveryRemainingMs, GAME_CONFIG.lifeLossRecoveryMs);
  assert.equal(engine.getNextDelayMs(missedAt), GAME_CONFIG.lifeLossRecoveryMs + 475);
  assert.equal(engine.getNextDecoyDelayMs(missedAt), GAME_CONFIG.lifeLossRecoveryMs + 600);

  const beforeRecoveryEnds = missedAt + GAME_CONFIG.lifeLossRecoveryMs - 1;
  assert.equal(engine.activateRound(beforeRecoveryEnds).reason, "recovering");
  assert.equal(engine.activateDecoy(beforeRecoveryEnds).reason, "recovering");
  assert.equal(engine.lives, 2);
  assert.equal(engine.misses, 1);

  const recoveryEnds = missedAt + GAME_CONFIG.lifeLossRecoveryMs;
  const active = engine.activateRound(recoveryEnds);
  assert.equal(active.type, "round-active");
  assert.equal(active.snapshot.recoveryRemainingMs, 0);
  assert.equal(engine.activateDecoy(recoveryEnds).type, "decoy-active");
});

test("empty-board taps remain mistakes during recovery and restart the quiet period", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  const firstMiss = engine.tap(0, 100);
  assert.equal(firstMiss.type, "miss");
  assert.equal(firstMiss.snapshot.lives, 2);

  const secondMiss = engine.tap(0, 200);
  assert.equal(secondMiss.type, "miss");
  assert.equal(secondMiss.reason, "empty");
  assert.equal(secondMiss.snapshot.lives, 1);
  assert.equal(secondMiss.snapshot.recoveryRemainingMs, GAME_CONFIG.lifeLossRecoveryMs);
  assert.equal(engine.activateRound(1_699).reason, "recovering");
  assert.equal(engine.activateRound(1_700).type, "round-active");
});

test("reaction contact time stays separate from the visible recovery start", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  const result = engine.tap(0, 100, 240);
  assert.equal(result.snapshot.elapsedMs, 240);
  assert.equal(result.snapshot.recoveryRemainingMs, GAME_CONFIG.lifeLossRecoveryMs);
  assert.equal(engine.activateRound(1_739).reason, "recovering");
  assert.equal(engine.activateRound(1_740).type, "round-active");
});

test("recovery delays use the difficulty in effect after the quiet period", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.NORMAL);
  engine.hits = 4;

  const missedAt = 9_500;
  engine.tap(0, missedAt);

  assert.equal(engine.getNextDelayMs(missedAt), GAME_CONFIG.lifeLossRecoveryMs + 550);
  assert.equal(engine.getNextDecoyDelayMs(missedAt), GAME_CONFIG.lifeLossRecoveryMs + 900);
});

test("Normal mode is endless until the third mistake", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  assert.equal(engine.finishTimedRun(10_000_000).reason, "not-timed");
  for (let miss = 0; miss < 3; miss += 1) {
    engine.tap(0, 100 + miss * 1_600);
  }

  assert.equal(engine.state, GAME_STATES.GAME_OVER);
  assert.equal(engine.lives, 0);
  assert.equal(engine.endReason, "lives");
  assert.equal(engine.isRunComplete(), true);
});

test("Zen records mistakes without losing lives and ends at exactly three minutes", () => {
  const engine = makeEngine();
  engine.start(1_000, GAME_MODES.ZEN);
  const miss = engine.tap(0, 1_100);

  assert.equal(miss.type, "miss");
  assert.equal(miss.lifeLost, false);
  assert.equal(miss.snapshot.lives, 3);
  assert.equal(miss.snapshot.recoveryRemainingMs, 0);
  assert.equal(engine.finishTimedRun(180_999).reason, "time-remaining");
  const result = engine.finishTimedRun(181_000);
  assert.equal(result.type, "time-up");
  assert.equal(result.snapshot.elapsedMs, 180_000);
  assert.equal(result.snapshot.remainingMs, 0);
  assert.equal(result.snapshot.endReason, "time");
  assert.equal(engine.isRunComplete(), true);
});

test("Zen completion clears an expiring decoy without a post-deadline dodge", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.ZEN);
  engine.hits = 4;
  const decoy = engine.activateDecoy(179_700);
  assert.equal(decoy.decoy.expiresAt, 180_000);

  const result = engine.finishTimedRun(180_010);
  assert.equal(result.type, "time-up");
  assert.equal(result.dodgesAwarded, 0);
  assert.equal(result.snapshot.dodges, 0);
  assert.equal(result.snapshot.points, 0);
});

test("speed ratings classify the same rounded milliseconds shown to players", () => {
  assert.deepEqual(classifyReaction(199.49), {
    id: SPEED_RATING_IDS.GODLIKE,
    label: "Godlike",
    displayedMs: 199
  });
  assert.equal(classifyReaction(199.5).id, SPEED_RATING_IDS.PERFECT);
  assert.equal(classifyReaction(299.49).id, SPEED_RATING_IDS.PERFECT);
  assert.equal(classifyReaction(299.5).id, SPEED_RATING_IDS.GREAT);
  assert.equal(classifyReaction(399.49).id, SPEED_RATING_IDS.GREAT);
  assert.equal(classifyReaction(399.5).id, SPEED_RATING_IDS.GOOD);
  assert.equal(classifyReaction(-10).displayedMs, 0);
});

test("correct taps retain per-run speed rating counts with reaction statistics", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  hitRound(engine, 100, 150);
  hitRound(engine, 1_500, 250);
  hitRound(engine, 3_000, 350);
  const finalHit = hitRound(engine, 5_000, 450);

  assert.deepEqual(finalHit.snapshot.speedRatings, {
    godlike: 1,
    perfect: 1,
    great: 1,
    good: 1
  });
  assert.equal(finalHit.snapshot.fastestReactionMs, 150);
  assert.equal(finalHit.snapshot.averageReactionMs, 300);
  assert.equal(finalHit.snapshot.hits, 4);
  assert.equal(finalHit.speedRating.id, SPEED_RATING_IDS.GOOD);
  assert.equal(finalHit.displayedReactionMs, 450);
});

test("five Godlike or Perfect taps unlock the next multiplier for the following hit", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  let thresholdHit;
  for (let hit = 0; hit < 5; hit += 1) {
    thresholdHit = hitRound(engine, 100 + hit * 1_000, 150);
  }

  assert.equal(thresholdHit.multiplierUsed, 1);
  assert.equal(thresholdHit.multiplierAfter, 2);
  assert.equal(thresholdHit.multiplierRaised, true);
  assert.equal(thresholdHit.snapshot.streakProgress, 0);

  const accumulatedBeforeMultiplier = thresholdHit.snapshot.points;
  const multipliedHit = hitRound(engine, 5_500, 350);
  assert.equal(multipliedHit.speedRating.id, SPEED_RATING_IDS.GREAT);
  assert.equal(multipliedHit.multiplierUsed, 2);
  assert.equal(multipliedHit.pointsAwarded, multipliedHit.basePointsAwarded * 2);
  assert.equal(
    multipliedHit.snapshot.points,
    accumulatedBeforeMultiplier + multipliedHit.pointsAwarded,
    "The multiplier applies only to the current tap and never rescales the accumulated run score."
  );
  assert.equal(multipliedHit.snapshot.multiplier, 2);
});

test("Great preserves streak progress while Good resets before scoring", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  hitRound(engine, 100, 150);
  hitRound(engine, 1_100, 250);
  const great = hitRound(engine, 2_100, 350);
  assert.equal(great.snapshot.streakProgress, 2);
  assert.equal(great.snapshot.multiplier, 1);

  hitRound(engine, 3_100, 150);
  const fourthFastTap = hitRound(engine, 4_100, 250);
  assert.equal(fourthFastTap.snapshot.streakProgress, 4);
  const unlock = hitRound(engine, 5_100, 150);
  assert.equal(unlock.snapshot.multiplier, 2);

  const good = hitRound(engine, 6_100, 450);
  assert.equal(good.speedRating.id, SPEED_RATING_IDS.GOOD);
  assert.equal(good.multiplierUsed, 1);
  assert.equal(good.pointsAwarded, good.basePointsAwarded);
  assert.equal(good.snapshot.multiplier, 1);
  assert.equal(good.snapshot.streakProgress, 0);
});

test("mistakes reset the multiplier in both game modes while dodges are neutral", () => {
  for (const mode of Object.values(GAME_MODES)) {
    const engine = makeEngine(() => 0);
    engine.start(0, mode);
    for (let hit = 0; hit < 5; hit += 1) {
      hitRound(engine, 100 + hit * 1_000, 150);
    }
    engine.hits = Math.max(engine.hits, 4);
    const decoy = engine.activateDecoy(10_100);
    const dodge = engine.expireDecoys(decoy.decoy.expiresAt);
    assert.equal(dodge.snapshot.multiplier, 2);

    const miss = engine.tap(0, decoy.decoy.expiresAt + 10);
    assert.equal(miss.type, "miss");
    assert.equal(miss.snapshot.multiplier, 1);
    assert.equal(miss.snapshot.streakProgress, 0);
  }
});

test("score accounting and multiplier hit buckets reconcile through the 5x cap", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);
  let result;
  const milestones = new Map();
  for (let hit = 0; hit < 21; hit += 1) {
    result = hitRound(engine, 100 + hit * 1_900, 150);
    if ([5, 10, 15, 20].includes(hit + 1)) milestones.set(hit + 1, result);
  }

  assert.deepEqual(
    [...milestones].map(([hit, milestone]) => [
      hit,
      milestone.multiplierUsed,
      milestone.multiplierAfter
    ]),
    [
      [5, 1, 2],
      [10, 2, 3],
      [15, 3, 4],
      [20, 4, 5]
    ]
  );
  assert.equal(result.multiplierUsed, 5);
  assert.equal(result.snapshot.multiplier, 5);
  assert.equal(result.snapshot.maximumMultiplierUsed, 5);
  assert.equal(result.snapshot.streakProgress, GAME_CONFIG.streak.tapsPerMultiplier);
  assert.deepEqual(result.snapshot.multiplierHitCounts, {
    1: 5,
    2: 5,
    3: 5,
    4: 5,
    5: 1
  });
  assert.equal(
    Object.values(result.snapshot.multiplierBasePoints).reduce((sum, points) => sum + points, 0),
    result.snapshot.reactionBasePoints
  );
  assert.equal(
    Object.entries(result.snapshot.multiplierBasePoints).reduce(
      (sum, [multiplier, points]) => sum + (Number(multiplier) - 1) * points,
      0
    ),
    result.snapshot.multiplierBonusPoints
  );
  assert.equal(
    result.snapshot.points,
    result.snapshot.reactionBasePoints + result.snapshot.multiplierBonusPoints
  );
});

test("reset clears dodge, reaction, speed-rating, and multiplier statistics", () => {
  const engine = makeEngine();
  engine.start(0);
  hitRound(engine, 100, 80);
  engine.reset();

  const snapshot = engine.getSnapshot(0);
  assert.equal(snapshot.dodges, 0);
  assert.equal(snapshot.fastestReactionMs, null);
  assert.equal(snapshot.averageReactionMs, null);
  assert.deepEqual(snapshot.speedRatings, { godlike: 0, perfect: 0, great: 0, good: 0 });
  assert.equal(snapshot.multiplier, 1);
  assert.equal(snapshot.streakProgress, 0);
  assert.equal(snapshot.reactionBasePoints, 0);
  assert.equal(snapshot.multiplierBonusPoints, 0);
  assert.deepEqual(snapshot.multiplierBasePoints, { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 });
});

test("the active target snapshot exposes a smooth remaining-time ratio", () => {
  const engine = makeEngine();
  engine.start(0);
  const active = engine.activateRound(100);

  assert.equal(active.snapshot.reactionProgress, 1);
  assert.equal(engine.getSnapshot(350).reactionProgress, 0.75);
  assert.equal(engine.getSnapshot(1_100).reactionProgress, 0);
  engine.tap(active.snapshot.targetIndex, 200);
  assert.equal(engine.getSnapshot(200).reactionProgress, null);
});

test("faster reactions still award more points", () => {
  assert.ok(scoreReaction(40, 1_000) > scoreReaction(500, 1_000));
  assert.ok(scoreReaction(500, 1_000) > scoreReaction(900, 1_000));
  assert.equal(scoreReaction(1_000, 1_000), GAME_CONFIG.scoreFloor);
});
