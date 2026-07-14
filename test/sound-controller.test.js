import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { createSoundController } from "../src/sound-controller.js";

const TONE_BANK_URL = "./assets/audio/tap-tones.wav";

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

  setTargetAtTime(value, time, timeConstant) {
    this.value = value;
    this.events.push({ method: "setTargetAtTime", time, timeConstant, value });
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

  start(time = 0, offset = 0, duration) {
    this.startCalls.push({ duration, offset, time });
  }

  stop(time = 0) {
    this.stopCalls.push(time);
  }
}

class FakeAudioContext {
  static decodeDuration = 8;
  static instances = [];
  static resumeShouldDefer = false;
  static resumeShouldReject = false;
  static suspendShouldDefer = false;

  constructor(options) {
    this.bufferSources = [];
    this.closeCalls = 0;
    this.currentTime = 4;
    this.decodeCalls = [];
    this.destination = { type: "destination" };
    this.gainNodes = [];
    this.listeners = new Map();
    this.options = options;
    this.resumeCalls = 0;
    this.resumeResolvers = [];
    this.state = "suspended";
    this.suspendCalls = 0;
    this.suspendResolvers = [];
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

  decodeAudioData(arrayBuffer) {
    this.decodeCalls.push(arrayBuffer);
    return Promise.resolve({
      duration: FakeAudioContext.decodeDuration,
      id: arrayBuffer.url
    });
  }

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
  }

  resume() {
    this.resumeCalls += 1;
    if (FakeAudioContext.resumeShouldReject) {
      return Promise.reject(new Error("Audio output is unavailable."));
    }
    if (FakeAudioContext.resumeShouldDefer) {
      return new Promise((resolve) => {
        this.resumeResolvers.push(() => {
          this.changeState("running");
          resolve();
        });
      });
    }
    this.changeState("running");
    return Promise.resolve();
  }

  suspend() {
    this.suspendCalls += 1;
    if (FakeAudioContext.suspendShouldDefer) {
      return new Promise((resolve) => {
        this.suspendResolvers.push(() => {
          this.changeState("suspended");
          resolve();
        });
      });
    }
    this.changeState("suspended");
    return Promise.resolve();
  }
}

function createImmediateFetch() {
  const calls = [];
  const fetchImpl = async (url, options = {}) => {
    calls.push({ options, url });
    return {
      ok: true,
      async arrayBuffer() {
        return { url };
      }
    };
  };
  return { calls, fetchImpl };
}

function createDeferredFetch() {
  const calls = [];
  const fetchImpl = (url, options = {}) => new Promise((resolve) => {
    calls.push({ options, resolve, url });
  });
  return { calls, fetchImpl };
}

function resetFakes() {
  FakeAudioContext.decodeDuration = 8;
  FakeAudioContext.instances = [];
  FakeAudioContext.resumeShouldDefer = false;
  FakeAudioContext.resumeShouldReject = false;
  FakeAudioContext.suspendShouldDefer = false;
}

function createController(fetchImpl, AudioContextClass = FakeAudioContext) {
  resetFakes();
  return createSoundController({ AudioContextClass, fetchImpl });
}

async function flushAsyncWork(turns = 12) {
  for (let turn = 0; turn < turns; turn += 1) await Promise.resolve();
}

test("the controller uses one Web Audio tone bank and no hum, failure cue, or media element", async () => {
  const source = await readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8");

  assert.match(source, /AudioContext/);
  assert.match(source, /latencyHint:\s*["']interactive["']/);
  assert.match(source, /assets\/audio\/tap-tones\.wav/);
  assert.doesNotMatch(source, /fluorescent-hum|oops\.wav|HTMLMediaElement|new Audio\s*\(/);
  assert.doesNotMatch(source, /navigator\.(?:userAgent|platform)|iPhone|iPad|iPod/i);
});

test("disabled Sound FX creates no context and performs no fetch, decode, or playback work", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(false);
  await sound.unlock();
  await sound.startRun();
  assert.equal(sound.playCorrectTap(1), false);
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
});

test("enabling prepares exactly one eight-second bank with interactive Web Audio", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(true);
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 1);
  const context = FakeAudioContext.instances[0];
  assert.equal(context.options.latencyHint, "interactive");
  assert.deepEqual(fetchRecorder.calls, [
    {
      options: {
        cache: "no-store",
        signal: fetchRecorder.calls[0].options.signal
      },
      url: TONE_BANK_URL
    }
  ]);
  assert.equal(context.decodeCalls.length, 1);
  assert.equal(context.bufferSources.length, 0, "Preloading must remain silent.");

  sound.setEnabled(true);
  await sound.unlock();
  await flushAsyncWork();
  assert.equal(FakeAudioContext.instances.length, 1);
  assert.equal(fetchRecorder.calls.length, 1);
  assert.equal(context.decodeCalls.length, 1);
});

