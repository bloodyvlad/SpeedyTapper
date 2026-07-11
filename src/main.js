import { COLORS, GAME_MODES } from "./config.js?v=20260711-1";
import { GameEngine, GAME_STATES } from "./game-engine.js?v=20260711-1";
import { sanitizePlayerName } from "../lib/leaderboard-model.js?v=20260711-1";

const LEGACY_HIGH_SCORE_KEY = "speedytapper.highScore.v1";
const LEGACY_MODE_SCORE_KEYS = Object.freeze({
  [GAME_MODES.NORMAL]: "speedytapper.highScore.normal.v2",
  [GAME_MODES.ZEN]: "speedytapper.highScore.zen.v2"
});
const PLAYER_NAME_KEY = "speedytapper.playerName.v1";
const PROFILE_SCORE_PREFIX = "speedytapper.highScore.profile.v3";
const PROFILE_SCORE_MIGRATION_KEY = "speedytapper.highScore.profileMigration.v3";

const elements = {
  board: document.querySelector("#board"),
  colorHero: document.querySelector("#color-hero"),
  colorGlyph: document.querySelector("#color-glyph"),
  colorName: document.querySelector("#color-name"),
  colorSwatch: document.querySelector("#color-swatch"),
  dialog: document.querySelector(".dialog"),
  dialogMessage: document.querySelector("#dialog-message"),
  dialogTitle: document.querySelector("#dialog-title"),
  feedback: document.querySelector("#feedback"),
  highScore: document.querySelector("#high-score"),
  highScoreLabel: document.querySelector("#high-score-label"),
  installButton: document.querySelector("#install-button"),
  leaderboardList: document.querySelector("#leaderboard-list"),
  leaderboardPanel: document.querySelector("#leaderboard-panel"),
  leaderboardStatus: document.querySelector("#leaderboard-status"),
  leaderboardTabs: [...document.querySelectorAll("[data-leaderboard-mode]")],
  leaderboardToggle: document.querySelector("#leaderboard-toggle"),
  modeLabel: document.querySelector("#mode-label"),
  modeName: document.querySelector("#mode-name"),
  normalButton: document.querySelector("#normal-button"),
  overlay: document.querySelector("#overlay"),
  playerName: document.querySelector("#player-name"),
  playerProfileName: document.querySelector("#player-profile-name"),
  points: document.querySelector("#points"),
  responseRails: document.querySelector("#response-rails"),
  responseRailFills: [...document.querySelectorAll(".response-rail__fill")],
  resultAverageValue: document.querySelector("#result-average-value"),
  resultDodgesValue: document.querySelector("#result-dodges-value"),
  resultDurationLabel: document.querySelector("#result-duration-label"),
  resultDurationValue: document.querySelector("#result-duration-value"),
  resultFastestValue: document.querySelector("#result-fastest-value"),
  resultStats: document.querySelector("#result-stats"),
  scoreForm: document.querySelector("#score-form"),
  scoreStatus: document.querySelector("#score-status"),
  scoreSubmit: document.querySelector("#score-submit"),
  statusLabel: document.querySelector("#status-label"),
  statusValue: document.querySelector("#status-value"),
  zenButton: document.querySelector("#zen-button")
};

const engine = new GameEngine();
migrateLegacyHighScores();
let highScores = readHighScores();
let spawnTimer = null;
let deadlineTimer = null;
let runEndTimer = null;
let clockTimer = null;
let feedbackTimer = null;
let completionTimer = null;
let progressFrame = null;
let sessionId = 0;
let deferredInstallPrompt = null;
let pendingResult = null;
let leaderboardMode = GAME_MODES.NORMAL;
let leaderboardRequestId = 0;

const sound = (() => {
  const relayOn = new Audio("./assets/audio/relay-on.mp3");
  const relayOff = new Audio("./assets/audio/relay-off.mp3");
  const oops = new Audio("./assets/audio/oops.mp3");
  const hum = new Audio("./assets/audio/fluorescent-hum.mp3");
  const sounds = [relayOn, relayOff, oops, hum];
  hum.loop = true;
  hum.volume = 0.42;
  relayOn.volume = 0.7;
  relayOff.volume = 0.72;
  oops.volume = 0.8;
  for (const audio of sounds) audio.preload = "auto";

  function play(audio) {
    audio.currentTime = 0;
    audio.play().catch(() => {});
  }

  return {
    unlock() {
      for (const audio of sounds) {
        audio.muted = true;
        audio.play().then(() => {
          audio.pause();
          audio.currentTime = 0;
          audio.muted = false;
        }).catch(() => {
          audio.muted = false;
        });
      }
    },
    tileOn() {
      play(relayOn);
      hum.currentTime = 0;
      hum.play().catch(() => {});
    },
    tileOff(withRelay = true) {
      hum.pause();
      hum.currentTime = 0;
      if (withRelay) play(relayOff);
    },
    lifeLost() {
      play(oops);
    }
  };
})();

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

