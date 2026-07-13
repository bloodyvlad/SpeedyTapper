import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  createMusicController,
  INTERACTIVE_MUSIC_SECTIONS,
  INTERACTIVE_MUSIC_TRACKS,
  INTERACTIVE_MUSIC_TRANSITIONS,
  MUSIC_STAGES,
  MUSIC_TRACKS,
  resolveInteractiveMusicSection,
  resolveMusicStage
} from "../src/music-controller.js";

class FakeAudioParam {
  constructor(value = 0) {
    this.value = value;
    this.events = [];
  }

  cancelScheduledValues(time) {
    this.events.push({ method: "cancelScheduledValues", time });
  }

  linearRampToValueAtTime(value, time) {
    this.value = value;
    this.events.push({ method: "linearRampToValueAtTime", time, value });
  }

  setValueAtTime(value, time) {
    this.value = value;
    this.events.push({ method: "setValueAtTime", time, value });
  }
}

class FakeGain {
  constructor() {
    this.gain = new FakeAudioParam();
    this.connections = [];
  }

  connect(node) {
    this.connections.push(node);
  }

  disconnect() {}
}

class FakeSource {
  constructor() {
    this.connections = [];
    this.loop = false;
    this.loopEnd = 0;
    this.loopStart = 0;
    this.startCalls = [];
    this.stopCalls = [];
  }

  connect(node) {
    this.connections.push(node);
  }

  disconnect() {}

  start(...args) {
    this.startCalls.push(args);
  }

  stop(time = 0) {
    this.stopCalls.push(time);
  }
}

class FakeAudioContext {
  static instances = [];
  static deferResume = false;
  static deferSuspend = false;

  constructor(options) {
    this.bufferSources = [];
    this.closeCalls = 0;
    this.currentTime = 5;
    this.destination = {};
    this.gains = [];
    this.options = options;
    this.resumeCalls = 0;
    this.state = "suspended";
    this.suspendCalls = 0;
    FakeAudioContext.instances.push(this);
  }

  close() {
    this.closeCalls += 1;
    this.state = "closed";
    return Promise.resolve();
  }

  createBufferSource() {
    const source = new FakeSource();
    this.bufferSources.push(source);
    return source;
  }

  createGain() {
    const gain = new FakeGain();
    this.gains.push(gain);
    return gain;
  }

  decodeAudioData(encoded) {
    return Promise.resolve({ duration: 30.172, encoded });
  }

  resume() {
    this.resumeCalls += 1;
    if (FakeAudioContext.deferResume) {
      return new Promise((resolve) => {
        this.resolveResume = () => {
          this.state = "running";
          resolve();
        };
      });
    }
    this.state = "running";
    return Promise.resolve();
  }

  suspend() {
    this.suspendCalls += 1;
    if (FakeAudioContext.deferSuspend) {
      return new Promise((resolve) => {
        this.resolveSuspend = () => {
          this.state = "suspended";
          resolve();
        };
      });
    }
    this.state = "suspended";
    return Promise.resolve();
  }
}

class DeferredDecodeAudioContext extends FakeAudioContext {
  constructor(options) {
    super(options);
    this.pendingDecodes = [];
  }

  decodeAudioData(encoded) {
    return new Promise((resolve) => {
      this.pendingDecodes.push({
        encoded,
        resolve: () => resolve({ duration: 107.44, encoded })
      });
    });
  }
}

class ManualScheduler {
  constructor() {
    this.jobs = [];
  }

  clearTimeout(id) {
    const job = this.jobs.find((candidate) => candidate.id === id);
    if (job) job.cancelled = true;
  }

  runAll() {
    for (const job of this.jobs.splice(0)) {
      if (!job.cancelled) job.callback();
    }
  }

  setTimeout(callback, delay) {
    const id = this.jobs.length + 1;
    this.jobs.push({ callback, cancelled: false, delay, id });
    return id;
  }
}

function createFetchRecorder() {
  const calls = [];
  return {
    calls,
    fetchImpl(url, options) {
      calls.push({ options, url });
      return Promise.resolve({
        ok: true,
        arrayBuffer: () => Promise.resolve({ url })
      });
    }
  };
}

