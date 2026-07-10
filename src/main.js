import { COLORS, GAME_MODES } from "./config.js";
import { GameEngine, GAME_STATES, ROUND_KINDS } from "./game-engine.js";

const LEGACY_HIGH_SCORE_KEY = "speedytapper.highScore.v1";
const HIGH_SCORE_KEYS = Object.freeze({
  [GAME_MODES.NORMAL]: "speedytapper.highScore.normal.v2",
  [GAME_MODES.ZEN]: "speedytapper.highScore.zen.v2"
});

const elements = {
  board: document.querySelector("#board"),
  colorHero: document.querySelector("#color-hero"),
  colorGlyph: document.querySelector("#color-glyph"),
  colorName: document.querySelector("#color-name"),
  colorSwatch: document.querySelector("#color-swatch"),
  dialogMessage: document.querySelector("#dialog-message"),
  dialogTitle: document.querySelector("#dialog-title"),
  feedback: document.querySelector("#feedback"),
  highScore: document.querySelector("#high-score"),
  hint: document.querySelector("#hint"),
  installButton: document.querySelector("#install-button"),
  instruction: document.querySelector("#instruction"),
  modeName: document.querySelector("#mode-name"),
  normalButton: document.querySelector("#normal-button"),
  overlay: document.querySelector("#overlay"),
  phase: document.querySelector("#phase"),
  points: document.querySelector("#points"),
  statusLabel: document.querySelector("#status-label"),
  statusValue: document.querySelector("#status-value"),
  zenButton: document.querySelector("#zen-button")
};

const engine = new GameEngine();
const highScores = readHighScores();
let spawnTimer = null;
let deadlineTimer = null;
let runEndTimer = null;
let clockTimer = null;
let feedbackTimer = null;
let sessionId = 0;
let deferredInstallPrompt = null;

function now() {
  return performance.now();
}

function readStoredScore(key) {
  try {
    const storedValue = Number.parseInt(localStorage.getItem(key) ?? "0", 10);
    return Number.isFinite(storedValue) && storedValue > 0 ? storedValue : 0;
  } catch {
    return 0;
  }
}

function readHighScores() {
  return {
    [GAME_MODES.NORMAL]: Math.max(
      readStoredScore(HIGH_SCORE_KEYS[GAME_MODES.NORMAL]),
      readStoredScore(LEGACY_HIGH_SCORE_KEY)
    ),
    [GAME_MODES.ZEN]: readStoredScore(HIGH_SCORE_KEYS[GAME_MODES.ZEN])
  };
}

function saveHighScore(mode, score) {
  if (score <= highScores[mode]) return;
  highScores[mode] = score;
  try {
    localStorage.setItem(HIGH_SCORE_KEYS[mode], String(score));
  } catch {
    // Private browsing or storage restrictions should not stop the game.
  }
}

function clearTimers() {
  window.clearTimeout(spawnTimer);
  window.clearTimeout(deadlineTimer);
  window.clearTimeout(runEndTimer);
  window.clearInterval(clockTimer);
  spawnTimer = null;
  deadlineTimer = null;
  runEndTimer = null;
  clockTimer = null;
}

function startGame(mode) {
  clearTimers();
  sessionId += 1;
  const startedAt = now();
  engine.start(startedAt, mode);
  elements.overlay.hidden = true;
  elements.dialogTitle.textContent = "SpeedyTapper Lab";
  render();

  if (mode === GAME_MODES.ZEN) {
    runEndTimer = window.setTimeout(() => finishZenRun(sessionId), engine.config.zenDurationMs);
    clockTimer = window.setInterval(() => renderHud(engine.getSnapshot(now())), 100);
  }

  scheduleRound(sessionId);
}

function scheduleRound(currentSession) {
  if (engine.state === GAME_STATES.GAME_OVER || currentSession !== sessionId) return;

  const delayMs = engine.getNextDelayMs(now());
  elements.instruction.textContent = "Get ready";
  spawnTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden || engine.state === GAME_STATES.GAME_OVER) return;
    const result = engine.activateRound(now());
    if (result.type !== "round-active") return;
    elements.instruction.textContent =
      result.snapshot.roundKind === ROUND_KINDS.WRONG_ONLY ? "Ignore it" : "Tap your color";
    render();
    scheduleDeadline(currentSession, result.snapshot.difficulty.responseWindowMs);
  }, delayMs);
}

function scheduleDeadline(currentSession, delayMs) {
  deadlineTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden || engine.state === GAME_STATES.GAME_OVER) return;
    const result = engine.expireRound(now());
    if (result.type === "ignored" && result.reason === "not-expired") {
      scheduleDeadline(currentSession, Math.ceil(result.remainingMs));
      return;
    }
    if (result.type === "ignored-color") {
      showFeedback("Good restraint", false);
      elements.instruction.textContent = "Good — get ready";
      render();
      scheduleRound(currentSession);
      return;
    }
    if (result.type === "miss") {
      handleMiss(result, currentSession);
    }
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
    saveHighScore(result.snapshot.mode, result.snapshot.points);
    showFeedback(`+${result.pointsAwarded} · ${Math.round(result.reactionMs)} ms`, false);
    elements.instruction.textContent = "Good — get ready";
    render();
    scheduleRound(sessionId);
    return;
  }

  handleMiss(result, sessionId);
}

