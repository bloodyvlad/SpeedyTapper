import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { createMusicController } from "../src/music-controller.js";

const BACKGROUND_URL = "./assets/audio/background-daylight-circuit.m4a";

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

class FakeGainNode {
  constructor() {
    this.connections = [];
    this.disconnectCalls = 0;
    this.gain = new FakeAudioParam();
  }

  connect(node) {
    this.connections.push(node);
    return node;
  }

  disconnect() {
    this.disconnectCalls += 1;
  }
}

class FakeBufferSourceNode {
  constructor() {
    this.buffer = null;
    this.connections = [];
    this.disconnectCalls = 0;
    this.loop = false;
    this.loopEnd = 0;
    this.loopStart = 0;
    this.onended = null;
    this.startCalls = [];
    this.stopCalls = [];
  }

  connect(node) {
    this.connections.push(node);
    return node;
  }

  disconnect() {
    this.disconnectCalls += 1;
  }

  start(time = 0) {
    this.startCalls.push(time);
  }

  stop(time = 0) {
    this.stopCalls.push(time);
  }
}

class FakeAudioContext {
  static decodeDuration = 12;
  static instances = [];

  constructor(options) {
    this.bufferSources = [];
    this.closeCalls = 0;
    this.currentTime = 5;
    this.decodeCalls = [];
    this.destination = { type: "destination" };
    this.gainNodes = [];
    this.listeners = new Map();
    this.options = options;
    this.resumeCalls = 0;
    this.state = "suspended";
    this.suspendCalls = 0;
    FakeAudioContext.instances.push(this);
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) ?? new Set();
    listeners.add(listener);
    this.listeners.set(type, listeners);
  }

  changeState(state) {
    this.state = state;
    for (const listener of this.listeners.get("statechange") ?? []) listener();
  }

  close() {
    this.closeCalls += 1;
    this.changeState("closed");
    return Promise.resolve();
  }

  createBufferSource() {
    const source = new FakeBufferSourceNode();
    this.bufferSources.push(source);
    return source;
  }

  createGain() {
    const gain = new FakeGainNode();
    this.gainNodes.push(gain);
    return gain;
  }

  decodeAudioData(encodedAudio) {
    this.decodeCalls.push(encodedAudio);
    return Promise.resolve({ duration: FakeAudioContext.decodeDuration, id: encodedAudio.url });
  }

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
  }

  resume() {
    this.resumeCalls += 1;
    this.changeState("running");
    return Promise.resolve();
  }

  suspend() {
    this.suspendCalls += 1;
    this.changeState("suspended");
    return Promise.resolve();
  }
}

function createImmediateFetch() {
  const calls = [];
  return {
    calls,
    fetchImpl: async (url, options = {}) => {
      calls.push({ options, url });
      return {
        ok: true,
        async arrayBuffer() {
          return { url };
        }
      };
    }
  };
}

function createDeferredFetch() {
  const calls = [];
  return {
    calls,
    fetchImpl: (url, options = {}) => new Promise((resolve) => {
      calls.push({ options, resolve, url });
    })
  };
}

function resetFakes() {
  FakeAudioContext.decodeDuration = 12;
  FakeAudioContext.instances = [];
}

async function flushAsyncWork(turns = 12) {
  for (let turn = 0; turn < turns; turn += 1) await Promise.resolve();
}

test("the music controller is one fixed original background loop with no Interactive system", async () => {
  const source = await readFile(new URL("../src/music-controller.js", import.meta.url), "utf8");

  assert.match(source, /background-daylight-circuit\.m4a/);
  assert.match(source, /latencyHint:\s*"playback"/);
  assert.match(source, /BACKGROUND_GAIN = 0\.42/);
  assert.match(source, /LOOP_DURATION_SECONDS = 12/);
  assert.doesNotMatch(source, /interactive|pace|stage|rotation|motif|HTMLAudioElement|new Audio\s*\(/i);
});

test("disabled Music performs no context, fetch, decode, or playback work", async () => {
  resetFakes();
  const fetchRecorder = createImmediateFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(false);
  await music.unlock();
  await music.startRun();
  music.stopRun();
  music.suspend();
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
});

test("enabling prepares the background silently without resuming playback", async () => {
  resetFakes();
  const fetchRecorder = createImmediateFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 1);
  const context = FakeAudioContext.instances[0];
  assert.equal(context.options.latencyHint, "playback");
  assert.equal(context.resumeCalls, 0);
  assert.equal(context.bufferSources.length, 0);
  assert.equal(context.decodeCalls.length, 1);
  assert.equal(fetchRecorder.calls.length, 1);
  assert.equal(fetchRecorder.calls[0].url, BACKGROUND_URL);
  assert.equal(fetchRecorder.calls[0].options.cache, "no-store");
  assert.ok(fetchRecorder.calls[0].options.signal);
});

