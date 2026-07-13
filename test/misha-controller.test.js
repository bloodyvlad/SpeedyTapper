import assert from "node:assert/strict";
import test from "node:test";

import {
  createMishaController,
  MISHA_IDLE_DELAY_MS,
  normalizeMishaNickname,
  profileUnlocksMisha,
  resolveMishaFacing
} from "../src/misha-controller.js";

function classListStub() {
  const values = new Set();
  return {
    contains(value) {
      return values.has(value);
    },
    toggle(value, force) {
      if (force) values.add(value);
      else values.delete(value);
    }
  };
}

function elementStub() {
  return {
    hidden: true,
    dataset: {},
    classList: classListStub()
  };
}

function unlockedSession(nickname = "misha_boy") {
  return {
    authenticated: true,
    profile: {
      nickname,
      nicknameConfirmed: true
    }
  };
}

function schedulerStub() {
  let now = 0;
  let nextId = 1;
  const jobs = new Map();
  const history = new Map();

  return {
    scheduleTimeout(callback, delay) {
      const id = nextId;
      nextId += 1;
      const job = { callback, delay, dueAt: now + delay };
      jobs.set(id, job);
      history.set(id, job);
      return id;
    },
    cancelTimeout(id) {
      jobs.delete(id);
    },
    advance(milliseconds) {
      now += milliseconds;
      const ready = [...jobs.entries()]
        .filter(([, job]) => job.dueAt <= now)
        .sort((left, right) => left[1].dueAt - right[1].dueAt);
      for (const [id, job] of ready) {
        if (!jobs.delete(id)) continue;
        job.callback();
      }
    },
    forceRun(id) {
      history.get(id)?.callback();
    },
    pending() {
      return [...jobs.entries()].map(([id, job]) => ({ id, delay: job.delay }));
    }
  };
}

function controllerFixture() {
  const menuScene = elementStub();
  const menuPet = elementStub();
  const gameplayPet = elementStub();
  const dialog = elementStub();
  const gameArea = elementStub();
  const streakMeter = elementStub();
  const scheduler = schedulerStub();
  const board = {
    getBoundingClientRect() {
      return { left: 20, width: 200 };
    }
  };
  const controller = createMishaController({
    menuScene,
    menuPet,
    gameplayPet,
    board,
    dialog,
    gameArea,
    streakMeter,
    scheduleTimeout: scheduler.scheduleTimeout,
    cancelTimeout: scheduler.cancelTimeout
  });
  return {
    controller,
    dialog,
    gameArea,
    gameplayPet,
    menuPet,
    menuScene,
    scheduler,
    streakMeter
  };
}

test("Misha unlocks only for a confirmed authenticated matching profile", () => {
  assert.equal(normalizeMishaNickname("  Misha_Boy  "), "misha_boy");
  assert.equal(profileUnlocksMisha(unlockedSession()), true);
  assert.equal(profileUnlocksMisha(unlockedSession("Misha_Boy")), true);
  assert.equal(profileUnlocksMisha(unlockedSession("misha-boy")), false);
  assert.equal(profileUnlocksMisha({ ...unlockedSession(), authenticated: false }), false);
  assert.equal(
    profileUnlocksMisha({
      ...unlockedSession(),
      profile: { nickname: "misha_boy", nicknameConfirmed: false }
    }),
    false
  );
});

test("Misha faces accepted taps relative to the board midpoint", () => {
  const boardRect = { left: 100, width: 200 };
  assert.equal(resolveMishaFacing(130, boardRect, "front"), "left");
  assert.equal(resolveMishaFacing(270, boardRect, "left"), "right");
  assert.equal(resolveMishaFacing(199, boardRect, "right"), "left");
  assert.equal(resolveMishaFacing(201, boardRect, "left"), "right");
  assert.equal(resolveMishaFacing(200, boardRect, "right"), "right");
  assert.equal(resolveMishaFacing(Number.NaN, boardRect, "left"), "left");
  assert.equal(resolveMishaFacing(130, { left: 100, width: 0 }, "unexpected"), "front");
});

