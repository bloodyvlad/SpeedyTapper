import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  createMusicController,
  MUSIC_STAGES,
  MUSIC_TRACKS,
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
