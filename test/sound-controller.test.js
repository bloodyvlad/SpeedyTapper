import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { createSoundController } from "../src/sound-controller.js";

const SOUND_URLS = [
  "./assets/audio/fluorescent-hum.wav",
  "./assets/audio/oops.wav"
];

class FakeAudioParam {
  constructor(value = 0) {
    this.value = value;
    this.events = [];
  }

  cancelAndHoldAtTime(time) {
    this.events.push({ method: "cancelAndHoldAtTime", time });
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

  setTargetAtTime(value, time, timeConstant) {
    this.value = value;
    this.events.push({ method: "setTargetAtTime", time, timeConstant, value });
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
    this.sampleRate = 48000;
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
      duration: arrayBuffer.url.endsWith("oops.wav") ? 0.62 : 2,
      id: arrayBuffer.url
    });
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

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
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
  for (let turn = 0; turn < turns; turn += 1) {
    await Promise.resolve();
  }
}

function findHumSource(context) {
  return context.bufferSources.find((source) => source.loop);
}

test("the PCM hum asset has a click-safe loop seam", async () => {
  const wav = await readFile(new URL("../assets/audio/fluorescent-hum.wav", import.meta.url));
  let offset = 12;
  let audioData = null;
  while (offset + 8 <= wav.length) {
    const chunkId = wav.toString("ascii", offset, offset + 4);
    const chunkSize = wav.readUInt32LE(offset + 4);
    if (chunkId === "data") {
      audioData = wav.subarray(offset + 8, offset + 8 + chunkSize);
      break;
    }
    offset += 8 + chunkSize + (chunkSize % 2);
  }

  assert.ok(audioData && audioData.length >= 4, "The hum WAV must contain PCM data.");
  const firstSample = audioData.readInt16LE(0);
  const lastSample = audioData.readInt16LE(audioData.length - 2);
  assert.ok(
    Math.abs(lastSample - firstSample) <= 512,
    `The hum loop edge is too large: ${lastSample} → ${firstSample}.`
  );
});

test("the PCM life-loss cue starts and ends at exact zero", async () => {
  const wav = await readFile(new URL("../assets/audio/oops.wav", import.meta.url));
  let offset = 12;
  let audioData = null;
  while (offset + 8 <= wav.length) {
    const chunkId = wav.toString("ascii", offset, offset + 4);
    const chunkSize = wav.readUInt32LE(offset + 4);
    if (chunkId === "data") {
      audioData = wav.subarray(offset + 8, offset + 8 + chunkSize);
      break;
    }
    offset += 8 + chunkSize + (chunkSize % 2);
  }

  assert.ok(audioData && audioData.length >= 4, "The oops WAV must contain 16-bit PCM data.");
  assert.equal(audioData.readInt16LE(0), 0);
  assert.equal(audioData.readInt16LE(audioData.length - 2), 0);
});

test("the controller is Web Audio based and contains no browser sniffing or media-element seeking", async () => {
  const source = await readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8");

  assert.doesNotMatch(source, /navigator\.(?:userAgent|platform)|iPhone|iPad|iPod/i);
  assert.doesNotMatch(source, /HTMLMediaElement|new Audio\s*\(|\.currentTime\s*=/);
  assert.match(source, /AudioContext/);
  assert.match(source, /latencyHint\s*:\s*["']interactive["']/);
});

test("Sound FX defaults on in the browser shell while preserving an explicit opt-out", async () => {
  const [mainSource, indexHtml] = await Promise.all([
    readFile(new URL("../src/main.js", import.meta.url), "utf8"),
    readFile(new URL("../index.html", import.meta.url), "utf8")
  ]);

  assert.match(mainSource, /let soundFxEnabled = true;/);
  assert.match(mainSource, /soundFxEnabled = storedSoundFx !== ["']off["'];/);
  assert.match(indexHtml, /id="sound-fx-toggle"[^>]+role="switch"/);
  assert.match(indexHtml, /id="sound-fx-toggle"[^>]+checked/);
});

test("disabled Sound FX creates no context and performs no fetch, decode, or playback work", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(false);
  sound.unlock();
  sound.startRun();
  sound.tileOn();
  sound.lifeLost();
  sound.tileOff();
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 0);
  assert.equal(fetchRecorder.calls.length, 0);
});

test("enabling eagerly fetches and decodes both buffers once and retains them", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(true);
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 1);
  const context = FakeAudioContext.instances[0];
  assert.equal(context.options.latencyHint, "interactive");
  assert.deepEqual(fetchRecorder.calls.map(({ url }) => url).sort(), [...SOUND_URLS].sort());
  assert.equal(context.decodeCalls.length, 2);

  sound.unlock();
  sound.tileOn();
  sound.tileOff();
  sound.lifeLost();
  await flushAsyncWork();

  assert.equal(FakeAudioContext.instances.length, 1, "The enabled session must retain its context.");
  assert.equal(fetchRecorder.calls.length, 2, "Gameplay must reuse the preloaded buffers.");
  assert.equal(context.decodeCalls.length, 2, "Gameplay must not repeatedly decode audio.");
});

test("unlock resumes the interactive context and softly opens its master gate", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];

  assert.equal(context.resumeCalls, 0);
  const masterGain = context.gainNodes[0];
  assert.equal(masterGain.gain.value, 0, "The output must remain closed before a gesture.");
  const unlocked = sound.unlock();
  assert.equal(context.resumeCalls, 1);
  await flushAsyncWork();
  assert.equal(await unlocked, true);
  assert.ok(
    masterGain.gain.events.some(
      (event) =>
        event.method === "setTargetAtTime" &&
        event.value === 1 &&
        event.timeConstant >= 0.01
    ),
    "The resumed output must fade in instead of opening with a hardware pop."
  );

  sound.unlock();
  assert.equal(context.resumeCalls, 1, "An already-running context does not need another resume.");
});