async function flushAsyncWork(turns = 8) {
  for (let turn = 0; turn < turns; turn += 1) await Promise.resolve();
}

test("disabled music creates no context or network work", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setStage(MUSIC_STAGES.GRID_2);
  music.advanceTrack();
  assert.equal(await music.unlock(), false);
  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
});

test("music preloads three tracks, loops their regions, and rotates atomically", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  assert.equal(fetchRecorder.calls.length, 3);
  assert.deepEqual(
    fetchRecorder.calls.map(({ url }) => url),
    MUSIC_TRACKS.map(({ file }) => file)
  );
  assert.equal(context.options.latencyHint, "playback");

  const menuSource = context.bufferSources[0];
  assert.equal(menuSource.loop, true);
  assert.equal(menuSource.loopStart, 0);
  assert.equal(menuSource.loopEnd, 460_800 / 48_000);
  assert.deepEqual(menuSource.startCalls[0], [5, 0]);
  assert.match(menuSource.buffer.encoded.url, /neon-circuit-refined\.m4a$/);

  music.setStage(MUSIC_STAGES.GRID_2);
  const gridSource = context.bufferSources[1];
  assert.equal(gridSource.loopStart, 460_800 / 48_000);
  assert.equal(gridSource.loopEnd, 844_800 / 48_000);
  assert.deepEqual(gridSource.startCalls[0], [5, 460_800 / 48_000]);
  assert.equal(menuSource.stopCalls.length, 1);

  music.setStage(MUSIC_STAGES.GRID_4);
  const fourByFourSource = context.bufferSources[2];
  assert.equal(fourByFourSource.loopStart, 844_800 / 48_000);

  music.setStage(MUSIC_STAGES.CHALLENGE);
  const challengeSource = context.bufferSources[3];
  assert.equal(challengeSource.loopStart, 1_173_943 / 48_000);
  assert.equal(challengeSource.loopEnd, 1_448_208 / 48_000);

  assert.equal(music.advanceTrack(MUSIC_STAGES.MENU), "deep-current");
  const deepCurrentMenu = context.bufferSources[4];
  assert.equal(deepCurrentMenu.loopStart, 0);
  assert.match(deepCurrentMenu.buffer.encoded.url, /deep-current\.m4a$/);
  assert.equal(context.bufferSources.length, 5, "Rotation must create only one new menu voice.");

  assert.equal(music.advanceTrack(MUSIC_STAGES.MENU), "power-grid");
  assert.match(context.bufferSources[5].buffer.encoded.url, /power-grid\.m4a$/);
  assert.equal(music.advanceTrack(MUSIC_STAGES.MENU), "neon-circuit-refined");
  assert.match(context.bufferSources[6].buffer.encoded.url, /neon-circuit-refined\.m4a$/);
});

test("music stages hold early 4x4 at 120 BPM and reserve overdrive for two minutes", () => {
  const timing = { fourByFourPressure: 90_000, endurance: 120_000 };
  const snapshot = (gridDimension, elapsedMs) => ({ difficulty: { gridDimension }, elapsedMs });

  assert.equal(resolveMusicStage(snapshot(1, 0), timing), MUSIC_STAGES.MENU);
  assert.equal(resolveMusicStage(snapshot(2, 10_000), timing), MUSIC_STAGES.GRID_2);
  assert.equal(resolveMusicStage(snapshot(4, 89_999), timing), MUSIC_STAGES.GRID_2);
  assert.equal(resolveMusicStage(snapshot(4, 90_000), timing), MUSIC_STAGES.GRID_4);
  assert.equal(resolveMusicStage(snapshot(4, 119_999), timing), MUSIC_STAGES.GRID_4);
  assert.equal(resolveMusicStage(snapshot(4, 120_000), timing), MUSIC_STAGES.CHALLENGE);
});

