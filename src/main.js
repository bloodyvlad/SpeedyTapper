import { COLORS } from "./config.js";
import { GameEngine, GAME_STATES } from "./game-engine.js";

const HIGH_SCORE_KEY = "speedytapper.highScore.v1";

const elements = {
  board: document.querySelector("#board"),
  colorName: document.querySelector("#color-name"),
  colorSwatch: document.querySelector("#color-swatch"),
  dialogMessage: document.querySelector("#dialog-message"),
  dialogTitle: document.querySelector("#dialog-title"),
  feedback: document.querySelector("#feedback"),
  highScore: document.querySelector("#high-score"),
  hint: document.querySelector("#hint"),
  installButton: document.querySelector("#install-button"),
  instruction: document.querySelector("#instruction"),
  lives: document.querySelector("#lives"),
  overlay: document.querySelector("#overlay"),
  phase: document.querySelector("#phase"),
  points: document.querySelector("#points"),
  startButton: document.querySelector("#start-button")
};

const engine = new GameEngine();
let highScore = readHighScore();
let spawnTimer = null;
let deadlineTimer = null;
let feedbackTimer = null;
let sessionId = 0;
let deferredInstallPrompt = null;

function now() {
  return performance.now();
}

function readHighScore() {
  try {
    const storedValue = Number.parseInt(localStorage.getItem(HIGH_SCORE_KEY) ?? "0", 10);
    return Number.isFinite(storedValue) && storedValue > 0 ? storedValue : 0;
  } catch {
    return 0;
  }
}

function saveHighScore(score) {
  if (score <= highScore) return;
  highScore = score;
  try {
    localStorage.setItem(HIGH_SCORE_KEY, String(score));
  } catch {
    // Private browsing or storage restrictions should not stop the game.
  }
}

function clearTimers() {
  window.clearTimeout(spawnTimer);
  window.clearTimeout(deadlineTimer);
  spawnTimer = null;
  deadlineTimer = null;
}

function startGame() {
  clearTimers();
  sessionId += 1;
  engine.start(now());
  elements.overlay.hidden = true;
  elements.startButton.textContent = "Play again";
  elements.dialogTitle.textContent = "SpeedyTapper Lab";
  render();
  scheduleRound(sessionId);
}

function scheduleRound(currentSession) {
  if (engine.state === GAME_STATES.GAME_OVER || currentSession !== sessionId) return;

  const delayMs = engine.getNextDelayMs(now());
  elements.instruction.textContent = "Wait for your color";
  spawnTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden) return;
    const result = engine.activateRound(now());
    if (result.type !== "round-active") return;
    elements.instruction.textContent = "Tap!";
    render();
    scheduleDeadline(currentSession, result.snapshot.difficulty.responseWindowMs);
  }, delayMs);
}

function scheduleDeadline(currentSession, delayMs) {
  deadlineTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden) return;
    const result = engine.expireRound(now());
    if (result.type === "ignored" && result.reason === "not-expired") {
      scheduleDeadline(currentSession, Math.ceil(result.remainingMs));
      return;
    }
    if (result.type !== "miss") return;
    handleMiss(result, currentSession);
  }, delayMs);
}

function handleTileTap(event) {
  event.preventDefault();
  const cellIndex = Number.parseInt(event.currentTarget.dataset.index, 10);
  const result = engine.tap(cellIndex, now());
  if (result.type === "ignored") return;

  window.clearTimeout(deadlineTimer);
  deadlineTimer = null;

  if (result.type === "hit") {
    saveHighScore(result.snapshot.points);
    showFeedback(`+${result.pointsAwarded} · ${Math.round(result.reactionMs)} ms`, false);
    elements.instruction.textContent = "Good — get ready";
    render();
    scheduleRound(sessionId);
    return;
  }

  handleMiss(result, sessionId);
}

function handleMiss(result, currentSession) {
  const message = result.reason === "wrong" ? "Wrong color · −1 life" : "Too slow · −1 life";
  showFeedback(message, true);
  elements.instruction.textContent = message;
  render();

  if (result.snapshot.state === GAME_STATES.GAME_OVER) {
    finishGame(result.snapshot);
  } else {
    scheduleRound(currentSession);
  }
}

