import { COLORS, GAME_MODES, THEMES, THEME_PALETTES } from "./config.js?v=20260711-3";
import { GameEngine, GAME_STATES } from "./game-engine.js?v=20260711-3";
import { sanitizePlayerName } from "../lib/leaderboard-model.js?v=20260711-3";

const INTRO_COPY_HTML =
  "Tap only the squares of <strong>Your color</strong> shown above the board. Fast reactions score more. Avoid wrong colors.";
const THEME_STORAGE_KEY = "speedytapper.theme.v1";
const COLOR_BLIND_STORAGE_KEY = "speedytapper.colorBlindMode.v1";

const elements = {
  board: document.querySelector("#board"),
  colorBlindToggle: document.querySelector("#color-blind-toggle"),
  colorHero: document.querySelector("#color-hero"),
  colorGlyph: document.querySelector("#color-glyph"),
  colorName: document.querySelector("#color-name"),
  colorSwatch: document.querySelector("#color-swatch"),
  dialog: document.querySelector(".dialog"),
  dialogMessage: document.querySelector("#dialog-message"),
  dialogTitle: document.querySelector("#dialog-title"),
  feedback: document.querySelector("#feedback"),
  highScore: document.querySelector("#high-score"),
  installButton: document.querySelector("#install-button"),
  leaderboardList: document.querySelector("#leaderboard-list"),
  leaderboardPanel: document.querySelector("#leaderboard-panel"),
  leaderboardStatus: document.querySelector("#leaderboard-status"),
  leaderboardTabs: [...document.querySelectorAll("[data-leaderboard-mode]")],
  leaderboardToggle: document.querySelector("#leaderboard-toggle"),
  mainMenuButton: document.querySelector("#main-menu-button"),
  mainMenuContent: document.querySelector("#main-menu-content"),
  modeLabel: document.querySelector("#mode-label"),
  modeName: document.querySelector("#mode-name"),
  normalButton: document.querySelector("#normal-button"),
  overlay: document.querySelector("#overlay"),
  playerName: document.querySelector("#player-name"),
  points: document.querySelector("#points"),
  responseRails: document.querySelector("#response-rails"),
  responseRailFills: [...document.querySelectorAll(".response-rail__fill")],
  resultAverageValue: document.querySelector("#result-average-value"),
  resultContent: document.querySelector("#result-content"),
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
  themeCurrent: document.querySelector("#theme-current"),
  themeInputs: [...document.querySelectorAll('input[name="theme"]')],
  themeColorMeta: document.querySelector('meta[name="theme-color"]'),
  themesPanel: document.querySelector("#themes-panel"),
  themesToggle: document.querySelector("#themes-toggle"),
  zenButton: document.querySelector("#zen-button")
};

const engine = new GameEngine();
const topScores = {
  [GAME_MODES.NORMAL]: null,
  [GAME_MODES.ZEN]: null
};
const topScoreRevisions = {
  [GAME_MODES.NORMAL]: 0,
  [GAME_MODES.ZEN]: 0
};
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
let activeTheme = THEMES.CLASSIC;
let colorBlindMode = true;

