export const MISHA_EASTER_EGG_NICKNAME = "misha_boy";

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
  menuPet,
  gameplayPet,
  board,
  dialog,
  gameArea,
  streakMeter
}) {
  if (!menuPet || !gameplayPet || !board || !dialog || !gameArea || !streakMeter) {
    throw new TypeError("Misha controller requires both pet views and their layout anchors.");
  }

  let unlocked = false;
  let gameplayVisible = false;

  function render() {
    const showMenuPet = unlocked && !gameplayVisible;
    const showGameplayPet = unlocked && gameplayVisible;
    menuPet.hidden = !showMenuPet;
    gameplayPet.hidden = !showGameplayPet;
    dialog.classList.toggle("dialog--with-misha", showMenuPet);
    gameArea.classList.toggle("game--with-misha", showGameplayPet);
    streakMeter.classList.toggle("streak-meter--with-misha", showGameplayPet);
  }

  return Object.freeze({
    setProfileSession(session) {
      unlocked = profileUnlocksMisha(session);
      render();
      return unlocked;
    },

    setGameplayVisible(visible) {
      const nextVisible = visible === true;
      if (nextVisible) gameplayPet.dataset.facing = "front";
      gameplayVisible = nextVisible;
      render();
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
