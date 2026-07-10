import assert from "node:assert/strict";
import test from "node:test";

import { GAME_CONFIG } from "../src/config.js";
import {
  GameEngine,
  GAME_STATES,
  orthogonalNeighbors,
  resolveDifficulty,
  scoreReaction
} from "../src/game-engine.js";

function makeEngine() {
  return new GameEngine({ random: () => 0 });
}

function hitRound(engine, activeAt, reactionMs = 50) {
  const active = engine.activateRound(activeAt);
  assert.equal(active.type, "round-active");
  return engine.tap(active.snapshot.targetIndex, activeAt + reactionMs);
}

test("warm-up begins with one cell, no decoys, and a 1000 ms response window", () => {
  const engine = makeEngine();
  engine.start(0);
  const result = engine.activateRound(100);

  assert.equal(result.snapshot.difficulty.gridDimension, 1);
  assert.equal(result.snapshot.difficulty.responseWindowMs, 1_000);
  assert.equal(result.snapshot.cells.filter((cell) => cell.kind === "target").length, 1);
  assert.equal(result.snapshot.cells.filter((cell) => cell.kind === "decoy").length, 0);
});

test("the board grows to 2x2 after four successful taps and 4x4 after twelve", () => {
  const engine = makeEngine();
  engine.start(0);

  for (let hit = 0; hit < 4; hit += 1) hitRound(engine, 100 + hit * 100);
  assert.equal(engine.getSnapshot(900).difficulty.gridDimension, 2);

  for (let hit = 4; hit < 12; hit += 1) hitRound(engine, 1_000 + hit * 100);
  assert.equal(engine.getSnapshot(3_000).difficulty.gridDimension, 4);
});

test("color choice begins after ten seconds and a correct tap selects a new player color", () => {
  const engine = makeEngine();
  engine.start(0);
  for (let hit = 0; hit < 4; hit += 1) hitRound(engine, 100 + hit * 100);

  const oldColor = engine.playerColorIndex;
  const active = engine.activateRound(10_100);
  assert.equal(active.snapshot.difficulty.usesColorChoice, true);
  assert.equal(active.snapshot.difficulty.responseWindowMs, 500);
  assert.equal(active.snapshot.cells.filter((cell) => cell.kind === "decoy").length, 1);

  const result = engine.tap(active.snapshot.targetIndex, 10_150);
  assert.equal(result.type, "hit");
  assert.notEqual(result.snapshot.playerColorIndex, oldColor);
});

test("at least one decoy is orthogonally adjacent to the correct cell", () => {
  const engine = makeEngine();
  engine.start(0);
  for (let hit = 0; hit < 12; hit += 1) hitRound(engine, 100 + hit * 100);

  const active = engine.activateRound(12_000);
  const target = active.snapshot.targetIndex;
  const neighbors = orthogonalNeighbors(target, 4);
  assert.equal(
    neighbors.some((index) => active.snapshot.cells[index].kind === "decoy"),
    true
  );
});

test("faster reactions award more points", () => {
  assert.ok(scoreReaction(40, 500) > scoreReaction(250, 500));
  assert.ok(scoreReaction(250, 500) > scoreReaction(450, 500));
  assert.equal(scoreReaction(500, 500), GAME_CONFIG.scoreFloor);
});

test("wrong taps and expired rounds each cost exactly one life", () => {
  const engine = makeEngine();
  engine.start(0);

  let active = engine.activateRound(10_000);
  const wrongIndex = active.snapshot.targetIndex === 0 ? 1 : 0;
  let result = engine.tap(wrongIndex, 10_050);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "wrong");
  assert.equal(result.snapshot.lives, 2);

  active = engine.activateRound(11_000);
  result = engine.expireRound(11_000 + active.snapshot.difficulty.responseWindowMs);
  assert.equal(result.type, "miss");
  assert.equal(result.reason, "late");
  assert.equal(result.snapshot.lives, 1);
});

test("the third mistake ends the game", () => {
  const engine = makeEngine();
  engine.start(0);

  for (let miss = 0; miss < 3; miss += 1) {
    const active = engine.activateRound(10_000 + miss * 1_000);
    engine.expireRound(10_000 + miss * 1_000 + active.snapshot.difficulty.responseWindowMs);
  }

  assert.equal(engine.state, GAME_STATES.GAME_OVER);
  assert.equal(engine.lives, 0);
});

test("the aggressive phase bottoms out at a 100 ms response window", () => {
  assert.equal(resolveDifficulty(12, 20_000).responseWindowMs, 300);
  assert.equal(resolveDifficulty(28, 20_000).responseWindowMs, 200);
  assert.equal(resolveDifficulty(44, 20_000).responseWindowMs, 100);
  assert.equal(resolveDifficulty(500, 20_000).responseWindowMs, 100);
});