test("a pending resume cannot reopen audio after suspension", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.resumeShouldDefer = true;
  sound.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];

  const unlocking = sound.unlock();
  assert.equal(context.resumeCalls, 1);
  sound.suspend();
  context.resumeResolvers[0]();
  await flushAsyncWork();

  assert.equal(await unlocking, false);
  assert.equal(context.state, "suspended");
  assert.equal(context.suspendCalls, 1, "A stale resume must be suspended again.");
  assert.equal(masterGain.gain.value, 0);
  assert.equal(
    masterGain.gain.events.filter(
      (event) => event.method === "setTargetAtTime" && event.value === 1
    ).length,
    0,
    "A stale completion must never reopen the master gate."
  );
});

test("an old resume completion cannot suspend a newer valid gesture", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.resumeShouldDefer = true;
  sound.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];

  const oldUnlock = sound.unlock();
  sound.suspend();
  const newUnlock = sound.unlock();
  assert.equal(context.resumeResolvers.length, 2);

  context.resumeResolvers[1]();
  await flushAsyncWork();
  assert.equal(await newUnlock, true);
  context.resumeResolvers[0]();
  await flushAsyncWork();

  assert.equal(await oldUnlock, false);
  assert.equal(context.state, "running");
  assert.equal(context.suspendCalls, 0, "The stale completion must respect the newer run.");
  assert.equal(masterGain.gain.value, 1);
});

test("a newer gesture replaces a context with a pending suspension", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  FakeAudioContext.suspendShouldDefer = true;
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const oldContext = FakeAudioContext.instances[0];

  sound.suspend();
  assert.equal(oldContext.state, "running");
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

  assert.equal(newContext.state, "running", "The old suspension must not mute the newer run.");
  const newMasterGain = newContext.gainNodes[0];
  assert.equal(newMasterGain.gain.value, 1);
});

test("an unmanaged iOS interruption closes stale gates before resuming", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];
  const hum = findHumSource(context);
  const humGain = hum.connections.find((node) => node instanceof FakeGainNode);

  sound.tileOn();
  assert.equal(humGain.gain.value, 0.75);
  context.changeState("interrupted");
  const restarting = sound.startRun();

  assert.equal(humGain.gain.value, 0, "A stale active target must be gated before resume.");
  assert.equal(masterGain.gain.value, 0, "The output route must be closed before resume.");
  await restarting;
  const openEvents = masterGain.gain.events.filter(
    (event) => event.method === "setTargetAtTime" && event.value === 1
  );
  assert.equal(openEvents.length, 2, "The resumed route must get a fresh soft opening.");
});

test("automatic iOS interruption recovery stays silent until another trusted gesture", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];
  const masterGain = context.gainNodes[0];
  const hum = findHumSource(context);
  const humGain = hum.connections.find((node) => node instanceof FakeGainNode);

  sound.tileOn();
  context.changeState("interrupted");
  assert.equal(humGain.gain.value, 0);
  assert.equal(masterGain.gain.value, 0);
  context.changeState("running");

  const openEventsBeforeGesture = masterGain.gain.events.filter(
    (event) => event.method === "setTargetAtTime" && event.value === 1
  );
  assert.equal(openEventsBeforeGesture.length, 1, "Automatic recovery must keep the gate closed.");
  assert.equal(masterGain.gain.value, 0);

  await sound.unlock();
  const openEventsAfterGesture = masterGain.gain.events.filter(
    (event) => event.method === "setTargetAtTime" && event.value === 1
  );
  assert.equal(openEventsAfterGesture.length, 2);
});

