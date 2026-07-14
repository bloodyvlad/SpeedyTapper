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

test("run proof records rounded target and hit timing without exposing mutable state", () => {
  const engine = makeEngine();
  engine.start(1_000.2, GAME_MODES.NORMAL);
  const active = engine.activateRound(1_100.6);
  const targetIndex = active.snapshot.targetIndex;

  const hit = engine.tap(targetIndex, 1_250.4, 1_251.8);
  assert.equal(hit.type, "hit");

  const expected = [
    [0, 100, targetIndex],
    [1, 250, 252, targetIndex]
  ];
  const received = engine.getRunProofEvents();
  assert.deepEqual(received, expected);

  received[0][1] = 999;
  received.push([5, 999, 999]);
  assert.deepEqual(engine.getRunProofEvents(), expected);

  engine.reset();
  assert.deepEqual(engine.getRunProofEvents(), []);
});

test("Normal proof time stays monotonic after separately rounded target and reaction intervals", () => {
  const engine = makeEngine(() => 0);
  engine.start(0.2, GAME_MODES.NORMAL);
  engine.hits = 4;
  const target = engine.activateRound(10_000.8);
  const hit = engine.tap(target.snapshot.targetIndex, 10_186.4, 10_186.4);
  assert.equal(hit.type, "hit");
  const decoy = engine.activateDecoy(10_186.5);
  assert.equal(decoy.type, "decoy-active");

  assert.deepEqual(engine.getRunProofEvents().map((event) => event.slice(0, 2)), [
    [0, 10_001],
    [1, 10_187],
    [3, 10_187]
  ]);
});

