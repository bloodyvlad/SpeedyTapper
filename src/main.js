import { COLORS, GAME_MODES } from "./config.js?v=20260710-2";
import { GameEngine, GAME_STATES, ROUND_KINDS } from "./game-engine.js?v=20260710-2";

const LEGACY_HIGH_SCORE_KEY = "speedytapper.highScore.v1";
const HIGH_SCORE_KEYS = Object.freeze({
  [GAME_MODES.NORMAL]: "speedytapper.highScore.normal.v2",
  [GAME_MODES.ZEN]: "speedytapper.highScore.zen.v2"
});
const PLAYER_NAME_KEY = "speedytapper.playerName.v1";

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
  leaderboardList: document.querySelector("#leaderboard-list"),
  leaderboardPanel: document.querySelector("#leaderboard-panel"),
  leaderboardStatus: document.querySelector("#leaderboard-status"),
  leaderboardTabs: [...document.querySelectorAll("[data-leaderboard-mode]")],
  leaderboardToggle: document.querySelector("#leaderboard-toggle"),
  modeLabel: document.querySelector("#mode-label"),
  modeName: document.querySelector("#mode-name"),
  normalButton: document.querySelector("#normal-button"),
  overlay: document.querySelector("#overlay"),
  phase: document.querySelector("#phase"),
  playerName: document.querySelector("#player-name"),
  points: document.querySelector("#points"),
  rules: document.querySelector("#rules"),
  resultDuration: document.querySelector("#result-duration"),
  resultDurationValue: document.querySelector("#result-duration-value"),
  scoreForm: document.querySelector("#score-form"),
  scoreStatus: document.querySelector("#score-status"),
  scoreSubmit: document.querySelector("#score-submit"),
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
let completionTimer = null;
let sessionId = 0;
let deferredInstallPrompt = null;
let pendingResult = null;
let leaderboardMode = GAME_MODES.NORMAL;
let leaderboardRequestId = 0;

function now() {
  return performance.now();
}

function formatDuration(milliseconds, showTenths = false) {
  const totalTenths = Math.max(0, Math.floor(milliseconds / 100));
  const minutes = Math.floor(totalTenths / 600);
  const seconds = Math.floor((totalTenths % 600) / 10);
  const tenths = totalTenths % 10;
  return `${minutes}:${String(seconds).padStart(2, "0")}${showTenths ? `.${tenths}` : ""}`;
}

function readPlayerName() {
  try {
    return localStorage.getItem(PLAYER_NAME_KEY) ?? "";
  } catch {
    return "";
  }
}

function savePlayerName(name) {
  try {
    localStorage.setItem(PLAYER_NAME_KEY, name);
  } catch {
    // The leaderboard still works when local storage is unavailable.
  }
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
  window.clearTimeout(completionTimer);
  spawnTimer = null;
  deadlineTimer = null;
  runEndTimer = null;
  clockTimer = null;
  completionTimer = null;
}

function startGame(mode) {
  clearTimers();
  const currentSession = sessionId + 1;
  sessionId = currentSession;
  const startedAt = now();
  engine.start(startedAt, mode);
  resetResultUi();
  elements.overlay.hidden = true;
  elements.dialogTitle.textContent = "Ready to react?";
  render();

  clockTimer = window.setInterval(() => renderHud(engine.getSnapshot(now())), 100);

  if (mode === GAME_MODES.ZEN) {
    runEndTimer = window.setTimeout(
      () => finishZenRun(currentSession),
      engine.config.zenDurationMs
    );
  }

  scheduleRound(currentSession);
}

function scheduleRound(currentSession, additionalDelayMs = 0) {
  if (engine.state === GAME_STATES.GAME_OVER || currentSession !== sessionId) return;

  const delayMs = engine.getNextDelayMs(now());
  if (additionalDelayMs === 0) {
    elements.instruction.textContent = "Get ready";
  }
  spawnTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden || engine.state === GAME_STATES.GAME_OVER) return;
    const result = engine.activateRound(now());
    if (result.type !== "round-active") return;
    elements.instruction.textContent =
      result.snapshot.roundKind === ROUND_KINDS.WRONG_ONLY ? "Ignore it" : "Tap your color";
    render();
    scheduleDeadline(currentSession, result.snapshot.difficulty.responseWindowMs);
  }, delayMs + additionalDelayMs);
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
    ? `${wrong ? "Wrong color" : "Too slow"} · ${result.snapshot.lives} ${result.snapshot.lives === 1 ? "life" : "lives"} left`
    : `${wrong ? "Wrong color" : "Too slow"} · keep going`;
  showFeedback(message, true);
  elements.instruction.textContent = message;
  render();

  if (engine.isRunComplete()) {
    finishGame(result.snapshot, currentSession);
  } else {
    const recoveryMs = result.lifeLost ? engine.config.lifeLossRecoveryMs : 0;
    scheduleRound(currentSession, recoveryMs);
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
    finishGame(result.snapshot, currentSession);
  }
}

