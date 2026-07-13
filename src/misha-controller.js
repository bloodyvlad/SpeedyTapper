export const MISHA_EASTER_EGG_NICKNAME = "misha_boy";
export const MISHA_IDLE_DELAY_MS = 5_000;

const MISHA_FACINGS = new Set(["front", "left", "right"]);

function normalizedFacing(value) {
  return MISHA_FACINGS.has(value) ? value : "front";
}

export function normalizeMishaNickname(value) {
  if (typeof value !== "string") return "";
  return value.normalize("NFKC").trim().toLowerCase();
}

export function profileUnlocksMisha(session) {
  return session?.authenticated === true &&
    session.profile?.nicknameConfirmed === true &&
    normalizeMishaNickname(session.profile.nickname) === MISHA_EASTER_EGG_NICKNAME;
}

export function resolveMishaFacing(
  pointerX,
  boardRect,
  currentFacing = "front"
) {
  const fallback = normalizedFacing(currentFacing);
  const left = Number(boardRect?.left);
  const width = Number(boardRect?.width);
  if (!Number.isFinite(pointerX) || !Number.isFinite(left) || !Number.isFinite(width) || width <= 0) {
    return fallback;
  }

  const midpoint = left + width / 2;
  if (pointerX === midpoint) return fallback;
  return pointerX < midpoint ? "left" : "right";
}

export function createMishaController({
  menuScene,
  menuPet,
  gameplayPet,
  board,
  dialog,
  gameArea,
  streakMeter,
  scheduleTimeout = (callback, delay) => globalThis.setTimeout(callback, delay),
  cancelTimeout = (timerId) => globalThis.clearTimeout(timerId)
}) {
  if (!menuScene || !menuPet || !gameplayPet || !board || !dialog || !gameArea || !streakMeter) {
    throw new TypeError("Misha controller requires both pet views, the menu scene, and layout anchors.");
  }
  if (typeof scheduleTimeout !== "function" || typeof cancelTimeout !== "function") {
    throw new TypeError("Misha controller requires timeout scheduling functions.");
  }

  let unlocked = false;
  let gameplayVisible = false;
  let menuView = "menu";
  let menuPose = "awake";
  let menuFacing = "front";
  let idleTimer = null;
  let idleGeneration = 0;

  function syncMenuPetState() {
    menuPet.dataset.pose = menuPose;
    menuPet.dataset.facing = menuFacing;
  }

  function cancelIdleTimer() {
    idleGeneration += 1;
    if (idleTimer !== null) cancelTimeout(idleTimer);
    idleTimer = null;
  }

  function scheduleSleep() {
    cancelIdleTimer();
    if (!unlocked || gameplayVisible) return;
    const generation = idleGeneration;
    idleTimer = scheduleTimeout(() => {
      if (generation !== idleGeneration || !unlocked || gameplayVisible) return;
      idleTimer = null;
      menuPose = "sleeping";
      syncMenuPetState();
    }, MISHA_IDLE_DELAY_MS);
  }

  function wakeMenu(facing = menuFacing) {
    menuPose = "awake";
    menuFacing = normalizedFacing(facing);
    syncMenuPetState();
    scheduleSleep();
  }

  function render() {
    const showMenuPet = unlocked && !gameplayVisible;
    const showGameplayPet = unlocked && gameplayVisible;
    menuScene.hidden = !showMenuPet;
    menuPet.hidden = !showMenuPet;
    gameplayPet.hidden = !showGameplayPet;
    menuScene.dataset.climber = String(menuView === "menu");
    dialog.classList.toggle("dialog--with-misha", showMenuPet);
    gameArea.classList.toggle("game--with-misha", showGameplayPet);
    streakMeter.classList.toggle("streak-meter--with-misha", showGameplayPet);
  }

  return Object.freeze({
    setProfileSession(session) {
      const nextUnlocked = profileUnlocksMisha(session);
      if (nextUnlocked !== unlocked) {
        unlocked = nextUnlocked;
        if (unlocked && !gameplayVisible) {
          wakeMenu("front");
        } else if (!unlocked) {
          cancelIdleTimer();
          menuPose = "awake";
          menuFacing = "front";
          syncMenuPetState();
        }
      }
      render();
      return unlocked;
    },

    setGameplayVisible(visible) {
      const nextVisible = visible === true;
      const wasVisible = gameplayVisible;
      gameplayVisible = nextVisible;
      if (gameplayVisible) {
        cancelIdleTimer();
        gameplayPet.dataset.facing = "front";
      } else if (wasVisible && unlocked) {
        wakeMenu("front");
      }
      render();
    },

    setMenuView(view) {
      menuView = view === "menu" ? "menu" : "other";
      render();
    },

    handleNonGameTap(pointerX, viewportRect) {
      if (!unlocked || gameplayVisible) return menuFacing;
      const nextFacing = resolveMishaFacing(pointerX, viewportRect, menuFacing);
      wakeMenu(nextFacing);
      return nextFacing;
    },

    turnToward(pointerX) {
      if (!unlocked || !gameplayVisible) return gameplayPet.dataset.facing ?? "front";
      const nextFacing = resolveMishaFacing(
        pointerX,
        board.getBoundingClientRect(),
        gameplayPet.dataset.facing
      );
      if (nextFacing !== gameplayPet.dataset.facing) gameplayPet.dataset.facing = nextFacing;
      return nextFacing;
    }
  });
}