test("Zen target cadence starts at one second and moves halfway toward each reaction", () => {
  assert.equal(GAME_CONFIG.zen.durationMs, null);
  assert.equal(GAME_CONFIG.zen.decoysEnabled, false);
  assert.equal(GAME_CONFIG.zen.ranked, false);
  assert.equal(GAME_CONFIG.zen.awardsCoins, false);

  let randomCalls = 0;
  const engine = makeEngine(() => {
    randomCalls += 1;
    return 0;
  });
  engine.start(0, GAME_MODES.ZEN);
  const callsAfterStart = randomCalls;

  assert.equal(engine.getNextDelayMs(0), 1_000);
  assert.equal(engine.getSnapshot(0).nextTargetDelayMs, 1_000);
  assert.equal(randomCalls, callsAfterStart, "Zen cadence must not consume random spawn timing.");

  const faster = hitRound(engine, 1_000, 400);
  assert.equal(faster.snapshot.nextTargetDelayMs, 700);
  assert.equal(engine.getNextDelayMs(1_400), 700);

  const slower = hitRound(engine, 2_100, 1_000);
  assert.equal(slower.snapshot.nextTargetDelayMs, 850);
  assert.equal(engine.getNextDelayMs(3_100), 850);

  engine.start(4_000, GAME_MODES.ZEN);
  assert.equal(engine.getNextDelayMs(4_000), 1_000, "A new Zen run resets the learned cadence.");
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

test("difficulty uses the same integer proof clock across sub-millisecond phase timing", () => {
  assert.equal(resolveDifficulty(8, 23_460.49).responseWindowMs, 914);
  assert.equal(resolveDifficulty(8, 23_460.51).responseWindowMs, 913);

  const engine = makeEngine();
  engine.start(0.2);
  engine.hits = 8;
  const active = engine.activateRound(23_460.69);
  assert.equal(active.snapshot.difficulty.responseWindowMs, 914);
  const result = engine.tap(active.snapshot.targetIndex, 24_374.29, 24_374.4);

  assert.equal(result.type, "hit");
  assert.equal(result.displayedReactionMs, 914);
  assert.deepEqual(engine.getRunProofEvents().slice(-2), [
    [0, 23_460, active.snapshot.targetIndex],
    [1, 24_374, 24_374, active.snapshot.targetIndex]
  ]);
});

test("programmed pace levels follow game phases rather than reaction timing", () => {
  assert.equal(resolveDifficulty(0, 0).paceLevel, 0);
  assert.equal(resolveDifficulty(4, 9_000).paceLevel, 1);
  assert.equal(resolveDifficulty(4, 15_000).paceLevel, 1);
  assert.equal(resolveDifficulty(4, 25_000).paceLevel, 2);
  assert.equal(resolveDifficulty(4, 35_000).paceLevel, 3);
  assert.equal(resolveDifficulty(20, 45_000).paceLevel, 4);
  for (let tier = 0; tier <= 6; tier += 1) {
    assert.equal(resolveDifficulty(20 + tier * 10, 50_000, tier * 10).paceLevel, 5 + tier);
  }
  assert.equal(resolveDifficulty(500, 50_000, 480).paceLevel, 11);
});

test("the switch to 16 cells resets target lifetime and eases decoy capacity", () => {
  const difficulty = resolveDifficulty(20, 40_000);
  assert.equal(difficulty.gridDimension, 4);
  assert.equal(difficulty.phaseId, "four-by-four-reset");
  assert.equal(difficulty.responseWindowMs, 1_000);
  assert.equal(difficulty.maximumActiveDecoys, 1);
  assert.deepEqual(difficulty.decoySpawnDelayRangeMs, [2_200, 3_400]);
});

test("the endless challenge accelerates targets and independent decoy opportunities", () => {
  const start = resolveDifficulty(20, 50_000, 0);
  const tenHits = resolveDifficulty(30, 60_000, 10);
  const fortyHits = resolveDifficulty(60, 90_000, 40);
  const capped = resolveDifficulty(500, 180_000, 480);

  assert.equal(start.responseWindowMs, 1_000);
  assert.equal(start.maximumActiveDecoys, 2);
  assert.deepEqual(start.decoySpawnDelayRangeMs, [600, 2_000]);
  assert.equal(tenHits.responseWindowMs, 900);
  assert.equal(tenHits.maximumActiveDecoys, 3);
  assert.deepEqual(tenHits.decoySpawnDelayRangeMs, [600, 1_830]);
  assert.equal(fortyHits.responseWindowMs, 600);
  assert.equal(fortyHits.maximumActiveDecoys, 6);
  assert.deepEqual(fortyHits.decoySpawnDelayRangeMs, [600, 1_320]);
  assert.equal(capped.responseWindowMs, 200);
  assert.equal(capped.maximumActiveDecoys, 6);
  assert.deepEqual(capped.decoySpawnDelayRangeMs, [600, 1_100]);
});

test("independent decoy scheduling wakes at the phase boundary and then uses its own range", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);

  assert.equal(engine.getNextDecoyDelayMs(2_500), 7_500);
  engine.hits = 4;
  assert.equal(engine.getNextDecoyDelayMs(10_000), 2_200);
  assert.equal(engine.getNextDecoyDelayMs(35_000), 600);
});

test("configured decoy gaps keep the doubled mean while permitting occasional overlap", () => {
  const rare = resolveDifficulty(4, 35_000).decoySpawnDelayRangeMs;
  const challenge = resolveDifficulty(20, 50_000, 0).decoySpawnDelayRangeMs;

  assert.deepEqual(rare, [600, 3_400]);
  assert.equal((rare[0] + rare[1]) / 2, 2_000);
  assert.equal((challenge[0] + challenge[1]) / 2, 1_300);
  assert.ok(rare[0] < MAX_DECOY_LIFETIME_MS);
  assert.ok(challenge[0] < MAX_DECOY_LIFETIME_MS);
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

test("run proof records independent decoy opportunities that cannot spawn", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);

  const disabled = engine.activateDecoy(10_000);
  assert.equal(disabled.type, "ignored");
  assert.equal(disabled.reason, "decoys-disabled");

  engine.hits = 4;
  const active = engine.activateDecoy(10_150);
  assert.equal(active.type, "decoy-active");
  const capacity = engine.activateDecoy(10_151);
  assert.equal(capacity.type, "ignored");
  assert.equal(capacity.reason, "decoy-capacity");

  assert.deepEqual(engine.getRunProofEvents(), [
    [6, 10_000],
    [3, 10_150, 1, active.decoy.cellIndex, 450],
    [6, 10_151]
  ]);
});