function finishGame(snapshot, currentSession) {
  if (currentSession !== sessionId || !engine.isRunComplete()) return;
  clearTimers();
  saveHighScore(snapshot.mode, snapshot.points);
  const isZen = snapshot.mode === GAME_MODES.ZEN;
  elements.dialogTitle.textContent = isZen ? "Minute complete" : "Run complete";
  const completionReason = isZen ? "The one-minute timer ended." : "All three lives were used.";
  elements.dialogMessage.innerHTML = `${completionReason} You scored <strong>${snapshot.points.toLocaleString()}</strong> points with <strong>${snapshot.hits}</strong> correct taps. Your ${isZen ? "Zen" : "Normal"} best is <strong>${highScores[snapshot.mode].toLocaleString()}</strong>.`;
  elements.resultDuration.hidden = isZen;
  if (!isZen) {
    elements.resultDurationValue.textContent = formatDuration(snapshot.elapsedMs, true);
  }
  pendingResult = {
    mode: snapshot.mode,
    score: snapshot.points,
    hits: snapshot.hits,
    survivalMs: Math.round(snapshot.elapsedMs),
    submitted: false
  };
  elements.rules.hidden = true;
  elements.scoreForm.hidden = false;
  elements.playerName.disabled = false;
  elements.playerName.value = readPlayerName();
  elements.scoreSubmit.disabled = false;
  elements.scoreSubmit.textContent = "Save score";
  setScoreStatus("Enter your name to submit this run to the Top 20.");
  completionTimer = window.setTimeout(() => {
    if (currentSession === sessionId && engine.isRunComplete()) {
      elements.overlay.hidden = false;
      elements.scoreForm.scrollIntoView({ block: "nearest" });
      elements.playerName.focus({ preventScroll: true });
    }
  }, 400);
}

function resetResultUi() {
  pendingResult = null;
  elements.rules.hidden = false;
  elements.resultDuration.hidden = true;
  elements.scoreForm.hidden = true;
  elements.playerName.disabled = false;
  elements.scoreSubmit.disabled = false;
  elements.scoreSubmit.textContent = "Save score";
  setScoreStatus("");
  closeLeaderboard();
}

function setScoreStatus(message, isError = false) {
  elements.scoreStatus.textContent = message;
  elements.scoreStatus.classList.toggle("is-error", isError);
}

function setLeaderboardStatus(message, isError = false) {
  elements.leaderboardStatus.textContent = message;
  elements.leaderboardStatus.classList.toggle("is-error", isError);
}

function selectLeaderboardMode(mode) {
  leaderboardMode = mode;
  for (const tab of elements.leaderboardTabs) {
    const selected = tab.dataset.leaderboardMode === mode;
    tab.classList.toggle("is-active", selected);
    tab.setAttribute("aria-selected", String(selected));
  }
}

function openLeaderboard() {
  elements.leaderboardPanel.hidden = false;
  elements.leaderboardToggle.setAttribute("aria-expanded", "true");
  elements.leaderboardToggle.textContent = "Close board";
  window.requestAnimationFrame(() => {
    elements.leaderboardPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
  });
}

function closeLeaderboard() {
  leaderboardRequestId += 1;
  elements.leaderboardPanel.hidden = true;
  elements.leaderboardToggle.setAttribute("aria-expanded", "false");
  elements.leaderboardToggle.textContent = "Leaderboard";
}