test("Interactive Music follows grid state and the engine timing opportunity", () => {
  const snapshot = (gridDimension, elapsedMs, overrides = {}) => ({
    elapsedMs,
    difficulty: {
      gridDimension,
      phaseId: gridDimension === 4 ? "four-by-four-challenge" : "warmup",
      responseWindowMs: 1_000,
      spawnDelayRangeMs: [425, 825],
      ...overrides
    }
  });

  assert.equal(resolveInteractiveMusicSection(snapshot(1, 0)), 0);
  assert.equal(resolveInteractiveMusicSection(snapshot(2, 10_000)), 1);
  assert.equal(resolveInteractiveMusicSection(snapshot(2, 20_000)), 2);
  assert.equal(resolveInteractiveMusicSection(snapshot(2, 30_000)), 3);
  assert.equal(
    resolveInteractiveMusicSection(snapshot(4, 40_000, { phaseId: "four-by-four-reset" })),
    4
  );
  assert.equal(resolveInteractiveMusicSection(snapshot(4, 50_000)), 5);
  assert.equal(
    resolveInteractiveMusicSection(snapshot(4, 90_000, {
      responseWindowMs: 360,
      spawnDelayRangeMs: [335, 675]
    })),
    8
  );
  assert.equal(
    resolveInteractiveMusicSection(snapshot(4, 120_000, {
      responseWindowMs: 200,
      spawnDelayRangeMs: [305, 625]
    })),
    11
  );
});

test("Interactive Music is opt-in and loads only one backing and note bank", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setInteractive(true);
  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
  music.setEnabled(true);
  await flushAsyncWork();
  assert.deepEqual(
    fetchRecorder.calls.map(({ url }) => url),
    [
      INTERACTIVE_MUSIC_TRACKS[0].backingFile,
      INTERACTIVE_MUSIC_TRACKS[0].notesFile
    ]
  );

  await music.unlock();
  const context = FakeAudioContext.instances[0];
  assert.equal(context.options.latencyHint, "interactive");
  const opening = INTERACTIVE_MUSIC_SECTIONS[0];
  const backingSource = context.bufferSources[0];
  assert.equal(backingSource.loop, true);
  assert.equal(backingSource.loopStart, opening.offsetFrames / 48_000);
  assert.equal(
    backingSource.loopEnd,
    (opening.offsetFrames + opening.durationFrames) / 48_000
  );

  assert.equal(music.playCorrectTap(1), true);
  assert.deepEqual(context.bufferSources[1].startCalls[0], [5, 0, 0.5]);
  assert.equal(music.playCorrectTap(2), true);
  assert.deepEqual(context.bufferSources[2].startCalls[0], [5, 1.5, 0.5]);
});

test("Interactive backing changes once on the next beat through an authored bridge", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setInteractive(true);
  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  const openingSource = context.bufferSources[0];

  music.setInteractiveSection(1);
  music.setInteractiveSection(1);
  const bridge = INTERACTIVE_MUSIC_TRANSITIONS[0];
  assert.equal(context.bufferSources.length, 3, "Repeated snapshots must not duplicate transitions.");
  assert.deepEqual(context.bufferSources[1].startCalls[0], [
    5.6,
    bridge.offsetFrames / 48_000,
    bridge.durationFrames / 48_000
  ]);
  assert.ok(Math.abs(openingSource.stopCalls[0] - 5.626) < 0.000001);
  assert.deepEqual(
    context.bufferSources[1].connections[0].gain.events.slice(0, 2),
    [
      { method: "setValueAtTime", time: 5.6, value: 0 },
      { method: "linearRampToValueAtTime", time: 5.624, value: 1 }
    ]
  );
  const targetStart = 5.6 + bridge.durationFrames / 48_000;
  assert.deepEqual(context.bufferSources[2].startCalls[0], [
    targetStart,
    INTERACTIVE_MUSIC_SECTIONS[1].offsetFrames / 48_000
  ]);
});

test("Interactive tap notes skip while unready and cap overlapping voices", async () => {
  FakeAudioContext.instances = [];
  const pending = [];
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl(url) {
      return new Promise((resolve) => pending.push({ resolve, url }));
    }
  });

  music.setInteractive(true);
  music.setEnabled(true);
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  assert.equal(music.playCorrectTap(1), false);
  assert.equal(context.bufferSources.length, 0);

  for (const item of pending) {
    item.resolve({ ok: true, arrayBuffer: () => Promise.resolve({ url: item.url }) });
  }
  await flushAsyncWork();
  assert.equal(context.bufferSources.length, 1, "Readiness must not replay the skipped note.");
  for (let hit = 1; hit <= 5; hit += 1) assert.equal(music.playCorrectTap(hit), true);
  assert.equal(context.bufferSources.length, 6);
  assert.ok(
    context.bufferSources.slice(1, 5).some((source) => source.stopCalls.length === 1),
    "The fifth simultaneous note must fade and stop the oldest voice."
  );
});