test("background suspension silences all audio and requires the next gesture to resume", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  sound.unlock();
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const hum = findHumSource(context);
  const humGain = hum.connections.find((node) => node instanceof FakeGainNode);

  sound.tileOn();
  sound.lifeLost();
  const oneShot = context.bufferSources.find((source) => !source.loop);
  sound.suspend();
  await flushAsyncWork();

  assert.equal(context.suspendCalls, 1);
  assert.equal(context.state, "suspended");
  assert.equal(humGain.gain.value, 0);
  assert.equal(oneShot.stopCalls.length, 1, "A suspended cue must not resume in the next run.");
  assert.ok(oneShot.disconnectCalls > 0);

  sound.unlock();
  assert.equal(context.resumeCalls, 2);
});

test("events that occur before decoding finishes are skipped instead of playing late", async () => {
  const fetchRecorder = createDeferredFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork(2);
  const context = FakeAudioContext.instances[0];

  sound.unlock();
  sound.tileOn();
  sound.lifeLost();

  for (const request of fetchRecorder.calls) {
    request.resolve({
      ok: true,
      async arrayBuffer() {
        return { url: request.url };
      }
    });
  }
  await flushAsyncWork();

  const hum = findHumSource(context);
  assert.ok(hum, "The persistent silent loop may be prepared for future rounds.");
  const humGain = hum.connections.find((node) => node instanceof FakeGainNode);
  assert.equal(
    humGain.gain.events.filter(
      (event) => event.method === "setTargetAtTime" && event.value > 0
    ).length,
    0,
    "A target that appeared before readiness must not make the hum arrive late."
  );
  assert.equal(
    context.bufferSources.filter((source) => !source.loop).length,
    0,
    "A life-loss cue that occurred before readiness must not be queued."
  );
});

test("the hum is one persistent loop controlled by smooth click-resistant targets", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  sound.unlock();
  await flushAsyncWork();

  const context = FakeAudioContext.instances[0];
  const hum = findHumSource(context);
  assert.ok(hum, "A looped hum source should be prepared once.");
  assert.equal(hum.startCalls.length, 1);
  assert.equal(hum.stopCalls.length, 0);
  const humGain = hum.connections.find((node) => node instanceof FakeGainNode);
  assert.ok(humGain, "The persistent hum must be gated through a GainNode.");

  context.currentTime = 10;
  sound.tileOn();
  const onTarget = humGain.gain.events.findLast(
    (event) => event.method === "setTargetAtTime" && event.value > 0
  );
  assert.ok(onTarget, "tileOn should smoothly approach an audible gain.");
  assert.equal(onTarget.value, 0.75, "The ambient cue must use the normalized SFX mix.");
  assert.equal(onTarget.time, 10);
  assert.ok(
    onTarget.timeConstant >= 0.006 && onTarget.timeConstant <= 0.01,
    "The attack must begin immediately and settle without a linear corner."
  );

  context.currentTime = 11;
  sound.tileOff();
  const offTarget = humGain.gain.events.findLast(
    (event) => event.method === "setTargetAtTime" && event.value === 0
  );
  assert.ok(offTarget, "tileOff should smoothly approach silence.");
  assert.equal(offTarget.time, 11);
  assert.ok(offTarget.timeConstant >= 0.01 && offTarget.timeConstant <= 0.014);

  context.currentTime = 12;
  sound.tileOn();
  context.currentTime = 13;
  sound.tileOff();
  assert.equal(findHumSource(context), hum);
  assert.equal(hum.startCalls.length, 1, "Tiles must never restart or seek the hum source.");
  assert.equal(hum.stopCalls.length, 0, "Tiles must never stop the hum source.");
});

test("life-loss cues cannot overlap and clip the output", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  sound.unlock();
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];

  sound.lifeLost();
  sound.lifeLost();

  let oneShots = context.bufferSources.filter((source) => !source.loop);
  assert.equal(oneShots.length, 1, "Rapid misses must not stack multiple loud cues.");
  assert.ok(oneShots[0].buffer?.id.endsWith("oops.wav"));
  assert.equal(oneShots[0].startCalls.length, 1);
  const oneShotGain = oneShots[0].connections.find((node) => node instanceof FakeGainNode);
  assert.equal(oneShotGain.gain.events[0]?.value, 0);
  assert.ok(
    oneShotGain.gain.events.some(
      (event) => event.method === "linearRampToValueAtTime" && event.value === 0.55
    ),
    "The life-loss cue must peak at the normalized SFX level."
  );

  oneShots[0].onended();
  sound.lifeLost();
  oneShots = context.bufferSources.filter((source) => !source.loop);
  assert.equal(oneShots.length, 2, "A later miss may use a fresh source after cleanup.");
});