test("decoy lifetimes vary from 450 ms through the 750 ms hard cap", () => {
  const minimumEngine = makeEngine(() => 0);
  minimumEngine.start(0);
  minimumEngine.hits = 4;
  assert.equal(minimumEngine.activateDecoy(10_100).lifetimeMs, 450);

  const engine = makeEngine(() => 0.999999);
  engine.start(0);
  engine.hits = 4;

  const result = engine.activateDecoy(10_100);
  assert.equal(result.lifetimeMs, MAX_DECOY_LIFETIME_MS);
  assert.equal(result.decoy.expiresAt - result.decoy.visibleAt, MAX_DECOY_LIFETIME_MS);
});

test("an expiring decoy cell is reserved from the target on the same frame", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);
  engine.hits = 4;
  const decoy = engine.activateDecoy(10_100);
  const result = engine.activateRound(decoy.decoy.expiresAt);

  assert.equal(result.type, "round-active");
  assert.equal(result.dodgesAwarded, 1);
  assert.notEqual(result.snapshot.targetIndex, decoy.decoy.cellIndex);
  assert.equal(result.snapshot.cells[decoy.decoy.cellIndex].kind, "idle");
});

test("a separately settled decoy cell remains reserved for the next target", () => {
  const engine = makeEngine(() => 0);
  engine.start(0);
  engine.hits = 4;
  const decoy = engine.activateDecoy(10_100);
  const expiry = engine.expireDecoys(decoy.decoy.expiresAt);
  const result = engine.activateRound(decoy.decoy.expiresAt);

  assert.equal(expiry.type, "decoys-dodged");
  assert.equal(result.type, "round-active");
  assert.notEqual(result.snapshot.targetIndex, decoy.decoy.cellIndex);
  assert.equal(result.snapshot.cells[decoy.decoy.cellIndex].kind, "idle");
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

test("run proof records decoy activations and grouped natural expiry", () => {
  const engine = makeEngine(() => 0);
  engine.start(1_000);
  engine.hits = 4;

  const first = engine.activateDecoy(36_000);
  const second = engine.activateDecoy(36_010);
  engine.expireDecoys(second.decoy.expiresAt);

  assert.deepEqual(engine.getRunProofEvents(), [
    [3, 35_000, first.decoy.id, first.decoy.cellIndex, 450],
    [3, 35_010, second.decoy.id, second.decoy.cellIndex, 450],
    [4, 35_460, first.decoy.id, second.decoy.id]
  ]);
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
  assert.equal(engine.getNextDecoyDelayMs(missedAt), GAME_CONFIG.lifeLossRecoveryMs + 2_200);
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

test("run proof distinguishes empty, wrong, and timer misses and finishes on the third Normal miss", () => {
  const engine = makeEngine();
  engine.start(1_000, GAME_MODES.NORMAL);

  engine.tap(0, 1_100, 1_105);
  const secondRound = engine.activateRound(3_000);
  const wrongCell = secondRound.snapshot.targetIndex + 1;
  engine.tap(wrongCell, 3_100, 3_102);
  const finalRound = engine.activateRound(5_000);
  assert.equal(finalRound.snapshot.difficulty.responseWindowMs, 1_000);
  engine.expireRound(6_000);

  assert.deepEqual(engine.getRunProofEvents(), [
    [2, 100, 105, 0, 0],
    [0, 2_000, secondRound.snapshot.targetIndex],
    [2, 2_100, 2_102, 1, wrongCell],
    [0, 4_000, finalRound.snapshot.targetIndex],
    [2, 5_000, 5_000, 2, -1],
    [5, 5_000, 5_000]
  ]);
});

test("Arcade survival uses the same integer logical instant as the terminal proof", () => {
  const engine = makeEngine();
  engine.start(0.4, GAME_MODES.NORMAL);

  engine.tap(0, 100.7, 101.2);
  engine.tap(0, 201.1, 201.6);
  const result = engine.tap(0, 301.2, 306.7);
  const proof = engine.getRunProofEvents();
  const finish = proof.at(-1);

  assert.equal(result.snapshot.elapsedMs, finish[1]);
  assert.deepEqual(finish, [5, 301, 306]);
});

test("Zen is endless practice and records mistakes without losing lives", () => {
  const engine = makeEngine();
  engine.start(1_000, GAME_MODES.ZEN);
  const miss = engine.tap(0, 1_100);

  assert.equal(miss.type, "miss");
  assert.equal(miss.lifeLost, false);
  assert.equal(miss.snapshot.lives, 3);
  assert.equal(miss.snapshot.recoveryRemainingMs, 0);
  const result = engine.finishTimedRun(10_001_000);
  assert.equal(result.type, "ignored");
  assert.equal(result.reason, "not-timed");
  assert.equal(result.snapshot.elapsedMs, 10_000_000);
  assert.equal(result.snapshot.remainingMs, null);
  assert.equal(result.snapshot.endReason, null);
  assert.equal(result.snapshot.state, GAME_STATES.WAITING);
  assert.equal(engine.isRunComplete(), false);
  assert.deepEqual(engine.getRunProofEvents(), []);
});

test("Zen remains playable beyond the former three-minute boundary", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.ZEN);
  const active = engine.activateRound(180_000);
  const hit = engine.tap(active.snapshot.targetIndex, 181_000, 181_001);
  assert.equal(hit.type, "hit");
  assert.equal(hit.snapshot.elapsedMs, 181_000);
  assert.ok(hit.snapshot.points > 0);
  assert.equal(hit.snapshot.state, GAME_STATES.WAITING);
  assert.equal(engine.isRunComplete(), false);
  assert.deepEqual(engine.getRunProofEvents(), []);
});

test("ending an active Zen run freezes its result and clears live play state", () => {
  const engine = makeEngine(() => 0);
  engine.start(1_000, GAME_MODES.ZEN);
  const active = engine.activateRound(5_000);
  engine.activeDecoys = [{
    id: 1,
    cellIndex: (active.snapshot.targetIndex + 1) % 4,
    colorIndex: 1,
    visibleAt: 5_100,
    expiresAt: 20_000
  }];

  const result = engine.endZenRun(12_345);

  assert.equal(result.type, "zen-ended");
  assert.equal(result.reason, "manual");
  assert.equal(result.snapshot.state, GAME_STATES.GAME_OVER);
  assert.equal(result.snapshot.endReason, "manual");
  assert.equal(result.snapshot.elapsedMs, 11_345);
  assert.equal(result.snapshot.targetIndex, null);
  assert.equal(result.snapshot.activeDecoys.length, 0);
  assert.equal(result.snapshot.cells.every(({ kind }) => kind === "idle"), true);
  assert.equal(result.snapshot.roundKind, null);
  assert.equal(engine.isRunComplete(), true);
  assert.deepEqual(engine.getRunProofEvents(), []);
  assert.deepEqual(engine.getSnapshot(999_999), result.snapshot);
});

test("ending a waiting Zen run preserves its accumulated statistics", () => {
  const engine = makeEngine();
  engine.start(100, GAME_MODES.ZEN);
  const hit = hitRound(engine, 1_100, 240);

  const result = engine.endZenRun(2_500);

  assert.equal(hit.snapshot.state, GAME_STATES.WAITING);
  assert.equal(result.type, "zen-ended");
  assert.equal(result.snapshot.elapsedMs, 2_400);
  assert.equal(result.snapshot.points, hit.snapshot.points);
  assert.equal(result.snapshot.hits, 1);
  assert.equal(result.snapshot.fastestReactionMs, 240);
  assert.equal(result.snapshot.averageReactionMs, 240);
  assert.deepEqual(result.snapshot.speedRatings, hit.snapshot.speedRatings);
});

test("manual Zen ending ignores idle, Arcade, and already-ended runs", () => {
  const idle = makeEngine();
  const idleResult = idle.endZenRun(100);
  assert.equal(idleResult.type, "ignored");
  assert.equal(idleResult.reason, "not-running");
  assert.equal(idle.state, GAME_STATES.IDLE);

  const arcade = makeEngine();
  arcade.start(0, GAME_MODES.NORMAL);
  const arcadeResult = arcade.endZenRun(100);
  assert.equal(arcadeResult.type, "ignored");
  assert.equal(arcadeResult.reason, "not-zen");
  assert.equal(arcade.state, GAME_STATES.WAITING);
  assert.equal(arcade.endReason, null);

  const zen = makeEngine();
  zen.start(0, GAME_MODES.ZEN);
  const ended = zen.endZenRun(1_000);
  const endedAgain = zen.endZenRun(5_000);
  assert.equal(endedAgain.type, "ignored");
  assert.equal(endedAgain.reason, "already-ended");
  assert.deepEqual(endedAgain.snapshot, ended.snapshot);
});

test("Zen targets stay active through wrong taps and have no response deadline", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.ZEN);
  engine.hits = 4;
  const active = engine.activateRound(10_000);
  const targetIndex = active.snapshot.targetIndex;
  const wrongIndex = (targetIndex + 1) % 4;

  assert.equal(active.snapshot.reactionProgress, null);
  assert.equal(engine.expireRound(11_000).reason, "target-does-not-expire");
  assert.equal(engine.getSnapshot(80_000).targetIndex, targetIndex);
  assert.equal(engine.getSnapshot(80_000).state, GAME_STATES.ACTIVE);

  const wrong = engine.tap(wrongIndex, 80_000);
  assert.equal(wrong.type, "miss");
  assert.equal(wrong.targetRetained, true);
  assert.equal(wrong.snapshot.targetIndex, targetIndex);
  assert.equal(wrong.snapshot.state, GAME_STATES.ACTIVE);

  const hit = engine.tap(targetIndex, 100_000);
  assert.equal(hit.type, "hit");
  assert.equal(hit.reactionMs, 90_000);
  assert.equal(hit.snapshot.fastestReactionMs, 90_000);
  assert.equal(hit.snapshot.state, GAME_STATES.WAITING);
});

