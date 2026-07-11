import assert from "node:assert/strict";
import test from "node:test";

import { createSoundController } from "../src/sound-controller.js";

class FakeAudio {
  static instances = [];

  constructor() {
    this.currentTime = 0;
    this.loadCalls = 0;
    this.loop = false;
    this.muted = false;
    this.pauseCalls = 0;
    this.playCalls = 0;
    this.preload = "";
    this.readyState = 4;
    this.src = "";
    this.volume = 1;
    FakeAudio.instances.push(this);
  }

  load() {
    this.loadCalls += 1;
  }

  pause() {
    this.pauseCalls += 1;
  }

  play() {
    this.playCalls += 1;
    return Promise.resolve();
  }

  removeAttribute(name) {
    if (name === "src") this.src = "";
  }
}

class DeferredAudio extends FakeAudio {
  static instances = [];

  constructor() {
    super();
    FakeAudio.instances.pop();
    this.playResolvers = [];
    DeferredAudio.instances.push(this);
  }

  play() {
    this.playCalls += 1;
    return new Promise((resolve) => {
      this.playResolvers.push(resolve);
    });
  }
}

function createController() {
  FakeAudio.instances = [];
  return createSoundController({ AudioClass: FakeAudio, haveFutureData: 3 });
}

test("disabled Sound FX never creates, loads, or plays audio", () => {
  const sound = createController();

  sound.setEnabled(false);
  sound.unlock();
  sound.tileOn();
  sound.lifeLost();
  sound.tileOff();

  assert.equal(FakeAudio.instances.length, 0);
});

test("enabling Sound FX remains lazy until an explicit unlock gesture", async () => {
  const sound = createController();

  sound.setEnabled(true);
  sound.tileOn();
  sound.lifeLost();
  assert.equal(FakeAudio.instances.length, 0);

  sound.unlock();
  assert.equal(FakeAudio.instances.length, 2);
  assert.deepEqual(
    FakeAudio.instances.map((audio) => audio.src).sort(),
    ["./assets/audio/fluorescent-hum.mp3", "./assets/audio/oops.mp3"]
  );
  assert.ok(FakeAudio.instances.every((audio) => audio.loadCalls === 1));
  assert.ok(FakeAudio.instances.every((audio) => audio.playCalls === 1));

  await Promise.resolve();
});

test("disabling Sound FX releases loaded media and all hooks become no-ops", async () => {
  const sound = createController();
  sound.setEnabled(true);
  sound.unlock();
  const loadedAudio = [...FakeAudio.instances];
  await Promise.resolve();

  sound.setEnabled(false);
  const playsBeforeDisabledHooks = loadedAudio.map((audio) => audio.playCalls);
  sound.unlock();
  sound.tileOn();
  sound.lifeLost();
  sound.tileOff();

  assert.ok(loadedAudio.every((audio) => audio.src === ""));
  assert.ok(loadedAudio.every((audio) => audio.pauseCalls >= 1));
  assert.ok(loadedAudio.every((audio) => audio.loadCalls === 2));
  assert.deepEqual(loadedAudio.map((audio) => audio.playCalls), playsBeforeDisabledHooks);

  sound.setEnabled(true);
  assert.equal(FakeAudio.instances.length, 2, "Re-enabling must not eagerly reload audio.");
  sound.unlock();
  assert.equal(FakeAudio.instances.length, 4, "The next gesture may create fresh media elements.");
});

test("a pending unlock cannot revive audio after Sound FX is disabled", async () => {
  DeferredAudio.instances = [];
  const sound = createSoundController({ AudioClass: DeferredAudio, haveFutureData: 3 });
  sound.setEnabled(true);
  sound.unlock();
  const loadedAudio = [...DeferredAudio.instances];

  sound.setEnabled(false);
  for (const audio of loadedAudio) {
    for (const resolve of audio.playResolvers) resolve();
  }
  await Promise.resolve();

  assert.ok(loadedAudio.every((audio) => audio.src === ""));
  assert.ok(loadedAudio.every((audio) => audio.muted));
  assert.ok(loadedAudio.every((audio) => audio.pauseCalls >= 2));
});