test("starting a new run fades and stops a stale failure cue", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  await sound.unlock();
  const context = FakeAudioContext.instances[0];

  sound.lifeLost();
  const oneShot = context.bufferSources.find((source) => !source.loop);
  context.currentTime = 4.614;
  await sound.startRun();

  assert.equal(oneShot.stopCalls.length, 1);
  assert.ok(oneShot.stopCalls[0] > 4.626 && oneShot.stopCalls[0] <= 4.629);
  const oneShotGain = oneShot.connections.find((node) => node instanceof FakeGainNode);
  const fadeTarget = oneShotGain.gain.events.findLast(
    (event) => event.method === "setTargetAtTime" && event.value === 0
  );
  const heldReleaseGain = oneShotGain.gain.events.findLast(
    (event) => event.method === "setValueAtTime" && event.time === 4.614
  );
  const settledZero = oneShotGain.gain.events.findLast(
    (event) => event.method === "setValueAtTime" && event.value === 0 && event.time > 4.614
  );
  assert.ok(fadeTarget);
  assert.ok(
    Math.abs(heldReleaseGain.value - 0.275) < 0.001,
    "Restart must hold the cue's instantaneous release value before fading."
  );
  assert.ok(
    oneShot.stopCalls[0] - fadeTarget.time >= 8 * fadeTarget.timeConstant,
    "The cue must decay for at least eight time constants before it stops."
  );
  assert.ok(settledZero?.time < oneShot.stopCalls[0], "The gain must reach exact zero before stop.");
});

test("disabling aborts preparation, closes audio, and prevents stale async work from reviving it", async () => {
  const fetchRecorder = createDeferredFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork(2);
  assert.equal(fetchRecorder.calls.length, 2);
  const context = FakeAudioContext.instances[0];

  sound.setEnabled(false);
  assert.ok(fetchRecorder.calls.every(({ options }) => options.signal?.aborted));
  assert.equal(context.closeCalls, 1);

  for (const request of fetchRecorder.calls) {
    request.resolve({
      ok: true,
      async arrayBuffer() {
        return { url: request.url };
      }
    });
  }
  await flushAsyncWork();

  assert.equal(context.decodeCalls.length, 0);
  assert.equal(context.bufferSources.length, 0);
  sound.unlock();
  sound.tileOn();
  sound.tileOff();
  sound.lifeLost();
  assert.equal(FakeAudioContext.instances.length, 1);
});

test("one failed preload aborts its sibling and cannot start a duplicate preparation", async () => {
  const calls = [];
  let resolveSibling;
  const sound = createController((url, options = {}) => {
    calls.push({ options, url });
    if (url.endsWith("fluorescent-hum.wav")) {
      return Promise.reject(new Error("Hum unavailable."));
    }
    return new Promise((resolve) => {
      resolveSibling = resolve;
    });
  });

  sound.setEnabled(true);
  await flushAsyncWork();
  assert.equal(calls.length, 2);
  assert.ok(calls.every(({ options }) => options.signal.aborted));

  sound.unlock();
  await flushAsyncWork();
  assert.equal(calls.length, 2, "A pending sibling must retain preparation ownership.");

  resolveSibling({
    ok: true,
    async arrayBuffer() {
      return { url: SOUND_URLS[1] };
    }
  });
  await flushAsyncWork();
});

test("disabling a prepared session stops its persistent source and closes its context", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  sound.unlock();
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];
  const hum = findHumSource(context);

  sound.setEnabled(false);
  await flushAsyncWork();

  assert.equal(hum.stopCalls.length, 1);
  assert.equal(context.closeCalls, 1);
  assert.ok(context.gainNodes.some((node) => node.disconnectCalls > 0));
});

test("unsupported, failed, or blocked audio degrades silently", async () => {
  const fetchRecorder = createImmediateFetch();
  const unsupported = createController(fetchRecorder.fetchImpl, null);
  assert.doesNotThrow(() => unsupported.setEnabled(true));
  assert.doesNotThrow(() => unsupported.unlock());
  assert.doesNotThrow(() => unsupported.tileOn());
  assert.doesNotThrow(() => unsupported.tileOff());
  assert.doesNotThrow(() => unsupported.lifeLost());
  await flushAsyncWork();
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
  assert.doesNotThrow(() => failed.unlock());
  assert.doesNotThrow(() => failed.tileOn());
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
  assert.doesNotThrow(() => blocked.unlock());
  await flushAsyncWork();
  assert.doesNotThrow(() => blocked.tileOn());
  assert.doesNotThrow(() => blocked.lifeLost());
});
