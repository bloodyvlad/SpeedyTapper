import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { createSoundController } from "../src/sound-controller.js";

const SOUND_URLS = [
  "./assets/audio/fluorescent-hum.wav",
  "./assets/audio/oops.mp3"
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
  static resumeShouldReject = false;

  constructor(options) {
    this.bufferSources = [];
    this.closeCalls = 0;
    this.currentTime = 4;
    this.decodeCalls = [];
    this.destination = { type: "destination" };
    this.gainNodes = [];
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
    return Promise.resolve({ id: arrayBuffer.url });
  }

  resume() {
    this.resumeCalls += 1;
    if (FakeAudioContext.resumeShouldReject) {
      return Promise.reject(new Error("Audio output is unavailable."));
    }
    this.state = "running";
    return Promise.resolve();
  }

  suspend() {
    this.suspendCalls += 1;
    this.state = "suspended";
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
  FakeAudioContext.resumeShouldReject = false;
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

test("the controller is Web Audio based and contains no browser sniffing or media-element seeking", async () => {
  const source = await readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8");

  assert.doesNotMatch(source, /navigator\.(?:userAgent|platform)|iPhone|iPad|iPod/i);
  assert.doesNotMatch(source, /HTMLMediaElement|new Audio\s*\(|\.currentTime\s*=/);
  assert.match(source, /AudioContext/);
  assert.match(source, /latencyHint\s*:\s*["']interactive["']/);
});

test("Sound FX defaults off in the browser shell", async () => {
  const [mainSource, indexHtml] = await Promise.all([
    readFile(new URL("../src/main.js", import.meta.url), "utf8"),
    readFile(new URL("../index.html", import.meta.url), "utf8")
  ]);

  assert.match(mainSource, /let soundFxEnabled = false;/);
  assert.match(mainSource, /soundFxEnabled = storedSoundFx === ["']on["'];/);
  assert.match(indexHtml, /id="sound-fx-toggle"[^>]+role="switch"/);
  assert.doesNotMatch(indexHtml, /id="sound-fx-toggle"[^>]+checked/);
});

test("disabled Sound FX creates no context and performs no fetch, decode, or playback work", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);

  sound.setEnabled(false);
  sound.unlock();
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

test("unlock resumes the interactive context from the explicit game-start gesture", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];

  assert.equal(context.resumeCalls, 0);
  sound.unlock();
  assert.equal(context.resumeCalls, 1);
  await flushAsyncWork();

  sound.unlock();
  assert.equal(context.resumeCalls, 1, "An already-running context does not need another resume.");
});

test("background suspension silences the hum and requires the next gesture to resume", async () => {
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
  sound.suspend();
  await flushAsyncWork();

  assert.equal(context.suspendCalls, 1);
  assert.equal(context.state, "suspended");
  assert.equal(humGain.gain.value, 0);

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
      (event) => event.method === "linearRampToValueAtTime" && event.value > 0
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

test("the hum is one persistent loop controlled only by short gain ramps", async () => {
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
  const onRamp = humGain.gain.events.findLast(
    (event) => event.method === "linearRampToValueAtTime" && event.value > 0
  );
  assert.ok(onRamp, "tileOn should ramp the hum to an audible gain.");
  assert.ok(onRamp.time >= 10.008 && onRamp.time <= 10.012, "The attack must be 8–12 ms.");

  context.currentTime = 11;
  sound.tileOff();
  const offRamp = humGain.gain.events.findLast(
    (event) => event.method === "linearRampToValueAtTime" && event.value === 0
  );
  assert.ok(offRamp, "tileOff should ramp the hum to silence.");
  assert.ok(offRamp.time >= 11.008 && offRamp.time <= 11.012, "The release must be 8–12 ms.");

  context.currentTime = 12;
  sound.tileOn();
  context.currentTime = 13;
  sound.tileOff();
  assert.equal(findHumSource(context), hum);
  assert.equal(hum.startCalls.length, 1, "Tiles must never restart or seek the hum source.");
  assert.equal(hum.stopCalls.length, 0, "Tiles must never stop the hum source.");
});

test("each life-loss cue uses a fresh one-shot buffer source", async () => {
  const fetchRecorder = createImmediateFetch();
  const sound = createController(fetchRecorder.fetchImpl);
  sound.setEnabled(true);
  await flushAsyncWork();
  sound.unlock();
  await flushAsyncWork();
  const context = FakeAudioContext.instances[0];

  sound.lifeLost();
  sound.lifeLost();

  const oneShots = context.bufferSources.filter((source) => !source.loop);
  assert.equal(oneShots.length, 2);
  assert.ok(oneShots.every((source) => source.buffer?.id.endsWith("oops.mp3")));
  assert.ok(oneShots.every((source) => source.startCalls.length === 1));
  assert.ok(oneShots.every((source) => source.stopCalls.length === 0));
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