function renderLeaderboard(entries, mode) {
  elements.leaderboardList.replaceChildren();

  if (!Array.isArray(entries) || entries.length === 0) {
    const empty = document.createElement("li");
    empty.className = "leaderboard-empty";
    empty.textContent = "No scores yet — the first place is waiting.";
    elements.leaderboardList.append(empty);
    return;
  }

  const fragment = document.createDocumentFragment();
  for (const [index, entry] of entries.slice(0, 20).entries()) {
    const row = document.createElement("li");
    row.className = "leaderboard-entry";

    const rank = document.createElement("span");
    rank.className = "leaderboard-entry__rank";
    rank.textContent = String(index + 1);

    const player = document.createElement("div");
    player.className = "leaderboard-entry__player";
    const name = document.createElement("div");
    name.className = "leaderboard-entry__name";
    name.textContent = entry.name;
    const meta = document.createElement("div");
    meta.className = "leaderboard-entry__meta";
    meta.textContent =
      mode === GAME_MODES.NORMAL
        ? `${formatDuration(entry.survivalMs, true)} survived · ${entry.hits.toLocaleString()} taps`
        : `${entry.hits.toLocaleString()} correct taps`;
    player.append(name, meta);

    const score = document.createElement("strong");
    score.className = "leaderboard-entry__score";
    score.textContent = entry.score.toLocaleString();

    row.append(rank, player, score);
    fragment.append(row);
  }
  elements.leaderboardList.append(fragment);
}

async function readApiResponse(response) {
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(body.error || "Leaderboard is temporarily unavailable.");
  }
  return body;
}

async function loadLeaderboard(mode = leaderboardMode) {
  selectLeaderboardMode(mode);
  openLeaderboard();
  const requestId = leaderboardRequestId + 1;
  leaderboardRequestId = requestId;
  setLeaderboardStatus("Loading scores…");
  elements.leaderboardList.replaceChildren();

  try {
    const response = await fetch(`/api/leaderboard?mode=${encodeURIComponent(mode)}`, {
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    const body = await readApiResponse(response);
    if (requestId !== leaderboardRequestId) return;
    renderLeaderboard(body.entries, mode);
    setLeaderboardStatus(`${body.entries.length} of 20 places filled.`);
  } catch (error) {
    if (requestId !== leaderboardRequestId) return;
    renderLeaderboard([], mode);
    setLeaderboardStatus(error.message, true);
  }
}

async function submitScore(event) {
  event.preventDefault();
  if (!pendingResult || pendingResult.submitted) return;

  const name = elements.playerName.value.trim();
  if (!name) {
    setScoreStatus("Enter a player name.", true);
    elements.playerName.focus();
    return;
  }

  elements.scoreSubmit.disabled = true;
  elements.playerName.disabled = true;
  setScoreStatus("Saving score…");

  try {
    const response = await fetch("/api/leaderboard", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        name,
        mode: pendingResult.mode,
        score: pendingResult.score,
        hits: pendingResult.hits,
        survivalMs: pendingResult.survivalMs
      })
    });
    const body = await readApiResponse(response);
    pendingResult.submitted = true;
    savePlayerName(name);
    elements.scoreSubmit.textContent = body.rank === null ? "Not ranked" : "Saved";
    setScoreStatus(
      body.rank === null
        ? "This result did not reach the current Top 20."
        : `Score saved at #${body.rank}.`
    );
    leaderboardRequestId += 1;
    selectLeaderboardMode(body.mode);
    renderLeaderboard(body.entries, body.mode);
    setLeaderboardStatus(`${body.entries.length} of 20 places filled.`);
    openLeaderboard();
  } catch (error) {
    elements.scoreSubmit.disabled = false;
    elements.playerName.disabled = false;
    setScoreStatus(error.message, true);
  }
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
  if (snapshot.mode === GAME_MODES.ZEN) {
    elements.modeLabel.textContent = "Mode";
    elements.modeName.textContent = "Zen";
  } else {
    elements.modeLabel.textContent = "Survived";
    elements.modeName.textContent = formatDuration(snapshot.elapsedMs);
  }
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
    const decoyCount = snapshot.difficulty.decoyCount;
    return `${decoyCount} ${decoyCount === 1 ? "decoy is" : "decoys are"} present. Tap only the large color shown in the header.`;
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
  resetResultUi();
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
elements.scoreForm.addEventListener("submit", submitScore);
elements.leaderboardToggle.addEventListener("click", () => {
  if (elements.leaderboardPanel.hidden) {
    loadLeaderboard(leaderboardMode);
  } else {
    closeLeaderboard();
  }
});
for (const tab of elements.leaderboardTabs) {
  tab.addEventListener("click", () => loadLeaderboard(tab.dataset.leaderboardMode));
}
document.addEventListener("visibilitychange", pauseForVisibilityChange);

engine.reset();
render();
