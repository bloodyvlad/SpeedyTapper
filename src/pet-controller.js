import { getPet, isPetId } from "./pet-catalog.js?v=20260714-1";

export const LEGACY_MISHA_NICKNAME = "misha_boy";
export const PET_IDLE_DELAY_MS = 5_000;
export const PET_TURN_DURATION_MS = 300;
export const PET_TRANSITION_DURATION_MS = 450;

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
  rect,
  fallback = "front"
) {
  const current = normalizedFacing(fallback);
  if (!Number.isFinite(pointerX) || !rect) return current;
  const left = Number(rect.left);
  const width = Number(rect.width);
  if (!Number.isFinite(left) || !Number.isFinite(width) || width <= 0) return current;
  const ratio = (pointerX - left) / width;
  if (ratio < 0.25) return "left";
  if (ratio < 0.45) return "half-left";
  if (ratio <= 0.55) return "front";
  if (ratio < 0.75) return "half-right";
  return "right";
}

export function resolvePancakeFacing(pointerX, rect, fallback = "right") {
  const current = fallback === "left" ? "left" : "right";
  if (!Number.isFinite(pointerX) || !rect) return current;
  const left = Number(rect.left);
  const width = Number(rect.width);
  if (!Number.isFinite(left) || !Number.isFinite(width) || width <= 0) return current;
  return pointerX < left + width / 2 ? "left" : "right";
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
    syncScene(menuScene, showMenu, showMenu && (menuView === "menu" || isPancake()));
    syncScene(gameplayScene, showGameplay, showGameplay && isPancake());
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
    facing = normalizedFacing(nextFacing, facing);
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

    handleNonGameTap(pointerX, viewportRect) {
      if (petId === null || gameplayVisible) return facing;
      const nextFacing = isPancake()
        ? resolvePancakeFacing(pointerX, viewportRect, facing)
        : resolvePetFacing(pointerX, viewportRect, facing);
      animateToward(nextFacing);
      return nextFacing;
    },

    handleGameplayTap(pointerX) {
      if (petId === null || !gameplayVisible) return facing;
      const nextFacing = isPancake()
        ? resolvePancakeFacing(pointerX, board.getBoundingClientRect(), facing)
        : resolvePetFacing(pointerX, board.getBoundingClientRect(), facing);
      animateToward(nextFacing);
      if (!isPancake()) cancelIdleTimer();
      return nextFacing;
    },

    getState() {
      return Object.freeze({ petId, gameplayVisible, menuView, pose, facing });
    }
  });
}