test("a short or malformed bank is rejected and taps remain silent", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.decodeDuration = 7.99;

  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();

  assert.equal(sound.playCorrectTap(1), false);
  assert.equal(FakeAudioContext.instances[0].bufferSources.length, 0);
});

test("unlock resumes only from the caller's gesture and softly opens the output gate", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];

  assert.equal(context.resumeCalls, 0);
  assert.equal(sound.playCorrectTap(1), false);
  assert.equal(await sound.unlock(), true);
  assert.equal(context.resumeCalls, 1);
  assert.ok(
    masterGain.gain.events.some(
      (event) =>
        event.method === "setTargetAtTime" &&
        event.value === 1 &&
        event.timeConstant >= 0.01
    ),
    "The output route must fade in rather than opening abruptly."
  );

  assert.equal(await sound.unlock(), true);
  assert.equal(context.resumeCalls, 1, "An already-running context is reused.");
});

test("correct taps play successive half-second slots and wrap after slot 16", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];

  for (const [hitNumber, expectedOffset] of [
    [1, 0],
    [2, 0.5],
    [16, 7.5],
    [17, 0],
    [0, 0]
  ]) {
    context.currentTime += 0.01;
    assert.equal(sound.playCorrectTap(hitNumber), true);
    const source = context.bufferSources.at(-1);
    assert.deepEqual(source.startCalls, [
      { duration: 0.5, offset: expectedOffset, time: context.currentTime }
    ]);
    assert.equal(source.buffer.id, TONE_BANK_URL);
    source.onended();
  }
});

test("the third overlapping tap briefly fades and stops only the oldest of two voices", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];

  sound.playCorrectTap(1);
  context.currentTime = 4.05;
  sound.playCorrectTap(2);
  context.currentTime = 4.1;
  sound.playCorrectTap(3);

  assert.equal(context.bufferSources.length, 3);
  const [oldest, second, newest] = context.bufferSources;
  assert.equal(oldest.stopCalls.length, 1);
  assert.ok(Math.abs(oldest.stopCalls[0] - 4.114) < 1e-9);
  assert.equal(second.stopCalls.length, 0);
  assert.equal(newest.stopCalls.length, 0);
  const oldestGain = oldest.connections[0];
  assert.ok(
    oldestGain.gain.events.some(
      (event) =>
        event.method === "linearRampToValueAtTime" &&
        event.value === 0 &&
        Math.abs(event.time - 4.112) < 1e-9
    )
  );
});

test("a tap before decoding is skipped and never arrives late", async () => {
  const fetchRecorder = createDeferredFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork(2);
  const context = FakeAudioContext.instances[0];
  await sound.unlock();

  assert.equal(sound.playCorrectTap(1), false);
  fetchRecorder.calls[0].resolve({
    ok: true,
    async arrayBuffer() {
      return { url: TONE_BANK_URL };
    }
  });
  await flushAsyncWork();

  assert.equal(context.bufferSources.length, 0, "Readiness must not replay the missed cue.");
  assert.equal(sound.playCorrectTap(2), true);
  assert.equal(context.bufferSources[0].startCalls[0].offset, 0.5);
});

test("startRun retires stale tones and keeps the prepared context and bank", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  sound.playCorrectTap(1);
  context.currentTime = 4.2;

  assert.equal(await sound.startRun(), true);
  assert.equal(context.bufferSources[0].stopCalls.length, 1);
  assert.ok(Math.abs(context.bufferSources[0].stopCalls[0] - 4.214) < 1e-9);
  assert.equal(fetchRecorder.calls.length, 1);
  assert.equal(context.resumeCalls, 1);
  assert.equal(sound.playCorrectTap(1), true);
});

test("backgrounding stops active tones and requires another trusted gesture", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  sound.playCorrectTap(1);
  const source = context.bufferSources[0];

  sound.suspend();
  await flushAsyncWork();
  assert.equal(context.state, "suspended");
  assert.equal(context.suspendCalls, 1);
  assert.deepEqual(source.stopCalls, [0]);
  assert.equal(sound.playCorrectTap(2), false);

  assert.equal(await sound.unlock(), true);
  assert.equal(context.resumeCalls, 2);
  assert.equal(sound.playCorrectTap(2), true);
});