const sound = (() => {
  const relayOff = new Audio("./assets/audio/relay-off.mp3");
  const oops = new Audio("./assets/audio/oops.mp3");
  const hum = new Audio("./assets/audio/fluorescent-hum.mp3");
  const sounds = [relayOff, oops, hum];
  hum.loop = true;
  hum.volume = 0.42;
  relayOff.volume = 0.36;
  oops.volume = 0.8;
  for (const audio of sounds) {
    audio.preload = "auto";
    audio.load();
  }

  function play(audio) {
    if (audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) return;
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
      if (hum.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) return;
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

function readStoredPreference(key) {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeStoredPreference(key, value) {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Display preferences remain usable for the current session when storage is unavailable.
  }
}

function isTheme(value) {
  return Object.values(THEMES).includes(value);
}

function getDisplayColor(colorIndex) {
  return THEME_PALETTES[activeTheme]?.[colorIndex] ?? COLORS[colorIndex];
}

function renderDisplaySettings() {
  document.documentElement.dataset.theme = activeTheme;
  document.documentElement.dataset.glyphs = colorBlindMode ? "on" : "off";
  elements.themeCurrent.textContent = activeTheme === THEMES.DISCO ? "Disco" : "Classic";
  elements.colorBlindToggle.checked = colorBlindMode;
  elements.themeColorMeta.content = activeTheme === THEMES.DISCO ? "#050606" : "#0b0d18";
  for (const input of elements.themeInputs) {
    input.checked = input.value === activeTheme;
  }
}

function initializeDisplaySettings() {
  const storedTheme = readStoredPreference(THEME_STORAGE_KEY);
  activeTheme = isTheme(storedTheme) ? storedTheme : THEMES.CLASSIC;
  const storedColorBlindMode = readStoredPreference(COLOR_BLIND_STORAGE_KEY);
  colorBlindMode = storedColorBlindMode !== "off";
  renderDisplaySettings();
}

function applyTheme(theme) {
  if (!isTheme(theme)) return;
  activeTheme = theme;
  renderDisplaySettings();
  writeStoredPreference(THEME_STORAGE_KEY, theme);
  render();
}

function applyColorBlindMode(enabled) {
  colorBlindMode = Boolean(enabled);
  renderDisplaySettings();
  writeStoredPreference(COLOR_BLIND_STORAGE_KEY, colorBlindMode ? "on" : "off");
  render();
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
  elements.responseRails.style.setProperty(
    "--player-color",
    getDisplayColor(snapshot.playerColorIndex).value
  );
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
  void refreshTopScore(mode);
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
    sound.tileOff(false);
    stopResponseRails();
    if (result.type === "ignored-color") {
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
  const isZen = snapshot.mode === GAME_MODES.ZEN;
  elements.dialogTitle.textContent = isZen ? "Minute complete" : "Game Over";
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
  renderResultMessage(pendingResult);
  void refreshTopScore(snapshot.mode);
  closeLeaderboard();
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = false;
  elements.scoreForm.hidden = false;
  elements.playerName.disabled = false;
  elements.playerName.value = "";
  elements.playerName.readOnly = false;
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
  elements.resultContent.hidden = true;
  elements.resultStats.hidden = true;
  elements.scoreForm.hidden = true;
  elements.mainMenuContent.hidden = false;
  elements.playerName.disabled = false;
  elements.playerName.readOnly = false;
  elements.playerName.value = "";
  elements.scoreSubmit.disabled = false;
  elements.scoreSubmit.textContent = "Save score";
  setScoreStatus("");
  closeThemes();
  closeLeaderboard();
}

function renderResultMessage(result) {
  if (!result) return;
  const isZen = result.mode === GAME_MODES.ZEN;
  const modeName = isZen ? "Zen" : "Normal";
  const completionReason = isZen ? "The one-minute timer ended." : "You are out of lives.";
  const topScore = topScores[result.mode];
  const bestScore = topScore === null
    ? "<strong>unavailable right now</strong>"
    : `<strong>${topScore.toLocaleString()}</strong>`;
  elements.dialogMessage.innerHTML = `${completionReason} You scored <strong>${result.score.toLocaleString()}</strong> points with <strong>${result.hits}</strong> correct taps. The best score for ${modeName} mode is ${bestScore}.`;
}

function showMainMenu() {
  clearTimers();
  sessionId += 1;
  engine.reset();
  resetResultUi();
  elements.dialogTitle.textContent = "Ready to react?";
  elements.dialogMessage.innerHTML = INTRO_COPY_HTML;
  elements.overlay.hidden = false;
  elements.dialog.scrollTop = 0;
  render();
  for (const mode of Object.values(GAME_MODES)) {
    void refreshTopScore(mode);
  }
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

function openThemes() {
  closeLeaderboard();
  elements.themesPanel.hidden = false;
  elements.themesToggle.setAttribute("aria-expanded", "true");
  window.requestAnimationFrame(() => {
    elements.themesPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
  });
}

function closeThemes() {
  elements.themesPanel.hidden = true;
  elements.themesToggle.setAttribute("aria-expanded", "false");
}

function openLeaderboard() {
  closeThemes();
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

function updateTopScore(mode, entries, revision = null) {
  if (revision !== null && revision !== topScoreRevisions[mode]) return;
  const receivedTopScore = Array.isArray(entries) && entries.length > 0 ? entries[0].score : 0;
  topScores[mode] = topScores[mode] === null
    ? receivedTopScore
    : Math.max(topScores[mode], receivedTopScore);
  renderHud(engine.getSnapshot(now()));
  if (pendingResult?.mode === mode) {
    renderResultMessage(pendingResult);
  }
}

async function refreshTopScore(mode) {
  const revision = topScoreRevisions[mode] + 1;
  topScoreRevisions[mode] = revision;
  try {
    const response = await fetch(`/api/leaderboard?mode=${encodeURIComponent(mode)}`, {
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    const body = await readApiResponse(response);
    updateTopScore(mode, body.entries, revision);
  } catch {
    // Gameplay stays available if the shared leaderboard is temporarily offline.
  }
}

async function loadLeaderboard(mode = leaderboardMode) {
  selectLeaderboardMode(mode);
  openLeaderboard();
  const requestId = leaderboardRequestId + 1;
  leaderboardRequestId = requestId;
  const topScoreRevision = topScoreRevisions[mode] + 1;
  topScoreRevisions[mode] = topScoreRevision;
  setLeaderboardStatus("Loading scores…");
  elements.leaderboardList.replaceChildren();

  try {
    const response = await fetch(`/api/leaderboard?mode=${encodeURIComponent(mode)}`, {
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    const body = await readApiResponse(response);
    updateTopScore(mode, body.entries, topScoreRevision);
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
  const submittedResult = pendingResult;

  let name;
  try {
    name = sanitizePlayerName(elements.playerName.value);
  } catch (error) {
    setScoreStatus(error.message, true);
    elements.playerName.focus();
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
        mode: submittedResult.mode,
        score: submittedResult.score,
        hits: submittedResult.hits,
        dodges: submittedResult.dodges,
        fastestReactionMs: submittedResult.fastestReactionMs,
        averageReactionMs: submittedResult.averageReactionMs,
        survivalMs: submittedResult.survivalMs
      })
    });
    const body = await readApiResponse(response);
    submittedResult.submitted = true;
    topScoreRevisions[body.mode] += 1;
    updateTopScore(body.mode, body.entries);
    if (pendingResult === submittedResult) {
      elements.scoreSubmit.textContent = body.rank === null ? "Not ranked" : "Saved";
      setScoreStatus(
        body.rank === null
          ? "This result did not reach the current Top 20."
          : `Score saved at #${body.rank}.`
      );
    }
  } catch (error) {
    if (pendingResult === submittedResult) {
      elements.scoreSubmit.disabled = false;
      elements.playerName.disabled = false;
      setScoreStatus(error.message, true);
    }
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
    const glyph = document.createElement("span");
    glyph.className = "tile__glyph";
    glyph.setAttribute("aria-hidden", "true");
    tile.append(glyph);
    tile.addEventListener("pointerdown", handleTileTap);
    fragment.append(tile);
  }
  elements.board.replaceChildren(fragment);
}

function renderHud(snapshot) {
  elements.points.textContent = snapshot.points.toLocaleString();
  const topScore = topScores[snapshot.mode];
  elements.highScore.textContent = topScore === null ? "—" : topScore.toLocaleString();
  if (snapshot.mode === GAME_MODES.ZEN) {
    elements.modeLabel.textContent = "Mode";
    elements.modeName.textContent = "Zen";
  } else {
    elements.modeLabel.textContent = "Survived";
    elements.modeName.textContent = formatDuration(snapshot.elapsedMs);
  }
  const playerColor = getDisplayColor(snapshot.playerColorIndex);
  elements.colorName.textContent = playerColor.name;
  elements.colorGlyph.hidden = !colorBlindMode;
  elements.colorGlyph.textContent = colorBlindMode ? playerColor.glyph : "";
  elements.colorSwatch.style.background = playerColor.value;
  elements.colorSwatch.style.color = playerColor.ink;
  elements.colorHero.style.setProperty("--player-color", playerColor.value);

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
    const glyph = tile.querySelector(".tile__glyph");
    tile.className = "tile";
    glyph.textContent = "";
    tile.style.removeProperty("--tile-color");
    tile.style.removeProperty("--tile-ink");
    tile.setAttribute("aria-label", `Inactive cell ${index + 1}`);

    if (cell.kind !== "idle") {
      const color = getDisplayColor(cell.colorIndex);
      tile.classList.add("tile--lit");
      tile.style.setProperty("--tile-color", color.value);
      tile.style.setProperty("--tile-ink", color.ink);
      glyph.textContent = colorBlindMode ? color.glyph : "";
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
elements.mainMenuButton.addEventListener("click", showMainMenu);
elements.scoreForm.addEventListener("submit", submitScore);
elements.themesToggle.addEventListener("click", () => {
  if (elements.themesPanel.hidden) {
    openThemes();
  } else {
    closeThemes();
  }
});
for (const input of elements.themeInputs) {
  input.addEventListener("change", () => {
    if (input.checked) applyTheme(input.value);
  });
}
elements.colorBlindToggle.addEventListener("change", () => {
  applyColorBlindMode(elements.colorBlindToggle.checked);
});
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

initializeDisplaySettings();
engine.reset();
resetResultUi();
render();
for (const mode of Object.values(GAME_MODES)) {
  void refreshTopScore(mode);
}