test("switching soundtrack variants replaces the context and preserves the track index", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  assert.equal(music.advanceTrack(), "deep-current");
  const legacyContext = FakeAudioContext.instances[0];
  legacyContext.currentTime = 10;
  music.setInteractive(true);
  const legacyGainEventCount = legacyContext.bufferSources[1].connections[0].gain.events.length;
  await flushAsyncWork();
  await music.unlock();
  const interactiveContext = FakeAudioContext.instances[1];
  assert.equal(interactiveContext.options.latencyHint, "interactive");
  assert.match(interactiveContext.bufferSources[0].buffer.encoded.url, /interactive-deep-current\.m4a$/);
  assert.equal(
    legacyContext.bufferSources[1].connections[0].gain.events.length,
    legacyGainEventCount,
    "The replacement context must not reschedule gain events on a fading legacy voice."
  );
  scheduler.runAll();
  assert.equal(legacyContext.closeCalls, 1);
});

test("Interactive track rotation releases the old sprite and loads only the next pair", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setInteractive(true);
  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  const oldBacking = context.bufferSources[0];

  assert.equal(music.advanceTrack(), "deep-current");
  await flushAsyncWork();
  assert.deepEqual(
    fetchRecorder.calls.slice(-2).map(({ url }) => url),
    [
      INTERACTIVE_MUSIC_TRACKS[1].backingFile,
      INTERACTIVE_MUSIC_TRACKS[1].notesFile
    ]
  );
  assert.equal(oldBacking.stopCalls.length, 1);
  assert.match(context.bufferSources[1].buffer.encoded.url, /interactive-deep-current\.m4a$/);
  assert.equal(music.playCorrectTap(1), true);
  assert.match(context.bufferSources[2].buffer.encoded.url, /interactive-notes-deep-current\.wav$/);
});

test("Interactive track rotation ignores stale decodes from the previous track", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: DeferredDecodeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setInteractive(true);
  music.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  assert.equal(context.pendingDecodes.length, 2);

  assert.equal(music.advanceTrack(), "deep-current");
  await flushAsyncWork();
  assert.equal(context.pendingDecodes.length, 4);

  context.pendingDecodes[2].resolve();
  context.pendingDecodes[3].resolve();
  await flushAsyncWork();
  await music.unlock();
  assert.match(context.bufferSources[0].buffer.encoded.url, /interactive-deep-current\.m4a$/);

  context.pendingDecodes[0].resolve();
  context.pendingDecodes[1].resolve();
  await flushAsyncWork();
  assert.equal(music.playCorrectTap(1), true);
  assert.match(
    context.bufferSources[1].buffer.encoded.url,
    /interactive-notes-deep-current\.wav$/
  );

  music.setInteractiveSection(1);
  assert.equal(context.bufferSources.length, 4);
  assert.match(context.bufferSources[2].buffer.encoded.url, /interactive-deep-current\.m4a$/);
  assert.match(context.bufferSources[3].buffer.encoded.url, /interactive-deep-current\.m4a$/);
});

test("Interactive rotation preserves an in-flight gesture-authorized resume", async () => {
  FakeAudioContext.instances = [];
  FakeAudioContext.deferResume = true;
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  try {
    music.setInteractive(true);
    music.setEnabled(true);
    await flushAsyncWork();
    const context = FakeAudioContext.instances[0];
    const unlockWork = music.unlock();
    assert.equal(context.resumeCalls, 1);
    assert.equal(context.state, "suspended");

    assert.equal(music.advanceTrack(), "deep-current");
    await flushAsyncWork();
    assert.equal(context.bufferSources.length, 0);

    context.resolveResume();
    assert.equal(await unlockWork, true);
    assert.equal(context.bufferSources.length, 1);
    assert.match(context.bufferSources[0].buffer.encoded.url, /interactive-deep-current\.m4a$/);
  } finally {
    FakeAudioContext.deferResume = false;
  }
});

