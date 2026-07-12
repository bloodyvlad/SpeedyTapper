import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { createMusicController, MUSIC_STAGES } from "../src/music-controller.js";

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
    return Promise.resolve({ duration: 39.957, encoded });
  }

  resume() {
    this.resumeCalls += 1;
    this.state = "running";
    return Promise.resolve();
  }

  suspend() {
    this.suspendCalls += 1;
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
  assert.equal(await music.unlock(), false);
  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
});

test("music loops and crossfades the regions for each game stage", async () => {
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
  assert.equal(fetchRecorder.calls.length, 1);
  assert.match(fetchRecorder.calls[0].url, /neon-circuit-v1\.m4a$/);
  assert.equal(context.options.latencyHint, "playback");

  const menuSource = context.bufferSources[0];
  assert.equal(menuSource.loop, true);
  assert.equal(menuSource.loopStart, 0);
  assert.equal(menuSource.loopEnd, 640_000 / 48_000);
  assert.deepEqual(menuSource.startCalls[0], [5, 0]);

  music.setStage(MUSIC_STAGES.GRID_2);
  const gridSource = context.bufferSources[1];
  assert.equal(gridSource.loopStart, 640_000 / 48_000);
  assert.equal(gridSource.loopEnd, 1_163_636 / 48_000);
  assert.deepEqual(gridSource.startCalls[0], [5, 640_000 / 48_000]);
  assert.equal(menuSource.stopCalls.length, 1);

  music.setStage(MUSIC_STAGES.GRID_4);
  const fourByFourSource = context.bufferSources[2];
  assert.equal(fourByFourSource.loopStart, 1_163_636 / 48_000);

  music.setStage(MUSIC_STAGES.CHALLENGE);
  const challengeSource = context.bufferSources[3];
  assert.equal(challengeSource.loopStart, 1_643_636 / 48_000);
  assert.equal(challengeSource.loopEnd, 1_917_922 / 48_000);
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
      { method: "setValueAtTime", value: 1 },
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

test("the retained PCM master is silent at every adaptive loop seam", async () => {
  const wav = await readFile(
    new URL("../assets/audio/music-previews/01-neon-circuit-clicksafe-v4.wav", import.meta.url)
  );
  const dataOffset = wav.indexOf(Buffer.from("data"));
  assert.ok(dataOffset >= 0, "The music master must contain a PCM data chunk.");
  const frameData = wav.subarray(dataOffset + 8);
  const sampleAt = (frame, channel) => frameData.readInt16LE((frame * 2 + channel) * 2) / 32_768;
  const boundaries = [0, 640_000, 1_163_636, 1_643_636, 1_917_922];

  for (let index = 0; index < boundaries.length - 1; index += 1) {
    const start = boundaries[index];
    const end = boundaries[index + 1];
    const jump = Math.max(
      Math.abs(sampleAt(end - 1, 0) - sampleAt(start, 0)),
      Math.abs(sampleAt(end - 1, 1) - sampleAt(start, 1))
    );
    assert.ok(jump <= 1 / 32_768, `Loop ${index} must have a near-zero PCM boundary.`);
  }
});