test("Zen disables decoy cadence and activation at every difficulty", () => {
  const engine = makeEngine(() => 0);
  engine.start(0, GAME_MODES.ZEN);
  engine.hits = 100;
  const active = engine.activateRound(300_000);
  assert.equal(active.snapshot.difficulty.maximumActiveDecoys, 0);
  assert.equal(active.snapshot.difficulty.decoySpawnDelayRangeMs, null);
  assert.equal(engine.getNextDecoyDelayMs(300_001), null);

  const result = engine.activateDecoy(300_001);
  assert.equal(result.type, "ignored");
  assert.equal(result.reason, "decoys-disabled");
  assert.equal(result.snapshot.activeDecoys.length, 0);
  assert.equal(result.snapshot.dodges, 0);
  assert.deepEqual(engine.getRunProofEvents(), []);
});

test("speed ratings classify the same rounded milliseconds shown to players", () => {
  assert.deepEqual(classifyReaction(249.49), {
    id: SPEED_RATING_IDS.GODLIKE,
    label: "Godlike",
    displayedMs: 249
  });
  assert.equal(classifyReaction(249.5).id, SPEED_RATING_IDS.PERFECT);
  assert.equal(classifyReaction(349.49).id, SPEED_RATING_IDS.PERFECT);
  assert.equal(classifyReaction(349.5).id, SPEED_RATING_IDS.GREAT);
  assert.equal(classifyReaction(449.49).id, SPEED_RATING_IDS.GREAT);
  assert.equal(classifyReaction(449.5).id, SPEED_RATING_IDS.GOOD);
  assert.equal(classifyReaction(-10).displayedMs, 0);
});