test("Misha sleeps after five seconds and wakes toward non-game taps", () => {
  const { controller, menuPet, menuScene, scheduler } = controllerFixture();

  controller.setProfileSession(unlockedSession());
  assert.equal(menuScene.hidden, false);
  assert.equal(menuPet.hidden, false);
  assert.equal(menuPet.dataset.pose, "awake");
  assert.equal(menuPet.dataset.facing, "front");
  assert.deepEqual(scheduler.pending().map(({ delay }) => delay), [MISHA_IDLE_DELAY_MS]);

  scheduler.advance(MISHA_IDLE_DELAY_MS - 1);
  assert.equal(menuPet.dataset.pose, "awake");
  scheduler.advance(1);
  assert.equal(menuPet.dataset.pose, "sleeping");
  assert.equal(scheduler.pending().length, 0);

  assert.equal(controller.handleNonGameTap(40, { left: 0, width: 300 }), "left");
  assert.equal(menuPet.dataset.pose, "awake");
  assert.equal(menuPet.dataset.facing, "left");
  const leftTimer = scheduler.pending()[0].id;
  assert.equal(controller.handleNonGameTap(260, { left: 0, width: 300 }), "right");
  assert.equal(menuPet.dataset.facing, "right");
  assert.equal(scheduler.pending().length, 1);
  assert.notEqual(scheduler.pending()[0].id, leftTimer);

  scheduler.advance(MISHA_IDLE_DELAY_MS);
  assert.equal(menuPet.dataset.pose, "sleeping");
  controller.setProfileSession(unlockedSession("Misha_Boy"));
  assert.equal(menuPet.dataset.pose, "sleeping");
  assert.equal(scheduler.pending().length, 0);
});

test("cancelled idle callbacks cannot sleep Misha after a newer tap", () => {
  const { controller, menuPet, scheduler } = controllerFixture();
  controller.setProfileSession(unlockedSession());
  const staleTimer = scheduler.pending()[0].id;
  controller.handleNonGameTap(260, { left: 0, width: 300 });

  scheduler.forceRun(staleTimer);
  assert.equal(menuPet.dataset.pose, "awake");
  assert.equal(menuPet.dataset.facing, "right");
  assert.equal(scheduler.pending().length, 1);
});

test("Misha moves between menu and gameplay while the climber stays main-menu only", () => {
  const {
    controller,
    dialog,
    gameArea,
    gameplayPet,
    menuPet,
    menuScene,
    scheduler,
    streakMeter
  } = controllerFixture();

  controller.setProfileSession(unlockedSession());
  assert.equal(menuScene.dataset.climber, "true");
  assert.equal(dialog.classList.contains("dialog--with-misha"), true);

  controller.setMenuView("profile");
  assert.equal(menuScene.dataset.climber, "false");
  assert.equal(menuScene.hidden, false);
  controller.setMenuView("menu");
  assert.equal(menuScene.dataset.climber, "true");

  controller.setGameplayVisible(true);
  assert.equal(menuScene.hidden, true);
  assert.equal(menuPet.hidden, true);
  assert.equal(gameplayPet.hidden, false);
  assert.equal(gameplayPet.dataset.facing, "front");
  assert.equal(gameArea.classList.contains("game--with-misha"), true);
  assert.equal(streakMeter.classList.contains("streak-meter--with-misha"), true);
  assert.equal(controller.turnToward(40), "left");
  assert.equal(gameplayPet.dataset.facing, "left");
  assert.equal(controller.turnToward(205), "right");
  assert.equal(gameplayPet.dataset.facing, "right");
  assert.equal(scheduler.pending().length, 0);
  assert.equal(controller.handleNonGameTap(40, { left: 0, width: 300 }), "front");

  controller.setGameplayVisible(true);
  assert.equal(gameplayPet.dataset.facing, "front");

  controller.setGameplayVisible(false);
  assert.equal(menuScene.hidden, false);
  assert.equal(menuPet.hidden, false);
  assert.equal(menuPet.dataset.pose, "awake");
  assert.equal(menuPet.dataset.facing, "front");
  assert.equal(gameplayPet.hidden, true);
  assert.equal(scheduler.pending().length, 1);

  controller.setProfileSession(unlockedSession("someone_else"));
  assert.equal(menuScene.hidden, true);
  assert.equal(menuPet.hidden, true);
  assert.equal(gameplayPet.hidden, true);
  assert.equal(dialog.classList.contains("dialog--with-misha"), false);
  assert.equal(scheduler.pending().length, 0);
});