function formatReaction(milliseconds) {
  return milliseconds === null || milliseconds === undefined
    ? "—"
    : `${Math.round(milliseconds)} ms`;
}

function readPlayerName() {
  try {
    const storedName = localStorage.getItem(PLAYER_NAME_KEY) ?? "";
    if (!storedName) return "";
    try {
      const normalizedName = sanitizePlayerName(storedName);
      if (normalizedName !== storedName) {
        localStorage.setItem(PLAYER_NAME_KEY, normalizedName);
      }
      return normalizedName;
    } catch {
      localStorage.removeItem(PLAYER_NAME_KEY);
      return "";
    }
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

function updatePlayerProfile(name = readPlayerName()) {
  const profileName = name || "Guest on this device";
  elements.playerProfileName.textContent = profileName;
  elements.highScoreLabel.textContent = "Profile best";
  elements.highScoreLabel.title = name
    ? `Best score stored for ${name} on this device`
    : "Best score stored in this browser on this device";
}

function readStoredScore(key) {
  try {
    const storedValue = Number.parseInt(localStorage.getItem(key) ?? "0", 10);
    return Number.isFinite(storedValue) && storedValue > 0 ? storedValue : 0;
  } catch {
    return 0;
  }
}

function profileIdForName(name) {
  const normalizedName = name.normalize("NFKC").trim().toLocaleLowerCase("en-US");
  return encodeURIComponent(normalizedName || "guest");
}

function profileScoreKey(mode, name = readPlayerName()) {
  return `${PROFILE_SCORE_PREFIX}.${profileIdForName(name)}.${mode}`;
}

function writeProfileScore(name, mode, score) {
  try {
    localStorage.setItem(profileScoreKey(mode, name), String(score));
  } catch {
    // Private browsing or storage restrictions should not stop the game.
  }
}

function clearProfileScores(name) {
  try {
    for (const mode of Object.values(GAME_MODES)) {
      localStorage.removeItem(profileScoreKey(mode, name));
    }
  } catch {
    // Private browsing or storage restrictions should not stop the game.
  }
}

function migrateLegacyHighScores() {
  try {
    if (localStorage.getItem(PROFILE_SCORE_MIGRATION_KEY) === "1") return;

    const profileName = readPlayerName();
    const legacyScores = {
      [GAME_MODES.NORMAL]: Math.max(
        readStoredScore(LEGACY_MODE_SCORE_KEYS[GAME_MODES.NORMAL]),
        readStoredScore(LEGACY_HIGH_SCORE_KEY)
      ),
      [GAME_MODES.ZEN]: readStoredScore(LEGACY_MODE_SCORE_KEYS[GAME_MODES.ZEN])
    };

    for (const mode of Object.values(GAME_MODES)) {
      const existingScore = readStoredScore(profileScoreKey(mode, profileName));
      if (legacyScores[mode] > existingScore) {
        writeProfileScore(profileName, mode, legacyScores[mode]);
      }
    }
    localStorage.setItem(PROFILE_SCORE_MIGRATION_KEY, "1");
  } catch {
    // A failed migration only means the browser cannot retain local best scores.
  }
}

function readHighScores(name = readPlayerName()) {
  return {
    [GAME_MODES.NORMAL]: readStoredScore(profileScoreKey(GAME_MODES.NORMAL, name)),
    [GAME_MODES.ZEN]: readStoredScore(profileScoreKey(GAME_MODES.ZEN, name))
  };
}

function saveHighScore(mode, score) {
  if (score <= highScores[mode]) return;
  highScores[mode] = score;
  writeProfileScore(readPlayerName(), mode, score);
}

function activatePlayerProfile(name, result) {
  const previousName = readPlayerName();

  if (previousName) {
    highScores = readHighScores(previousName);
    updatePlayerProfile(previousName);
    return;
  }

  const nextScores = readHighScores(name);
  const guestScores = readHighScores("");
  for (const mode of Object.values(GAME_MODES)) {
    nextScores[mode] = Math.max(nextScores[mode], guestScores[mode]);
  }

  nextScores[result.mode] = Math.max(nextScores[result.mode], result.score);
  for (const mode of Object.values(GAME_MODES)) {
    writeProfileScore(name, mode, nextScores[mode]);
  }

  clearProfileScores("");
  savePlayerName(name);
  highScores = readHighScores(name);
  updatePlayerProfile(name);
}

function stopResponseRails() {
  window.cancelAnimationFrame(progressFrame);
  progressFrame = null;
  elements.responseRails.hidden = true;
  for (const fill of elements.responseRailFills) {
    fill.style.transform = "scaleY(0)";
  }
}

function renderResponseRails(snapshot) {
  if (snapshot.state !== GAME_STATES.ACTIVE || snapshot.reactionProgress === null) {
    stopResponseRails();
    return;
  }

  elements.responseRails.hidden = false;
  elements.responseRails.style.setProperty("--player-color", snapshot.playerColor.value);
  const progress = Math.max(0, Math.min(1, snapshot.reactionProgress));
  for (const fill of elements.responseRailFills) {
    fill.style.transform = `scaleY(${progress})`;
  }
}

function startResponseRails(currentSession) {
  window.cancelAnimationFrame(progressFrame);
  const tick = () => {
    if (
      currentSession !== sessionId ||
      document.hidden ||
      engine.state !== GAME_STATES.ACTIVE
    ) {
      stopResponseRails();
      return;
    }
    renderResponseRails(engine.getSnapshot(now()));
    progressFrame = window.requestAnimationFrame(tick);
  };
  tick();
}

function clearTimers() {
  window.clearTimeout(spawnTimer);
  window.clearTimeout(deadlineTimer);
  window.clearTimeout(runEndTimer);
  window.clearInterval(clockTimer);
  window.clearTimeout(completionTimer);
  stopResponseRails();
  spawnTimer = null;
  deadlineTimer = null;
  runEndTimer = null;
  clockTimer = null;
  completionTimer = null;
  sound.tileOff(false);
}

function startGame(mode) {
  sound.unlock();
  clearTimers();
  highScores = readHighScores();
  updatePlayerProfile();
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
  spawnTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden || engine.state === GAME_STATES.GAME_OVER) return;
    const result = engine.activateRound(now());
    if (result.type !== "round-active") return;
    sound.tileOn();
    render();
    startResponseRails(currentSession);
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
    sound.tileOff();
    stopResponseRails();
    if (result.type === "ignored-color") {
      saveHighScore(result.snapshot.mode, result.snapshot.points);
      showFeedback(`Dodged that! +${result.pointsAwarded}`, false);
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

  sound.tileOff();
  window.clearTimeout(spawnTimer);
  spawnTimer = null;
  window.clearTimeout(deadlineTimer);
  deadlineTimer = null;
  stopResponseRails();

  if (result.type === "hit") {
    saveHighScore(result.snapshot.mode, result.snapshot.points);
    showFeedback(`+${result.pointsAwarded} · ${Math.round(result.reactionMs)} ms`, false);
    render();
    scheduleRound(sessionId);
    return;
  }

  handleMiss(result, sessionId);
}

function handleMiss(result, currentSession) {
  if (result.lifeLost) sound.lifeLost();
  const reasonLabel = {
    empty: "Empty square",
    late: "Too slow",
    wrong: "Wrong color"
  }[result.reason] ?? "Miss";
  const message = result.lifeLost
    ? `${reasonLabel} · ${result.snapshot.lives} ${result.snapshot.lives === 1 ? "life" : "lives"} left`
    : `${reasonLabel} · keep going`;
  showFeedback(message, true);
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
  const modeName = isZen ? "Zen" : "Normal";
  elements.dialogTitle.textContent = isZen ? "Minute complete" : "Game Over";
  const completionReason = isZen ? "The one-minute timer ended." : "You are out of lives.";
  elements.dialogMessage.innerHTML = `${completionReason} You scored <strong>${snapshot.points.toLocaleString()}</strong> points with <strong>${snapshot.hits}</strong> correct taps. Your best score for ${modeName} mode is <strong>${highScores[snapshot.mode].toLocaleString()}</strong>.`;
  elements.resultStats.hidden = false;
  elements.resultDurationLabel.textContent = isZen ? "Duration" : "Survived";
  elements.resultDurationValue.textContent = formatDuration(snapshot.elapsedMs, true);
  elements.resultFastestValue.textContent = formatReaction(snapshot.fastestReactionMs);
  elements.resultAverageValue.textContent = formatReaction(snapshot.averageReactionMs);
  elements.resultDodgesValue.textContent = snapshot.dodges.toLocaleString();
  pendingResult = {
    mode: snapshot.mode,
    score: snapshot.points,
    hits: snapshot.hits,
    dodges: snapshot.dodges,
    fastestReactionMs:
      snapshot.fastestReactionMs === null ? null : Math.round(snapshot.fastestReactionMs),
    averageReactionMs:
      snapshot.averageReactionMs === null ? null : Math.round(snapshot.averageReactionMs),
    survivalMs: Math.round(snapshot.elapsedMs),
    submitted: false
  };
  elements.scoreForm.hidden = false;
  elements.playerName.disabled = false;
  const savedPlayerName = readPlayerName();
  elements.playerName.value = savedPlayerName;
  elements.playerName.readOnly = Boolean(savedPlayerName);
  elements.scoreSubmit.disabled = false;
  elements.scoreSubmit.textContent = "Save score";
  setScoreStatus("Enter your name to submit this run to the Top 20.");
  completionTimer = window.setTimeout(() => {
    if (currentSession === sessionId && engine.isRunComplete()) {
      elements.overlay.hidden = false;
      elements.dialog.scrollTop = 0;
      elements.playerName.focus({ preventScroll: true });
      const keepPromptVisible = () => {
        if (elements.scoreForm.hidden || elements.overlay.hidden) return;
        const viewportTop = window.visualViewport?.offsetTop ?? 0;
        const viewportBottom =
          viewportTop + (window.visualViewport?.height ?? window.innerHeight);
        const formBounds = elements.scoreForm.getBoundingClientRect();
        if (formBounds.top < viewportTop + 8 || formBounds.bottom > viewportBottom - 8) {
          elements.scoreForm.scrollIntoView({ behavior: "smooth", block: "end" });
        }
      };
      window.visualViewport?.addEventListener("resize", keepPromptVisible, { once: true });
      window.setTimeout(keepPromptVisible, 250);
    }
  }, 400);
}

function resetResultUi() {
  pendingResult = null;
  elements.resultStats.hidden = true;
  elements.scoreForm.hidden = true;
  elements.playerName.disabled = false;
  elements.playerName.readOnly = false;
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
    const runMeta = document.createElement("span");
    runMeta.textContent = `${formatDuration(entry.survivalMs, true)} ${
      mode === GAME_MODES.NORMAL ? "survived" : "played"
    } · ${entry.hits.toLocaleString()} taps · ${(entry.dodges ?? 0).toLocaleString()} dodged`;
    const reactionMeta = document.createElement("span");
    reactionMeta.textContent = `Fastest ${formatReaction(entry.fastestReactionMs)} · Average ${formatReaction(entry.averageReactionMs)}`;
    meta.append(runMeta, reactionMeta);
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

  let name;
  try {
    name = sanitizePlayerName(elements.playerName.value);
  } catch (error) {
    setScoreStatus(error.message, true);
    elements.playerName.focus();
    return;
  }

  const savedPlayerName = readPlayerName();
  if (savedPlayerName && name !== savedPlayerName) {
    setScoreStatus(`This run belongs to the ${savedPlayerName} profile.`, true);
    elements.playerName.value = savedPlayerName;
    return;
  }
  elements.playerName.value = name;

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
        dodges: pendingResult.dodges,
        fastestReactionMs: pendingResult.fastestReactionMs,
        averageReactionMs: pendingResult.averageReactionMs,
        survivalMs: pendingResult.survivalMs
      })
    });
    const body = await readApiResponse(response);
    pendingResult.submitted = true;
    activatePlayerProfile(name, pendingResult);
    renderHud(engine.getSnapshot(now()));
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

updatePlayerProfile();
engine.reset();
render();