test("reaction score and statistics use the rounded displayed milliseconds", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);
  const active = engine.activateRound(100);

  const result = engine.tap(active.snapshot.targetIndex, 349.5, 350.4);

  assert.equal(result.reactionMs, 249.5);
  assert.equal(result.displayedReactionMs, 250);
  assert.equal(result.basePointsAwarded, scoreReaction(250, 1_000));
  assert.equal(result.snapshot.fastestReactionMs, 250);
  assert.equal(result.snapshot.averageReactionMs, 250);
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

test("Godlike adds two streak steps, Perfect adds one, and Great and Good remain neutral", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  const godlike = hitRound(engine, 100, 150);
  assert.equal(godlike.snapshot.streakProgress, 2);

  const perfect = hitRound(engine, 1_100, 250);
  assert.equal(perfect.snapshot.streakProgress, 3);

  const great = hitRound(engine, 2_100, 350);
  assert.equal(great.snapshot.streakProgress, 3);

  const good = hitRound(engine, 3_100, 450);
  assert.equal(good.snapshot.streakProgress, 3);

  const thresholdHit = hitRound(engine, 4_100, 150);
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
  assert.equal(multipliedHit.snapshot.streakProgress, 0);
});

test("Godlike overflow carries into the next multiplier and the threshold tap uses the old multiplier", () => {
  const engine = makeEngine();
  engine.start(0, GAME_MODES.NORMAL);

  hitRound(engine, 100, 150);
  const fourSteps = hitRound(engine, 1_100, 150);
  assert.equal(fourSteps.snapshot.streakProgress, 4);

  const unlock = hitRound(engine, 2_100, 150);
  assert.equal(unlock.multiplierUsed, 1);
  assert.equal(unlock.pointsAwarded, unlock.basePointsAwarded);
  assert.equal(unlock.snapshot.multiplier, 2);
  assert.equal(unlock.snapshot.streakProgress, 1);

  const nextPerfect = hitRound(engine, 3_100, 250);
  assert.equal(nextPerfect.multiplierUsed, 2);
  assert.equal(nextPerfect.pointsAwarded, nextPerfect.basePointsAwarded * 2);
  assert.equal(nextPerfect.snapshot.streakProgress, 2);
});

