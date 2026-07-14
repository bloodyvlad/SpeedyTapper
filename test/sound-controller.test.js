import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { createSoundController } from "../src/sound-controller.js";

const TONE_BANK_URL = "./assets/audio/tap-tones.wav";
const LIFE_LOSS_URL = "./assets/audio/oops.wav";

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
  static decodeDurations = new Map();
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
      duration:
        FakeAudioContext.decodeDurations.get(arrayBuffer.url) ??
        FakeAudioContext.decodeDuration,
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
  FakeAudioContext.decodeDurations = new Map();
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

test("the controller uses Web Audio tap tones and a life-loss cue without hum or media elements", async () => {
  const source = await readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8");

  assert.match(source, /AudioContext/);
  assert.match(source, /latencyHint:\s*["']interactive["']/);
  assert.match(source, /assets\/audio\/tap-tones\.wav/);
  assert.match(source, /assets\/audio\/oops\.wav/);
  assert.doesNotMatch(source, /fluorescent-hum|HTMLMediaElement|new Audio\s*\(/);
  assert.doesNotMatch(source, /navigator\.(?:userAgent|platform)|iPhone|iPad|iPod/i);
});

test("disabled Sound FX creates no context and performs no fetch, decode, or playback work", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(false);
  await sound.unlock();
  await sound.startRun();
  assert.equal(sound.playCorrectTap(1), false);
  assert.equal(sound.lifeLost(), false);
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
});

test("enabling prepares the tone bank and life-loss cue with interactive Web Audio", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(true);
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 1);
  const context = FakeAudioContext.instances[0];
  assert.equal(context.options.latencyHint, "interactive");
  assert.deepEqual(
    fetchRecorder.calls.map(({ options, url }) => ({
      cache: options.cache,
      hasSignal: options.signal instanceof AbortSignal,
      url
    })),
    [
      { cache: "no-store", hasSignal: true, url: TONE_BANK_URL },
      { cache: "no-store", hasSignal: true, url: LIFE_LOSS_URL }
    ]
  );
  assert.equal(context.decodeCalls.length, 2);
  assert.equal(context.bufferSources.length, 0, "Preloading must remain silent.");

  sound.setEnabled(true);
  await sound.unlock();
  await flushAsyncWork();
  assert.equal(FakeAudioContext.instances.length, 1);
  assert.equal(fetchRecorder.calls.length, 2);
  assert.equal(context.decodeCalls.length, 2);
});

test("a short or malformed bank is rejected and taps remain silent", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.decodeDurations.set(TONE_BANK_URL, 7.99);

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

test("life loss plays immediately from memory and smoothly retires the prior life cue", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];

  assert.equal(sound.lifeLost(), true);
  const first = context.bufferSources[0];
  assert.equal(first.buffer.id, LIFE_LOSS_URL);
  assert.deepEqual(first.startCalls, [
    { duration: undefined, offset: 0, time: context.currentTime }
  ]);

  context.currentTime = 4.1;
  assert.equal(sound.lifeLost(), true);
  const second = context.bufferSources[1];
  assert.equal(first.stopCalls.length, 1);
  assert.ok(Math.abs(first.stopCalls[0] - 4.114) < 1e-9);
  assert.equal(second.stopCalls.length, 0);
  const firstGain = first.connections[0];
  assert.ok(
    firstGain.gain.events.some(
      (event) =>
        event.method === "linearRampToValueAtTime" &&
        event.value === 0 &&
        Math.abs(event.time - 4.112) < 1e-9
    ),
    "Replacing a life cue must fade its predecessor before stopping it."
  );
});

test("the single life-loss voice does not consume either tap-tone voice slot", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];

  sound.lifeLost();
  sound.playCorrectTap(1);
  sound.playCorrectTap(2);
  context.currentTime = 4.1;
  sound.playCorrectTap(3);

  const [lifeSource, firstTone, secondTone, thirdTone] = context.bufferSources;
  assert.equal(lifeSource.stopCalls.length, 0);
  assert.equal(firstTone.stopCalls.length, 1);
  assert.equal(secondTone.stopCalls.length, 0);
  assert.equal(thirdTone.stopCalls.length, 0);
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

test("a life loss before its cue is decoded is skipped and never arrives late", async () => {
  const fetchRecorder = createDeferredFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork(2);
  const context = FakeAudioContext.instances[0];
  await sound.unlock();

  assert.equal(sound.lifeLost(), false);
  fetchRecorder.calls[1].resolve({
    ok: true,
    async arrayBuffer() {
      return { url: LIFE_LOSS_URL };
    }
  });
  await flushAsyncWork();

  assert.equal(context.bufferSources.length, 0, "Readiness must not replay the missed cue.");
  assert.equal(sound.lifeLost(), true);
  assert.equal(context.bufferSources[0].buffer.id, LIFE_LOSS_URL);
});

test("startRun retires stale tones and life loss while keeping prepared audio", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  sound.playCorrectTap(1);
  sound.lifeLost();
  context.currentTime = 4.2;

  assert.equal(await sound.startRun(), true);
  assert.equal(context.bufferSources[0].stopCalls.length, 1);
  assert.ok(Math.abs(context.bufferSources[0].stopCalls[0] - 4.214) < 1e-9);
  assert.equal(context.bufferSources[1].stopCalls.length, 1);
  assert.ok(Math.abs(context.bufferSources[1].stopCalls[0] - 4.214) < 1e-9);
  assert.equal(fetchRecorder.calls.length, 2);
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
  sound.lifeLost();
  const [toneSource, lifeSource] = context.bufferSources;

  sound.suspend();
  await flushAsyncWork();
  assert.equal(context.state, "suspended");
  assert.equal(context.suspendCalls, 1);
  assert.deepEqual(toneSource.stopCalls, [0]);
  assert.deepEqual(lifeSource.stopCalls, [0]);
  assert.equal(sound.playCorrectTap(2), false);
  assert.equal(sound.lifeLost(), false);

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
  assert.ok(fetchRecorder.calls.every((call) => call.options.signal.aborted));
  assert.equal(context.closeCalls, 1);
  for (const call of fetchRecorder.calls) {
    call.resolve({
      ok: true,
      async arrayBuffer() {
        return { url: call.url };
      }
    });
  }
  await flushAsyncWork();

  assert.equal(context.decodeCalls.length, 0);
  assert.equal(await sound.unlock(), false);
  assert.equal(sound.playCorrectTap(1), false);
  assert.equal(sound.lifeLost(), false);
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
  sound.lifeLost();
  const [toneSource, lifeSource] = context.bufferSources;

  sound.setEnabled(false);
  await flushAsyncWork();
  assert.deepEqual(toneSource.stopCalls, [0]);
  assert.deepEqual(lifeSource.stopCalls, [0]);
  assert.ok(toneSource.disconnectCalls > 0);
  assert.ok(lifeSource.disconnectCalls > 0);
  assert.ok(context.gainNodes.some((node) => node.disconnectCalls > 0));
  assert.equal(context.closeCalls, 1);
});

test("unsupported, failed, or blocked audio degrades silently", async () => {
  const fetchRecorder = createImmediateFetch();
  const unsupported = createController(fetchRecorder.fetchImpl, null);
  assert.doesNotThrow(() => unsupported.setEnabled(true));
  assert.equal(await unsupported.unlock(), false);
  assert.equal(unsupported.playCorrectTap(1), false);
  assert.equal(unsupported.lifeLost(), false);
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
  assert.doesNotThrow(() => failed.lifeLost());

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
  assert.equal(blocked.lifeLost(), false);
});