test("an interruption stays silent until a newer gesture explicitly resumes it", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];
  sound.playCorrectTap(1);

  context.changeState("interrupted");
  assert.equal(masterGain.gain.value, 0);
  assert.deepEqual(context.bufferSources[0].stopCalls, [0]);
  context.changeState("running");
  assert.equal(sound.playCorrectTap(2), false, "Automatic recovery must remain gated.");

  assert.equal(await sound.unlock(), true);
  assert.equal(sound.playCorrectTap(2), true);
});

test("a stale resume completion cannot reopen audio after suspension", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.resumeShouldDefer = true;
  sound.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];

  const unlocking = sound.unlock();
  sound.suspend();
  context.resumeResolvers[0]();
  await flushAsyncWork();

  assert.equal(await unlocking, false);
  assert.equal(context.state, "suspended");
  assert.equal(context.suspendCalls, 1);
  assert.equal(masterGain.gain.value, 0);
  assert.equal(
    masterGain.gain.events.filter(
      (event) => event.method === "setTargetAtTime" && event.value === 1
    ).length,
    0
  );
});

test("a gesture during a pending suspension replaces the old context safely", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.suspendShouldDefer = true;
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const oldContext = FakeAudioContext.instances[0];

  sound.suspend();
  assert.equal(oldContext.suspendResolvers.length, 1);
  const newRun = sound.startRun();
  await flushAsyncWork();
  assert.equal(await newRun, true);
  assert.equal(FakeAudioContext.instances.length, 2);
  const newContext = FakeAudioContext.instances[1];
  assert.equal(oldContext.closeCalls, 1);
  assert.equal(newContext.state, "running");

  oldContext.suspendResolvers[0]();
  await flushAsyncWork();
  assert.equal(newContext.state, "running");
});

test("disabling aborts preparation, closes audio, and prevents stale work from reviving it", async () => {
  const fetchRecorder = createDeferredFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork(2);
  const context = FakeAudioContext.instances[0];

  sound.setEnabled(false);
  assert.equal(fetchRecorder.calls[0].options.signal.aborted, true);
  assert.equal(context.closeCalls, 1);
  fetchRecorder.calls[0].resolve({
    ok: true,
    async arrayBuffer() {
      return { url: TONE_BANK_URL };
    }
  });
  await flushAsyncWork();

  assert.equal(context.decodeCalls.length, 0);
  assert.equal(await sound.unlock(), false);
  assert.equal(sound.playCorrectTap(1), false);
  assert.equal(FakeAudioContext.instances.length, 1);
});

test("disabling a prepared session stops tones, disconnects nodes, and closes its context", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  sound.playCorrectTap(1);
  const source = context.bufferSources[0];

  sound.setEnabled(false);
  await flushAsyncWork();
  assert.deepEqual(source.stopCalls, [0]);
  assert.ok(source.disconnectCalls > 0);
  assert.ok(context.gainNodes.some((node) => node.disconnectCalls > 0));
  assert.equal(context.closeCalls, 1);
});

test("unsupported, failed, or blocked audio degrades silently", async () => {
  const fetchRecorder = createImmediateFetch();
  const unsupported = createController(fetchRecorder.fetchImpl, null);
  assert.doesNotThrow(() => unsupported.setEnabled(true));
  assert.equal(await unsupported.unlock(), false);
  assert.equal(unsupported.playCorrectTap(1), false);
  assert.equal(fetchRecorder.calls.length, 0);

  resetFakes();
  const failed = createSoundController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: async () => {
      throw new Error("Audio asset is unavailable.");
    }
  });
  assert.doesNotThrow(() => failed.setEnabled(true));
  await flushAsyncWork();
  assert.doesNotThrow(() => failed.playCorrectTap(1));

  resetFakes();
  FakeAudioContext.resumeShouldReject = true;
  const blockedFetch = createImmediateFetch();
  const blocked = createSoundController({
    AudioContextClass: FakeAudioContext,
    fetchImpl: blockedFetch.fetchImpl
  });
  blocked.setEnabled(true);
  await flushAsyncWork();
  assert.equal(await blocked.unlock(), false);
  assert.equal(blocked.playCorrectTap(1), false);
});