function handleMiss(result, currentSession) {
  const wrong = result.reason === "wrong";
  const message = result.lifeLost
    ? `${wrong ? "Wrong color" : "Too slow"} · −1 life`
    : `${wrong ? "Wrong color" : "Too slow"} · keep going`;
  showFeedback(message, true);
  elements.instruction.textContent = message;
  render();

  if (result.snapshot.state === GAME_STATES.GAME_OVER) {
    finishGame(result.snapshot);
  } else {
    scheduleRound(currentSession);
  }
}

function finishZenRun(currentSession) {
  if (currentSession !== sessionId) return;
  const result = engine.finishTimedRun(now());
  if (result.type === "ignored" && result.reason === "time-remaining") {
    runEndTimer = window.setTimeout(
      () => finishZenRun(currentSession),
      Math.max(1, Math.ceil(result.remainingMs))
    );
    return;
  }
  if (result.type === "time-up") {
    finishGame(result.snapshot);
  }
}

function finishGame(snapshot) {
  clearTimers();
  saveHighScore(snapshot.mode, snapshot.points);
  const isZen = snapshot.mode === GAME_MODES.ZEN;
  elements.dialogTitle.textContent = isZen ? "Minute complete" : "Run complete";
  elements.dialogMessage.innerHTML = `You scored <strong>${snapshot.points.toLocaleString()}</strong> points with <strong>${snapshot.hits}</strong> correct taps. Your ${isZen ? "Zen" : "Normal"} best is <strong>${highScores[snapshot.mode].toLocaleString()}</strong>.`;
  window.setTimeout(() => {
    elements.overlay.hidden = false;
  }, 400);
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

function renderHud(snapshot) {
  const best = Math.max(highScores[snapshot.mode], snapshot.points);
  elements.points.textContent = snapshot.points.toLocaleString();
  elements.highScore.textContent = best.toLocaleString();
  elements.modeName.textContent = snapshot.mode === GAME_MODES.ZEN ? "Zen" : "Normal";
  elements.phase.textContent = snapshot.difficulty.phaseName;
  elements.colorName.textContent = snapshot.playerColor.name;
  elements.colorGlyph.textContent = snapshot.playerColor.glyph;
  elements.colorSwatch.style.background = snapshot.playerColor.value;
  elements.colorSwatch.style.color = snapshot.playerColor.ink;
  elements.colorHero.style.setProperty("--player-color", snapshot.playerColor.value);

  elements.statusValue.className = "stat__value status-value";
  if (snapshot.mode === GAME_MODES.ZEN) {
    const secondsRemaining = Math.ceil((snapshot.remainingMs ?? engine.config.zenDurationMs) / 1_000);
    elements.statusLabel.textContent = "Time";
    elements.statusValue.textContent = `${secondsRemaining}s`;
    elements.statusValue.setAttribute("aria-label", `${secondsRemaining} seconds remaining`);
  } else {
    elements.statusLabel.textContent = "Lives";
    elements.statusValue.classList.add("lives");
    elements.statusValue.innerHTML = Array.from(
      { length: engine.config.startingLives },
      (_, index) => `<span class="${index < snapshot.lives ? "" : "lost"}">♥</span>`
    ).join("");
    elements.statusValue.setAttribute(
      "aria-label",
      `${snapshot.lives} ${snapshot.lives === 1 ? "life" : "lives"}`
    );
  }
}

function render() {
  const snapshot = engine.getSnapshot(now());
  ensureBoard(snapshot.difficulty.gridDimension);
  renderHud(snapshot);

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

  elements.hint.textContent = hintFor(snapshot);
}

function hintFor(snapshot) {
  if (snapshot.mode === GAME_MODES.ZEN) {
    return "Zen lasts one minute. Mistakes never remove lives; collect the best score you can.";
  }
  if (snapshot.roundKind === ROUND_KINDS.WRONG_ONLY) {
    return "This is not your color. Wait for it to disappear without tapping.";
  }
  if (snapshot.roundKind === ROUND_KINDS.MIXED) {
    return "A rare decoy is present. Tap only the large color shown in the header.";
  }
  if (snapshot.difficulty.phaseId === "warmup") {
    return "The timer is hidden. React as soon as your color lights up.";
  }
  if (snapshot.difficulty.phaseId === "four-by-four-reset") {
    return "A forgiving 16-cell reset: no simultaneous decoys for ten seconds.";
  }
  return "Tap your color. If a lone different color appears, ignore it.";
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
  elements.dialogMessage.textContent = "The app moved into the background, so this run was stopped. Choose a mode to restart.";
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

elements.normalButton.addEventListener("click", () => startGame(GAME_MODES.NORMAL));
elements.zenButton.addEventListener("click", () => startGame(GAME_MODES.ZEN));
document.addEventListener("visibilitychange", pauseForVisibilityChange);

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("./sw.js").catch(() => {
      // Offline support is optional during local development.
    });
  });
}

engine.reset();
render();
