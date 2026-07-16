import { getPet, isPetId, isSpecialPetId } from "./pet-catalog.js?v=20260716-1";

export const LEGACY_MISHA_NICKNAME = "misha_boy";
export const PET_IDLE_DELAY_MS = 5_000;
export const PET_TURN_DURATION_MS = 300;
export const PET_TRANSITION_DURATION_MS = 450;
export const PET_HALF_TURN_MAX_DEGREES = 30;

const PET_FRONT_DEAD_ZONE_PX = 2;
const PET_ANGLE_EPSILON_DEGREES = 1e-9;

const PET_FACINGS = new Set(["front", "half-left", "left", "half-right", "right"]);

function normalizedFacing(value, fallback = "front") {
  return PET_FACINGS.has(value) ? value : fallback;
}

export function normalizeLegacyMishaNickname(value) {
  return typeof value === "string" ? value.normalize("NFKC").trim().toLowerCase() : "";
}

export function resolveEquippedPetId(session) {
  if (session?.authenticated !== true || !session.profile || typeof session.profile !== "object") {
    return null;
  }
  if (isSpecialPetId(session.profile.specialPetId)) {
    return session.profile.specialPetId;
  }
  if (isPetId(session.profile.equippedPetId)) {
    return session.profile.equippedPetId;
  }
  const hasPersistedPetState = Object.hasOwn(session.profile, "equippedPetId")
    || Object.hasOwn(session.profile, "ownedPetIds");
  if (
    !hasPersistedPetState
    && session.profile.nicknameConfirmed === true
    && normalizeLegacyMishaNickname(session.profile.nickname) === LEGACY_MISHA_NICKNAME
  ) {
    return "misha";
  }
  return null;
}

export function resolvePetFacing(
  pointerX,
  pointerY,
  rect,
  fallback = "front"
) {
  const current = normalizedFacing(fallback);
  if (!Number.isFinite(pointerX) || !Number.isFinite(pointerY) || !rect) return current;
  const left = Number(rect.left);
  const top = Number(rect.top);
  const width = Number(rect.width);
  const height = Number(rect.height);
  if (
    !Number.isFinite(left)
    || !Number.isFinite(top)
    || !Number.isFinite(width)
    || !Number.isFinite(height)
    || width <= 0
    || height <= 0
  ) return current;

  const deltaX = pointerX - (left + width / 2);
  if (Math.abs(deltaX) <= PET_FRONT_DEAD_ZONE_PX) return "front";

  const deltaY = pointerY - (top + height / 2);
  const angleDegrees = Math.atan2(
    Math.abs(deltaX),
    Math.max(Math.abs(deltaY), 1)
  ) * 180 / Math.PI;
  const side = deltaX < 0 ? "left" : "right";
  return angleDegrees <= PET_HALF_TURN_MAX_DEGREES + PET_ANGLE_EPSILON_DEGREES
    ? `half-${side}`
    : side;
}

export function resolvePancakeFacing(pointerX, rect, fallback = "right") {
  const current = fallback === "left" ? "left" : "right";
  if (!Number.isFinite(pointerX) || !rect) return current;
  const left = Number(rect.left);
  const width = Number(rect.width);
  if (!Number.isFinite(left) || !Number.isFinite(width) || width <= 0) return current;
  return pointerX < left + width / 2 ? "left" : "right";
}

function getPetSpriteRect(scene) {
  const sprite = typeof scene?.querySelector === "function"
    ? scene.querySelector(".pet-sprite")
    : null;
  if (typeof sprite?.getBoundingClientRect === "function") {
    return sprite.getBoundingClientRect();
  }
  return typeof scene?.getBoundingClientRect === "function"
    ? scene.getBoundingClientRect()
    : null;
}

