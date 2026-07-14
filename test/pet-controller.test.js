import assert from "node:assert/strict";
import test from "node:test";

import {
  createPetController,
  LEGACY_MISHA_NICKNAME,
  normalizeLegacyMishaNickname,
  PET_HALF_TURN_MAX_DEGREES,
  PET_IDLE_DELAY_MS,
  PET_TRANSITION_DURATION_MS,
  resolveEquippedPetId,
  resolvePancakeFacing,
  resolvePetFacing
} from "../src/pet-controller.js";

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

function elementStub(rect = { left: 0, top: 0, width: 64, height: 64 }) {
  const sprite = { getBoundingClientRect: () => ({ ...rect }) };
  return {
    hidden: true,
    dataset: {},
    classList: classListStub(),
    getBoundingClientRect: () => ({ ...rect }),
    querySelector(selector) {
      return selector === ".pet-sprite" ? sprite : null;
    }
  };
}

function persistedSession(petId, ownedPetIds = [petId]) {
  return {
    authenticated: true,
    profile: {
      nickname: "Player",
      nicknameConfirmed: true,
      ownedPetIds,
      equippedPetId: petId
    }
  };
}

function legacyMishaSession(nickname = LEGACY_MISHA_NICKNAME) {
  return {
    authenticated: true,
    profile: { nickname, nicknameConfirmed: true }
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
      let ready;
      do {
        ready = [...jobs.entries()]
          .filter(([, job]) => job.dueAt <= now)
          .sort((left, right) => left[1].dueAt - right[1].dueAt);
        for (const [id, job] of ready) {
          if (!jobs.delete(id)) continue;
          job.callback();
        }
      } while (ready.length > 0 && [...jobs.values()].some((job) => job.dueAt <= now));
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
  const menuScene = elementStub({ left: 288, top: 64, width: 64, height: 64 });
  const gameplayScene = elementStub({ left: 100, top: 260, width: 64, height: 64 });
  const dialog = elementStub();
  const gameArea = elementStub();
  const streakMeter = elementStub();
  const scheduler = schedulerStub();
  const board = { getBoundingClientRect: () => ({ left: 20, width: 200 }) };
  const controller = createPetController({
    menuScene,
    gameplayScene,
    board,
    dialog,
    gameArea,
    streakMeter,
    scheduleTimeout: scheduler.scheduleTimeout,
    cancelTimeout: scheduler.cancelTimeout
  });
  return { controller, dialog, gameArea, gameplayScene, menuScene, scheduler, streakMeter };
}

test("persisted selection is authoritative while old servers retain the Misha fallback", () => {
  assert.equal(normalizeLegacyMishaNickname("  Misha_Boy  "), "misha_boy");
  assert.equal(resolveEquippedPetId(persistedSession("tauta")), "tauta");
  assert.equal(resolveEquippedPetId(legacyMishaSession("Misha_Boy")), "misha");
  assert.equal(resolveEquippedPetId(legacyMishaSession("misha-boy")), null);
  assert.equal(resolveEquippedPetId({ ...legacyMishaSession(), authenticated: false }), null);
  assert.equal(resolveEquippedPetId({
    ...legacyMishaSession(),
    profile: { ...legacyMishaSession().profile, ownedPetIds: [], equippedPetId: null }
  }), null);
  assert.equal(resolveEquippedPetId({
    authenticated: true,
    profile: {
      nickname: "Player",
      nicknameConfirmed: true,
      ownedPetIds: ["tauta"],
      selectedPetId: "tauta",
      petVisible: false,
      equippedPetId: null
    }
  }), null, "A hidden selected pet remains out of both menu and gameplay scenes.");
});

test("pet taps resolve full and half-facing positions", () => {
  const rect = { left: 288, top: 80, width: 64, height: 64 };
  const centerX = rect.left + rect.width / 2;
  const centerY = rect.top + rect.height / 2;
  const verticalDistance = 100;
  const halfTurnDelta = Math.tan(PET_HALF_TURN_MAX_DEGREES * Math.PI / 180)
    * verticalDistance;

  assert.equal(
    resolvePetFacing(centerX - halfTurnDelta, centerY + verticalDistance, rect),
    "half-left",
    "The exact 30-degree left boundary remains a half turn."
  );
  assert.equal(
    resolvePetFacing(centerX - halfTurnDelta - 0.1, centerY + verticalDistance, rect),
    "left"
  );
  assert.equal(resolvePetFacing(centerX + 1, centerY + 80, rect), "front");
  assert.equal(
    resolvePetFacing(centerX + halfTurnDelta, centerY + verticalDistance, rect),
    "half-right",
    "The exact 30-degree right boundary remains a half turn."
  );
  assert.equal(
    resolvePetFacing(centerX + halfTurnDelta + 0.1, centerY + verticalDistance, rect),
    "right"
  );
  assert.equal(
    resolvePetFacing(300, 210, rect),
    "half-left",
    "A tap near an upper-right iPhone SE menu pet is relative to the pet, not the viewport."
  );
  assert.equal(resolvePetFacing(Number.NaN, 120, rect, "half-left"), "half-left");
  assert.equal(resolvePancakeFacing(centerX - 1, rect), "left");
  assert.equal(resolvePancakeFacing(centerX, rect), "right");
});

test("pets settle at five seconds, use the intermediate pose, and wake toward taps", () => {
  const { controller, menuScene, scheduler } = controllerFixture();
  controller.setProfileSession(persistedSession("misha"));
  assert.equal(menuScene.hidden, false);
  assert.equal(menuScene.dataset.pose, "awake");
  assert.deepEqual(scheduler.pending().map(({ delay }) => delay), [PET_IDLE_DELAY_MS]);

  scheduler.advance(PET_IDLE_DELAY_MS - 1);
  assert.equal(menuScene.dataset.pose, "awake");
  scheduler.advance(1);
  assert.equal(menuScene.dataset.pose, "settling");
  assert.deepEqual(scheduler.pending().map(({ delay }) => delay), [PET_TRANSITION_DURATION_MS]);
  scheduler.advance(PET_TRANSITION_DURATION_MS);
  assert.equal(menuScene.dataset.pose, "sleeping");

  assert.equal(controller.handleNonGameTap(220, 96), "left");
  assert.equal(menuScene.dataset.pose, "waking");
  assert.equal(menuScene.dataset.facing, "left");
  scheduler.advance(PET_TRANSITION_DURATION_MS);
  assert.equal(menuScene.dataset.pose, "awake");
  assert.equal(menuScene.dataset.facing, "left", "The selected pose persists after the turn animation.");
});

test("cancelled idle work cannot override a newer pet tap", () => {
  const { controller, menuScene, scheduler } = controllerFixture();
  controller.setProfileSession(persistedSession("tauta"));
  const staleTimer = scheduler.pending()[0].id;
  controller.handleNonGameTap(400, 96);
  scheduler.forceRun(staleTimer);
  assert.equal(menuScene.dataset.pose, "turning");
  assert.equal(menuScene.dataset.facing, "right");
});

test("the habitat follows every non-game screen and stays out of gameplay", () => {
  const { controller, dialog, gameArea, gameplayScene, menuScene, streakMeter } = controllerFixture();
  controller.setProfileSession(persistedSession("foka"));
  assert.equal(menuScene.dataset.habitat, "true");
  assert.equal(dialog.classList.contains("dialog--with-pet"), true);

  controller.setMenuView("profile");
  assert.equal(menuScene.dataset.habitat, "true");
  assert.equal(menuScene.hidden, false);
  controller.setGameplayVisible(true);
  assert.equal(menuScene.hidden, true);
  assert.equal(gameplayScene.hidden, false);
  assert.equal(gameplayScene.dataset.habitat, "false");
  assert.equal(gameArea.classList.contains("game--with-pet"), true);
  assert.equal(streakMeter.classList.contains("streak-meter--with-pet"), true);
  assert.equal(controller.handleGameplayTap(112, 360), "half-left");

  controller.setProfileSession({ authenticated: true, profile: { ownedPetIds: [], equippedPetId: null } });
  assert.equal(menuScene.hidden, true);
  assert.equal(gameplayScene.hidden, true);
});

test("Pancake uses only horizontal left-right direction and rests down after inactivity", () => {
  const { controller, gameplayScene, menuScene, scheduler } = controllerFixture();
  controller.setProfileSession(persistedSession("pancake"));
  assert.equal(menuScene.dataset.pose, "stopped");
  assert.equal(menuScene.dataset.facing, "right");
  assert.equal(menuScene.dataset.habitat, "true");

  assert.equal(controller.handleNonGameTap(20, 96), "left");
  assert.equal(menuScene.dataset.pose, "dancing");
  scheduler.advance(PET_IDLE_DELAY_MS);
  assert.equal(menuScene.dataset.pose, "stopped");

  controller.setGameplayVisible(true);
  assert.equal(gameplayScene.dataset.pose, "dancing");
  assert.equal(gameplayScene.dataset.habitat, "false");
  assert.equal(controller.handleGameplayTap(219, 292), "right");
  scheduler.advance(PET_IDLE_DELAY_MS);
  assert.equal(gameplayScene.dataset.pose, "stopped");
  controller.setGameplayVisible(false);
  assert.equal(menuScene.dataset.pose, "stopped");
});