test("suspending Interactive Music cancels scheduled bridges and tap voices", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setInteractive(true);
  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  assert.equal(music.playCorrectTap(1), true);
  music.setInteractiveSection(1);
  const sources = [...context.bufferSources];

  music.suspend();
  assert.ok(sources.every((source) => source.stopCalls.length >= 1));
  scheduler.runAll();
  await flushAsyncWork();
  assert.equal(context.suspendCalls, 1);
});

test("rotation silences stale gameplay music until a delayed next track is ready", async () => {
  FakeAudioContext.instances = [];
  const pending = [];
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl(url) {
      return new Promise((resolve) => pending.push({ resolve, url }));
    }
  });

  music.setEnabled(true);
  assert.equal(pending.length, 3);
  pending[0].resolve({
    ok: true,
    arrayBuffer: () => Promise.resolve({ url: pending[0].url })
  });
  await flushAsyncWork();
  await music.unlock();
  music.setStage(MUSIC_STAGES.CHALLENGE);
  const context = FakeAudioContext.instances[0];
  const staleChallenge = context.bufferSources[1];

  music.advanceTrack(MUSIC_STAGES.MENU);
  assert.equal(context.bufferSources.length, 2);
  assert.equal(staleChallenge.stopCalls.length, 1);

  pending[1].resolve({
    ok: true,
    arrayBuffer: () => Promise.resolve({ url: pending[1].url })
  });
  await flushAsyncWork();
  assert.equal(context.bufferSources.length, 3);
  assert.equal(context.bufferSources[2].loopStart, 0);
  assert.match(context.bufferSources[2].buffer.encoded.url, /deep-current\.m4a$/);
});

test("suspending fades active music before the context is paused", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  const source = context.bufferSources[0];

  music.suspend();
  assert.equal(source.stopCalls.length, 1);
  assert.ok(source.stopCalls[0] > context.currentTime);
  const voiceGain = source.connections[0].gain;
  assert.deepEqual(
    voiceGain.events.slice(-3).map(({ method, value }) => ({ method, value })),
    [
      { method: "cancelScheduledValues", value: undefined },
      { method: "setValueAtTime", value: 0 },
      { method: "linearRampToValueAtTime", value: 0 }
    ]
  );
  assert.equal(context.suspendCalls, 0);
  scheduler.runAll();
  await flushAsyncWork();
  assert.equal(context.suspendCalls, 1);
});

test("disabling fades a running voice before closing its context", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  const source = context.bufferSources[0];

  music.setEnabled(false);
  assert.ok(source.stopCalls[0] > context.currentTime);
  assert.equal(context.closeCalls, 0);
  scheduler.runAll();
  assert.equal(context.closeCalls, 1);
});

test("unlocking during the release fade cancels a stale suspension", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  music.suspend();
  await music.unlock();
  scheduler.runAll();
  await flushAsyncWork();

  assert.equal(context.suspendCalls, 0);
  assert.equal(context.state, "running");
  assert.equal(context.bufferSources.length, 2);
});

test("a gesture replaces a context whose suspension is already in flight", async () => {
  FakeAudioContext.instances = [];
  FakeAudioContext.deferSuspend = true;
  const fetchRecorder = createFetchRecorder();
  const scheduler = new ManualScheduler();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl,
    setTimeoutImpl: scheduler.setTimeout.bind(scheduler),
    clearTimeoutImpl: scheduler.clearTimeout.bind(scheduler)
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const oldContext = FakeAudioContext.instances[0];
  music.suspend();
  scheduler.runAll();
  assert.equal(oldContext.suspendCalls, 1);

  await music.unlock();
  const replacementContext = FakeAudioContext.instances[1];
  assert.ok(replacementContext, "The newer gesture must own a replacement context.");
  assert.equal(replacementContext.state, "running");
  oldContext.resolveSuspend();
  await flushAsyncWork();
  scheduler.runAll();
  assert.equal(replacementContext.state, "running");
  assert.equal(oldContext.closeCalls, 1);
  assert.equal(replacementContext.bufferSources.length, 1);
  FakeAudioContext.deferSuspend = false;
});