export function createPetController({
  menuScene,
  gameplayScene,
  board,
  dialog,
  gameArea,
  streakMeter,
  scheduleTimeout = (callback, delay) => globalThis.setTimeout(callback, delay),
  cancelTimeout = (timerId) => globalThis.clearTimeout(timerId)
}) {
  if (!menuScene || !gameplayScene || !board || !dialog || !gameArea || !streakMeter) {
    throw new TypeError("Pet controller requires both scenes and their layout anchors.");
  }
  if (typeof scheduleTimeout !== "function" || typeof cancelTimeout !== "function") {
    throw new TypeError("Pet controller requires timeout scheduling functions.");
  }

  let petId = null;
  let gameplayVisible = false;
  let menuView = "menu";
  let pose = "awake";
  let facing = "front";
  let idleTimer = null;
  let idleGeneration = 0;
  let transitionTimer = null;
  let transitionGeneration = 0;

  function currentPet() {
    return getPet(petId);
  }

  function isPancake() {
    return petId === "pancake";
  }

  function cancelIdleTimer() {
    idleGeneration += 1;
    if (idleTimer !== null) cancelTimeout(idleTimer);
    idleTimer = null;
  }

  function cancelTransitionTimer() {
    transitionGeneration += 1;
    if (transitionTimer !== null) cancelTimeout(transitionTimer);
    transitionTimer = null;
  }

  function syncScene(scene, visible, habitat) {
    scene.hidden = !visible;
    scene.dataset.pet = petId ?? "none";
    scene.dataset.facing = facing;
    scene.dataset.pose = pose;
    scene.dataset.habitat = String(habitat);
  }

  function render() {
    const showMenu = petId !== null && !gameplayVisible;
    const showGameplay = petId !== null && gameplayVisible;
    syncScene(menuScene, showMenu, showMenu);
    syncScene(gameplayScene, showGameplay, false);
    dialog.classList.toggle("dialog--with-pet", showMenu);
    gameArea.classList.toggle("game--with-pet", showGameplay);
    streakMeter.classList.toggle("streak-meter--with-pet", showGameplay);
  }

  function finishTransition(nextPose, delay = PET_TRANSITION_DURATION_MS) {
    cancelTransitionTimer();
    const generation = transitionGeneration;
    transitionTimer = scheduleTimeout(() => {
      if (generation !== transitionGeneration || petId === null) return;
      transitionTimer = null;
      pose = nextPose;
      render();
    }, delay);
  }

  function enterIdlePose() {
    if (petId === null) return;
    cancelTransitionTimer();
    if (isPancake()) {
      pose = "stopped";
      render();
      return;
    }
    pose = "settling";
    render();
    finishTransition(currentPet()?.idlePose ?? "sleeping");
  }

  function scheduleIdle() {
    cancelIdleTimer();
    if (petId === null || (!isPancake() && gameplayVisible)) return;
    const generation = idleGeneration;
    idleTimer = scheduleTimeout(() => {
      if (generation !== idleGeneration || petId === null) return;
      if (!isPancake() && gameplayVisible) return;
      idleTimer = null;
      enterIdlePose();
    }, PET_IDLE_DELAY_MS);
  }

  function animateToward(nextFacing) {
    const wasIdle = pose === "sleeping" || pose === "stopped";
    const targetFacing = normalizedFacing(nextFacing, facing);
    const requiresWake = wasIdle || pose === "settling";
    if (targetFacing === facing && !requiresWake) {
      scheduleIdle();
      return;
    }
    facing = targetFacing;
    cancelTransitionTimer();
    if (isPancake()) {
      pose = "dancing";
      render();
      scheduleIdle();
      return;
    }
    pose = wasIdle || pose === "settling" ? "waking" : "turning";
    render();
    finishTransition("awake", pose === "turning" ? PET_TURN_DURATION_MS : PET_TRANSITION_DURATION_MS);
    scheduleIdle();
  }

  return Object.freeze({
    setProfileSession(session) {
      const nextPetId = resolveEquippedPetId(session);
      if (nextPetId !== petId) {
        cancelIdleTimer();
        cancelTransitionTimer();
        petId = nextPetId;
        facing = isPancake() ? "right" : "front";
        pose = isPancake() ? "stopped" : "awake";
        if (petId !== null && !gameplayVisible && !isPancake()) scheduleIdle();
      }
      render();
      return petId;
    },

    setGameplayVisible(visible) {
      const nextVisible = visible === true;
      if (nextVisible === gameplayVisible) {
        render();
        return;
      }
      gameplayVisible = nextVisible;
      cancelIdleTimer();
      cancelTransitionTimer();
      facing = isPancake() ? "right" : "front";
      if (petId !== null) {
        pose = isPancake()
          ? gameplayVisible ? "dancing" : "stopped"
          : "awake";
        if ((isPancake() && gameplayVisible) || (!isPancake() && !gameplayVisible)) {
          scheduleIdle();
        }
      }
      render();
    },

    setMenuView(view) {
      menuView = view === "menu" ? "menu" : "other";
      render();
    },

    handleNonGameTap(pointerX, pointerY) {
      if (petId === null || gameplayVisible) return facing;
      const petRect = getPetSpriteRect(menuScene);
      const nextFacing = isPancake()
        ? resolvePancakeFacing(pointerX, petRect, facing)
        : resolvePetFacing(pointerX, pointerY, petRect, facing);
      animateToward(nextFacing);
      return nextFacing;
    },

    handleGameplayTap(pointerX, pointerY) {
      if (petId === null || !gameplayVisible) return facing;
      const petRect = getPetSpriteRect(gameplayScene);
      const nextFacing = isPancake()
        ? resolvePancakeFacing(pointerX, petRect, facing)
        : resolvePetFacing(pointerX, pointerY, petRect, facing);
      animateToward(nextFacing);
      if (!isPancake()) cancelIdleTimer();
      return nextFacing;
    },

    getState() {
      return Object.freeze({ petId, gameplayVisible, menuView, pose, facing });
    }
  });
}
