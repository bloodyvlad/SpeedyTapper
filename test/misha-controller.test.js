import assert from "node:assert/strict";
import test from "node:test";

import {
  createMishaController,
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

test("Misha moves between menu and gameplay placements without affecting locked profiles", () => {
  const menuPet = elementStub();
  const gameplayPet = elementStub();
  const dialog = elementStub();
  const gameArea = elementStub();
  const streakMeter = elementStub();
  const board = {
    getBoundingClientRect() {
      return { left: 20, width: 200 };
    }
  };
  const controller = createMishaController({
    menuPet,
    gameplayPet,
    board,
    dialog,
    gameArea,
    streakMeter
  });

  controller.setProfileSession(unlockedSession());
  assert.equal(menuPet.hidden, false);
  assert.equal(gameplayPet.hidden, true);
  assert.equal(dialog.classList.contains("dialog--with-misha"), true);

  controller.setGameplayVisible(true);
  assert.equal(menuPet.hidden, true);
  assert.equal(gameplayPet.hidden, false);
  assert.equal(gameplayPet.dataset.facing, "front");
  assert.equal(gameArea.classList.contains("game--with-misha"), true);
  assert.equal(streakMeter.classList.contains("streak-meter--with-misha"), true);
  assert.equal(controller.turnToward(40), "left");
  assert.equal(gameplayPet.dataset.facing, "left");
  assert.equal(controller.turnToward(205), "right");
  assert.equal(gameplayPet.dataset.facing, "right");

  controller.setGameplayVisible(true);
  assert.equal(gameplayPet.dataset.facing, "front");

  controller.setGameplayVisible(false);
  assert.equal(menuPet.hidden, false);
  assert.equal(gameplayPet.hidden, true);

  controller.setProfileSession(unlockedSession("someone_else"));
  assert.equal(menuPet.hidden, true);
  assert.equal(gameplayPet.hidden, true);
  assert.equal(dialog.classList.contains("dialog--with-misha"), false);
});