test("an interrupted crossfade holds its computed gain without jumping to full volume", async () => {
  FakeAudioContext.instances = [];
  const fetchRecorder = createFetchRecorder();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.unlock();
  const context = FakeAudioContext.instances[0];
  const menuSource = context.bufferSources[0];
  context.currentTime = 5.06;
  music.setStage(MUSIC_STAGES.GRID_2);
  const menuHold = menuSource.connections[0].gain.events.findLast(
    (event) => event.method === "setValueAtTime" && event.time === 5.06
  );
  assert.ok(Math.abs(menuHold.value - 0.5) < 0.000001);

  const gridSource = context.bufferSources[1];
  context.currentTime = 5.09;
  music.advanceTrack(MUSIC_STAGES.MENU);
  const gridHold = gridSource.connections[0].gain.events.findLast(
    (event) => event.method === "setValueAtTime" && event.time === 5.09
  );
  assert.ok(Math.abs(gridHold.value - 0.25) < 0.000001);
});

test("all retained production masters are silent at every adaptive loop seam", async () => {
  const filenames = ["neon-circuit-refined.wav", "deep-current.wav", "power-grid.wav"];
  const boundaries = [0, 460_800, 844_800, 1_173_943, 1_448_229];

  for (const filename of filenames) {
    const wav = await readFile(
      new URL(`../assets/audio/music-masters/${filename}`, import.meta.url)
    );
    const dataOffset = wav.indexOf(Buffer.from("data"));
    assert.ok(dataOffset >= 0, `${filename} must contain a PCM data chunk.`);
    const frameData = wav.subarray(dataOffset + 8);
    const sampleAt = (frame, channel) =>
      frameData.readInt16LE((frame * 2 + channel) * 2) / 32_768;

    for (let index = 0; index < boundaries.length - 1; index += 1) {
      const start = boundaries[index];
      const end = boundaries[index + 1];
      const jump = Math.max(
        Math.abs(sampleAt(end - 1, 0) - sampleAt(start, 0)),
        Math.abs(sampleAt(end - 1, 1) - sampleAt(start, 1))
      );
      assert.ok(jump <= 1 / 32_768, `${filename} loop ${index} must be near zero.`);
    }
  }
});

test("Interactive Music manifest matches runtime cue metadata and PCM note boundaries", async () => {
  const manifest = JSON.parse(
    await readFile(
      new URL("../assets/audio/interactive-music-masters/manifest.json", import.meta.url),
      "utf8"
    )
  );
  assert.equal(manifest.sampleRate, 48_000);
  assert.deepEqual(
    INTERACTIVE_MUSIC_SECTIONS.map(({ id, bpm, beatFrames, offsetFrames, durationFrames }) => ({
      id,
      bpm,
      beatFrames,
      offsetFrames,
      durationFrames
    })),
    manifest.sections.map(({ id, bpm, beatFrames, offsetFrames, durationFrames }) => ({
      id,
      bpm,
      beatFrames,
      offsetFrames,
      durationFrames
    }))
  );
  assert.deepEqual(
    INTERACTIVE_MUSIC_TRANSITIONS,
    manifest.transitions.map(({ from, to, offsetFrames, durationFrames }) => ({
      from,
      to,
      offsetFrames,
      durationFrames
    }))
  );

  for (const track of INTERACTIVE_MUSIC_TRACKS) {
    assert.deepEqual(track.motif, manifest.tracks[track.id].motif);
    const wav = await readFile(new URL(`../${track.notesFile.slice(2)}`, import.meta.url));
    assert.equal(wav.readUInt16LE(22), 1, `${track.id} note bank must be mono.`);
    assert.equal(wav.readUInt32LE(24), 48_000, `${track.id} note bank must be 48 kHz.`);
    assert.equal(wav.readUInt16LE(34), 16, `${track.id} note bank must be 16-bit PCM.`);
    const dataOffset = wav.indexOf(Buffer.from("data"));
    const samples = wav.subarray(dataOffset + 8);
    for (let slot = 0; slot < 6; slot += 1) {
      assert.equal(samples.readInt16LE(slot * 24_000 * 2), 0);
      assert.equal(samples.readInt16LE(((slot + 1) * 24_000 - 1) * 2), 0);
    }
  }
});