function finishGame(snapshot) {
  clearTimers();
  saveHighScore(snapshot.points);
  elements.dialogTitle.textContent = "Run complete";
  elements.dialogMessage.innerHTML = `You scored <strong>${snapshot.points.toLocaleString()}</strong> points with <strong>${snapshot.hits}</strong> correct taps. Your high score is <strong>${highScore.toLocaleString()}</strong>.`;
  elements.startButton.textContent = "Play again";
  window.setTimeout(() => {
    elements.overlay.hidden = false;
  }, 450);
}

function ensureBoard(dimension) {
  const cellCount = dimension ** 2;
  elements.board.style.setProperty("--grid-size", String(dimension));
  if (elements.board.children.length === cellCount) return;

  const fragment = document.createDocumentFragment();
  for (let index = 0; index < cellCount; index += 1) {
    const tile = document.createElement("button");
    tile.type = "button";
    tile.className = "tile";
    tile.dataset.index = String(index);
    tile.setAttribute("aria-label", `Inactive cell ${index + 1}`);
    tile.addEventListener("pointerdown", handleTileTap);
    fragment.append(tile);
  }
  elements.board.replaceChildren(fragment);
}

function render() {
  const snapshot = engine.getSnapshot(now());
  ensureBoard(snapshot.difficulty.gridDimension);

  elements.points.textContent = snapshot.points.toLocaleString();
  elements.highScore.textContent = Math.max(highScore, snapshot.points).toLocaleString();
  elements.phase.textContent = snapshot.difficulty.phaseName;
  elements.colorName.textContent = `${snapshot.playerColor.glyph} ${snapshot.playerColor.name}`;
  elements.colorSwatch.style.background = snapshot.playerColor.value;
  elements.colorSwatch.style.color = snapshot.playerColor.value;
  elements.lives.innerHTML = Array.from(
    { length: engine.config.startingLives },
    (_, index) => `<span class="${index < snapshot.lives ? "" : "lost"}">♥</span>`
  ).join("");
  elements.lives.setAttribute("aria-label", `${snapshot.lives} ${snapshot.lives === 1 ? "life" : "lives"}`);

  const tiles = [...elements.board.children];
  for (const [index, tile] of tiles.entries()) {
    const cell = snapshot.cells[index] ?? { kind: "idle", colorIndex: null };
    tile.className = "tile";
    tile.textContent = "";
    tile.style.removeProperty("--tile-color");
    tile.style.removeProperty("--tile-ink");
    tile.setAttribute("aria-label", `Inactive cell ${index + 1}`);

    if (cell.kind !== "idle") {
      const color = COLORS[cell.colorIndex];
      tile.classList.add("tile--lit");
      tile.style.setProperty("--tile-color", color.value);
      tile.style.setProperty("--tile-ink", color.ink);
      tile.textContent = color.glyph;
      tile.setAttribute("aria-label", `${color.name} cell ${index + 1}`);
    }
  }

  if (snapshot.state === GAME_STATES.WAITING) {
    elements.hint.textContent = snapshot.difficulty.usesColorChoice
      ? "Match both the header color and symbol. Every other lit cell is a decoy."
      : "The timer is hidden. React as soon as a cell lights up.";
  }
}

function showFeedback(message, isBad) {
  window.clearTimeout(feedbackTimer);
  elements.feedback.textContent = message;
  elements.feedback.className = `feedback feedback--show${isBad ? " feedback--bad" : ""}`;
  feedbackTimer = window.setTimeout(() => {
    elements.feedback.className = "feedback";
  }, 540);
}

function pauseForVisibilityChange() {
  if (!document.hidden || engine.state === GAME_STATES.IDLE || engine.state === GAME_STATES.GAME_OVER) return;
  clearTimers();
  sessionId += 1;
  elements.dialogTitle.textContent = "Run paused";
  elements.dialogMessage.textContent = "The app moved into the background, so this run was stopped without changing your high score.";
  elements.startButton.textContent = "Restart run";
  elements.overlay.hidden = false;
}

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  deferredInstallPrompt = event;
  elements.installButton.hidden = false;
});

elements.installButton.addEventListener("click", async () => {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  await deferredInstallPrompt.userChoice;
  deferredInstallPrompt = null;
  elements.installButton.hidden = true;
});

elements.startButton.addEventListener("click", startGame);
document.addEventListener("visibilitychange", pauseForVisibilityChange);

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("./sw.js").catch(() => {
      // Offline support is optional during local development.
    });
  });
}

engine.reset();
elements.highScore.textContent = highScore.toLocaleString();
render();