test("mistakes reset the multiplier in both game modes while Normal dodges are neutral", () => {
  for (const mode of Object.values(GAME_MODES)) {
    const engine = makeEngine(() => 0);
    engine.start(0, mode);
    for (let hit = 0; hit < 3; hit += 1) {
      hitRound(engine, 100 + hit * 1_000, 150);
    }
    let missedAt = 10_100;
    if (mode === GAME_MODES.NORMAL) {
      engine.hits = Math.max(engine.hits, 4);
      const decoy = engine.activateDecoy(missedAt);
      const dodge = engine.expireDecoys(decoy.decoy.expiresAt);
      assert.equal(dodge.snapshot.multiplier, 2);
      assert.equal(dodge.snapshot.streakProgress, 1);
      missedAt = decoy.decoy.expiresAt + 10;
    }

    const miss = engine.tap(0, missedAt);
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
  for (let hit = 0; hit < 11; hit += 1) {
    result = hitRound(engine, 100 + hit * 1_900, 150);
    if ([3, 5, 8, 10].includes(hit + 1)) milestones.set(hit + 1, result);
  }

  assert.deepEqual(
    [...milestones].map(([hit, milestone]) => [
      hit,
      milestone.multiplierUsed,
      milestone.multiplierAfter
    ]),
    [
      [3, 1, 2],
      [5, 2, 3],
      [8, 3, 4],
      [10, 4, 5]
    ]
  );
  assert.equal(result.multiplierUsed, 5);
  assert.equal(result.snapshot.multiplier, 5);
  assert.equal(result.snapshot.maximumMultiplierUsed, 5);
  assert.equal(result.snapshot.streakProgress, GAME_CONFIG.streak.stepsPerMultiplier);
  assert.deepEqual(result.snapshot.multiplierHitCounts, {
    1: 3,
    2: 2,
    3: 3,
    4: 2,
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