test("a trusted run gesture starts one sample-aligned loop with a gentle fade", async () => {
  resetFakes();
  const fetchRecorder = createImmediateFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  await flushAsyncWork();
  assert.equal(await music.startRun(), true);

  const context = FakeAudioContext.instances[0];
  assert.equal(context.resumeCalls, 1);
  assert.equal(context.bufferSources.length, 1);
  const source = context.bufferSources[0];
  assert.equal(source.buffer.id, BACKGROUND_URL);
  assert.equal(source.loop, true);
  assert.equal(source.loopStart, 0);
  assert.equal(source.loopEnd, 12);
  assert.deepEqual(source.startCalls, [5]);
  assert.deepEqual(context.gainNodes[0].gain.events.slice(-3), [
    { method: "cancelScheduledValues", time: 5 },
    { method: "setValueAtTime", time: 5, value: 0 },
    { method: "linearRampToValueAtTime", time: 5.12, value: 1 }
  ]);
  assert.equal(context.gainNodes[1].gain.value, 0.42);
});

test("a run that starts before decoding begins the bed once it is ready", async () => {
  resetFakes();
  const fetchRecorder = createDeferredFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  assert.equal(await music.startRun(), true);
  const context = FakeAudioContext.instances[0];
  assert.equal(context.bufferSources.length, 0);

  fetchRecorder.calls[0].resolve({
    ok: true,
    async arrayBuffer() {
      return { url: BACKGROUND_URL };
    }
  });
  await flushAsyncWork();

  assert.equal(context.bufferSources.length, 1);
  assert.deepEqual(context.bufferSources[0].startCalls, [5]);
});

test("results fade the gameplay bed and a restart replaces it cleanly", async () => {
  resetFakes();
  const fetchRecorder = createImmediateFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.startRun();
  const context = FakeAudioContext.instances[0];
  const first = context.bufferSources[0];

  music.stopRun();
  assert.deepEqual(first.stopCalls, [5.09]);
  assert.deepEqual(context.gainNodes[0].gain.events.slice(-3), [
    { method: "cancelScheduledValues", time: 5 },
    { method: "setValueAtTime", time: 5, value: 1 },
    { method: "linearRampToValueAtTime", time: 5.08, value: 0 }
  ]);

  await music.startRun();
  assert.equal(context.bufferSources.length, 2);
  assert.ok(first.disconnectCalls > 0);
});

test("opting out closes Music and backgrounding suspends it", async () => {
  resetFakes();
  const fetchRecorder = createImmediateFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  music.setEnabled(true);
  await flushAsyncWork();
  await music.startRun();
  const firstContext = FakeAudioContext.instances[0];

  music.suspend();
  assert.equal(firstContext.suspendCalls, 1);
  assert.ok(firstContext.bufferSources[0].stopCalls.length > 0);

  music.setEnabled(false);
  await flushAsyncWork();
  assert.equal(firstContext.closeCalls, 1);
  const fetchCount = fetchRecorder.calls.length;
  await music.startRun();
  assert.equal(fetchRecorder.calls.length, fetchCount);
});

test("Music volume scales the louder base mix without creating disabled audio work", async () => {
  resetFakes();
  const fetchRecorder = createImmediateFetch();
  const music = createMusicController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: fetchRecorder.fetchImpl
  });

  assert.equal(music.setVolume(0.5), 0.5);
  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);

  music.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const volumeGain = context.gainNodes[1];
  assert.equal(volumeGain.gain.value, 0.21);

  await music.startRun();
  const contextCount = FakeAudioContext.instances.length;
  const fetchCount = fetchRecorder.calls.length;
  assert.equal(music.setVolume(0.25), 0.25);
  assert.deepEqual(volumeGain.gain.events.slice(-3), [
    { method: "cancelScheduledValues", time: 5 },
    { method: "setValueAtTime", time: 5, value: 0.21 },
    { method: "linearRampToValueAtTime", time: 5.025, value: 0.105 }
  ]);
  assert.equal(FakeAudioContext.instances.length, contextCount);
  assert.equal(fetchRecorder.calls.length, fetchCount);
  assert.equal(music.setVolume(2), 1);
  assert.equal(music.setVolume(-1), 0);
});
