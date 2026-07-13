import assert from "node:assert/strict";
import test from "node:test";

import { GAME_MODES } from "../src/config.js";
import { GameEngine } from "../src/game-engine.js";
import {
  predatesPresentation,
  reactionDeadline,
  reachedDeadline,
  remainingUntilDeadline,
  resolveInputTimestamp,
  scheduleAfterPaint,
  wasCoveredByDeadlineResolution
} from "../src/input-timing.js";

test("pointer timestamps preserve contact time instead of handler dispatch time", () => {
  assert.equal(resolveInputTimestamp(1_025.4, 1_041.8), 1_025.4);
  assert.equal(resolveInputTimestamp(0, 1_041.8), 1_041.8);
  assert.equal(resolveInputTimestamp(Number.NaN, 1_041.8), 1_041.8);
  assert.equal(resolveInputTimestamp(Date.now(), 1_041.8), 1_041.8);
  assert.equal(resolveInputTimestamp(1_043, 1_041.8), 1_041.8);
  assert.equal(resolveInputTimestamp(10, 70_011), 70_011);
});

test("reaction deadlines remain anchored to the presentation frame", () => {
  const deadlineAt = reactionDeadline(2_000.25, 200);
  assert.equal(deadlineAt, 2_200.25);
  assert.equal(remainingUntilDeadline(deadlineAt, 2_017.7), 183);
  assert.equal(remainingUntilDeadline(deadlineAt, 2_201), 0);
  assert.equal(reachedDeadline(2_200.249, deadlineAt), false);
  assert.equal(reachedDeadline(2_200.25, deadlineAt), true);
});

test("queued pre-presentation and already-resolved inputs are identifiable", () => {
  assert.equal(predatesPresentation(999.9, 1_000), true);
  assert.equal(predatesPresentation(1_000, 1_000), false);
  assert.equal(wasCoveredByDeadlineResolution(1_199, 1_200), true);
  assert.equal(wasCoveredByDeadlineResolution(1_200, 1_200), true);
  assert.equal(wasCoveredByDeadlineResolution(1_201, 1_200), false);
});

test("the engine reports visual-frame to pointer-contact time despite delayed dispatch", () => {
  const engine = new GameEngine({ random: () => 0 });
  engine.start(500);
  const active = engine.activateRound(1_000);
  const pointerContactAt = resolveInputTimestamp(1_123.4, 1_141.9);
  const hit = engine.tap(active.snapshot.targetIndex, pointerContactAt);

  assert.equal(hit.type, "hit");
  assert.ok(Math.abs(hit.reactionMs - 123.4) < 0.000001);
  assert.equal(Math.round(hit.reactionMs), 123);
});

test("pending expiry honors an on-time contact and commits only one outcome", () => {
  const engine = new GameEngine({ random: () => 0 });
  engine.start(0);
  const active = engine.activateRound(1_000);
  const deadlineAt = reactionDeadline(1_000, active.snapshot.difficulty.responseWindowMs);

  const hit = engine.tap(active.snapshot.targetIndex, deadlineAt - 0.1);
  const staleExpiry = engine.expireRound(deadlineAt + 16);
  assert.equal(hit.type, "hit");
  assert.equal(staleExpiry.type, "ignored");
  assert.equal(engine.hits, 1);
  assert.equal(engine.misses, 0);
});

test("timer-first expiry waits through paint so a queued on-time pointer can win", () => {
  const frames = [];
  const cancelled = new Set();
  let nextFrameId = 0;
  const scheduler = {
    requestFrame(callback) {
      nextFrameId += 1;
      const id = nextFrameId;
      frames.push({ callback, id });
      return id;
    },
    cancelFrame(id) {
      cancelled.add(id);
    }
  };
  const engine = new GameEngine({ random: () => 0 });
  engine.start(0);
  const active = engine.activateRound(1_000);
  const deadlineAt = reactionDeadline(1_000, active.snapshot.difficulty.responseWindowMs);
  let expiryCommitted = false;
  const pendingExpiry = scheduleAfterPaint(scheduler, () => {
    expiryCommitted = true;
    engine.expireRound(deadlineAt + 16);
  });

  const firstFrame = frames.shift();
  firstFrame.callback();
  const pointer = engine.tap(active.snapshot.targetIndex, deadlineAt - 0.1);
  pendingExpiry.cancel();
  for (const frame of frames) {
    if (!cancelled.has(frame.id)) frame.callback();
  }

  assert.equal(pointer.type, "hit");
  assert.equal(expiryCommitted, false);
  assert.equal(engine.hits, 1);
  assert.equal(engine.misses, 0);
});

test("after-paint expiry commits when no pointer resolves the round", () => {
  const frames = [];
  let nextFrameId = 0;
  const scheduler = {
    requestFrame(callback) {
      nextFrameId += 1;
      const id = nextFrameId;
      frames.push({ callback, id });
      return id;
    },
    cancelFrame() {}
  };
  let committed = 0;
  scheduleAfterPaint(scheduler, () => {
    committed += 1;
  });
  while (frames.length > 0) frames.shift().callback();
  assert.equal(committed, 1);
});

test("an exact-deadline contact is late and cannot be charged twice", () => {
  const engine = new GameEngine({ random: () => 0 });
  engine.start(0);
  const active = engine.activateRound(1_000);
  const deadlineAt = reactionDeadline(1_000, active.snapshot.difficulty.responseWindowMs);

  const miss = engine.tap(active.snapshot.targetIndex, deadlineAt);
  const staleExpiry = engine.expireRound(deadlineAt + 16);
  assert.equal(miss.type, "miss");
  assert.equal(miss.reason, "late");
  assert.equal(staleExpiry.type, "ignored");
  assert.equal(engine.misses, 1);
});

test("Zen input at or after three minutes finalizes without adding points", () => {
  const engine = new GameEngine({ random: () => 0 });
  const startedAt = 1_000;
  const runDeadlineAt = startedAt + engine.config.zenDurationMs;
  engine.start(startedAt, GAME_MODES.ZEN);
  engine.activateRound(runDeadlineAt - 100);
  const inputAt = runDeadlineAt;
  const result = reachedDeadline(inputAt, runDeadlineAt)
    ? engine.finishTimedRun(inputAt)
    : engine.tap(0, inputAt);

  assert.equal(result.type, "time-up");
  assert.equal(result.snapshot.points, 0);
  assert.equal(result.snapshot.elapsedMs, engine.config.zenDurationMs);
});
