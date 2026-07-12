import { COLORS, GAME_MODES, THEMES, THEME_PALETTES } from "./config.js?v=20260712-4";
import { GameEngine, GAME_STATES } from "./game-engine.js?v=20260712-4";
import { createMusicController, MUSIC_STAGES } from "./music-controller.js?v=20260712-4";
import { createSoundController } from "./sound-controller.js?v=20260712-4";
import { sanitizePlayerName } from "../lib/leaderboard-model.js?v=20260712-4";

const INTRO_COPY_HTML =
  "Tap only the squares of <strong>Your color</strong> shown above the board. Fast reactions score more. Avoid wrong colors.";
const THEME_STORAGE_KEY = "speedytapper.theme.v1";
const COLOR_BLIND_STORAGE_KEY = "speedytapper.colorBlindMode.v1";
const SOUND_FX_STORAGE_KEY = "speedytapper.soundFx.v1";
const MUSIC_STORAGE_KEY = "speedytapper.music.v1";
const REMEMBERED_NAME_STORAGE_KEY = "speedytapper.leaderboardName.v1";

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
  gameMenuButton: document.querySelector("#game-menu-button"),
  gameRestartButton: document.querySelector("#game-restart-button"),
  gameUtility: document.querySelector("#game-utility"),
  highScore: document.querySelector("#high-score"),
  installButton: document.querySelector("#install-button"),
  leaderboardList: document.querySelector("#leaderboard-list"),
  leaderboardBackButton: document.querySelector("#leaderboard-back-button"),
  leaderboardPanel: document.querySelector("#leaderboard-panel"),
  leaderboardStatus: document.querySelector("#leaderboard-status"),
  leaderboardTabs: [...document.querySelectorAll("[data-leaderboard-mode]")],
  leaderboardToggle: document.querySelector("#leaderboard-toggle"),
  leaderboardView: document.querySelector("#leaderboard-view"),
  mainMenuButton: document.querySelector("#main-menu-button"),
  mainMenuContent: document.querySelector("#main-menu-content"),
  modeLabel: document.querySelector("#mode-label"),
  modeName: document.querySelector("#mode-name"),
  musicToggle: document.querySelector("#music-toggle"),
  normalButton: document.querySelector("#normal-button"),
  overlay: document.querySelector("#overlay"),
  playerName: document.querySelector("#player-name"),
  points: document.querySelector("#points"),
  responseProgress: document.querySelector("#response-progress"),
  responseProgressFill: document.querySelector("#response-progress-fill"),
  resultRestartButton: document.querySelector("#result-restart-button"),
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
  settingsCurrent: document.querySelector("#settings-current"),
  settingsBackButton: document.querySelector("#settings-back-button"),
  settingsPanel: document.querySelector("#settings-panel"),
  settingsToggle: document.querySelector("#settings-toggle"),
  settingsView: document.querySelector("#settings-view"),
  soundFxToggle: document.querySelector("#sound-fx-toggle"),
  statusLabel: document.querySelector("#status-label"),
  statusValue: document.querySelector("#status-value"),
  themeInputs: [...document.querySelectorAll('input[name="theme"]')],
  themeColorMeta: document.querySelector('meta[name="theme-color"]'),
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
let soundFxEnabled = false;
let musicEnabled = true;

const sound = createSoundController();
const music = createMusicController({
  fetchImpl: async (...args) => {
    await globalThis.speedyTapperWorkerReady;
    return globalThis.fetch(...args);
  }
});

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
    // Preferences and form conveniences remain usable for this session when storage is unavailable.
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
  const themeName = activeTheme === THEMES.DISCO ? "Disco" : "Classic";
  elements.settingsCurrent.textContent = `${themeName} · FX ${soundFxEnabled ? "on" : "off"} · Music ${musicEnabled ? "on" : "off"}`;
  elements.colorBlindToggle.checked = colorBlindMode;
  elements.soundFxToggle.checked = soundFxEnabled;
  elements.musicToggle.checked = musicEnabled;
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
  const storedSoundFx = readStoredPreference(SOUND_FX_STORAGE_KEY);
  soundFxEnabled = storedSoundFx === "on";
  sound.setEnabled(soundFxEnabled);
  const storedMusic = readStoredPreference(MUSIC_STORAGE_KEY);
  musicEnabled = storedMusic !== "off";
  music.setEnabled(musicEnabled);
  music.setStage(MUSIC_STAGES.MENU);
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

function applySoundFx(enabled) {
  soundFxEnabled = Boolean(enabled);
  sound.setEnabled(soundFxEnabled);
  renderDisplaySettings();
  writeStoredPreference(SOUND_FX_STORAGE_KEY, soundFxEnabled ? "on" : "off");
}

function applyMusic(enabled) {
  musicEnabled = Boolean(enabled);
  music.setEnabled(musicEnabled);
  music.setStage(MUSIC_STAGES.MENU);
  renderDisplaySettings();
  writeStoredPreference(MUSIC_STORAGE_KEY, musicEnabled ? "on" : "off");
}

function musicStageFor(snapshot) {
  if (snapshot.difficulty.gridDimension === 1) return MUSIC_STAGES.MENU;
  if (snapshot.difficulty.gridDimension === 2) return MUSIC_STAGES.GRID_2;
  return snapshot.difficulty.phaseId === "four-by-four-challenge"
    ? MUSIC_STAGES.CHALLENGE
    : MUSIC_STAGES.GRID_4;
}

function stopResponseProgress() {
  window.cancelAnimationFrame(progressFrame);
  progressFrame = null;
  elements.responseProgress.hidden = true;
  elements.responseProgressFill.style.transform = "scaleX(0)";
}

function renderResponseProgress(snapshot) {
  if (snapshot.state !== GAME_STATES.ACTIVE || snapshot.reactionProgress === null) {
    stopResponseProgress();
    return;
  }

  elements.responseProgress.hidden = false;
  const progress = Math.max(0, Math.min(1, snapshot.reactionProgress));
  elements.responseProgressFill.style.transform = `scaleX(${progress})`;
}

function startResponseProgress(currentSession) {
  window.cancelAnimationFrame(progressFrame);
  const tick = () => {
    if (
      currentSession !== sessionId ||
      document.hidden ||
      engine.state !== GAME_STATES.ACTIVE
    ) {
      stopResponseProgress();
      return;
    }
    renderResponseProgress(engine.getSnapshot(now()));
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
  stopResponseProgress();
  spawnTimer = null;
  deadlineTimer = null;
  runEndTimer = null;
  clockTimer = null;
  completionTimer = null;
  sound.tileOff();
}

function startGame(mode) {
  clearTimers();
  void sound.startRun();
  music.setStage(MUSIC_STAGES.MENU);
  void music.unlock();
  void refreshTopScore(mode);
  const currentSession = sessionId + 1;
  sessionId = currentSession;
  const startedAt = now();
  engine.start(startedAt, mode);
  resetResultUi();
  elements.gameUtility.hidden = false;
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
    music.setStage(musicStageFor(result.snapshot));
    sound.tileOn();
    render();
    startResponseProgress(currentSession);
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
    stopResponseProgress();
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
  stopResponseProgress();

  if (result.type === "hit") {
    music.setStage(musicStageFor(result.snapshot));
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
  music.setStage(MUSIC_STAGES.MENU);
  const isZen = snapshot.mode === GAME_MODES.ZEN;
  elements.dialogTitle.textContent = isZen ? "Minute complete" : "Game Over";
  elements.resultStats.hidden = false;
  elements.resultDurationLabel.textContent = isZen ? "Duration" : "Survived";
  elements.resultDurationValue.textContent = formatDuration(snapshot.elapsedMs, true);
  elements.resultFastestValue.textContent = formatReaction(snapshot.fastestReactionMs);
  elements.resultAverageValue.textContent = formatReaction(snapshot.averageReactionMs);
  elements.resultDodgesValue.textContent = snapshot.dodges.toLocaleString();
  elements.resultRestartButton.setAttribute(
    "aria-label",
    `Restart ${isZen ? "Zen" : "Normal"} mode`
  );
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
  elements.playerName.value = readStoredPreference(REMEMBERED_NAME_STORAGE_KEY) ?? "";
  elements.playerName.readOnly = false;
  elements.scoreSubmit.disabled = false;
  elements.scoreSubmit.textContent = "Save score";
  setScoreStatus("Enter your name to submit this run to the Top 20.");
  completionTimer = window.setTimeout(() => {
    if (currentSession === sessionId && engine.isRunComplete()) {
      elements.gameUtility.hidden = true;
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
  elements.gameUtility.hidden = true;
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
  closeSettings();
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
  music.setStage(MUSIC_STAGES.MENU);
  void music.unlock();
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

function restartCurrentMode() {
  const mode = pendingResult?.mode ?? engine.mode;
  startGame(mode);
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

function showMenuView(focusTarget = null) {
  closeSettings();
  closeLeaderboard();
  elements.resultContent.hidden = true;
  elements.mainMenuContent.hidden = false;
  elements.dialogTitle.textContent = "Ready to react?";
  elements.dialogMessage.innerHTML = INTRO_COPY_HTML;
  elements.dialog.scrollTop = 0;
  focusTarget?.focus({ preventScroll: true });
}

function openSettings() {
  closeLeaderboard();
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.settingsView.hidden = false;
  elements.dialogTitle.textContent = "Settings";
  elements.dialogMessage.textContent = "Choose how SpeedyTapper looks and sounds.";
  elements.dialog.scrollTop = 0;
  void music.unlock();
  elements.settingsBackButton.focus({ preventScroll: true });
}

function closeSettings() {
  elements.settingsView.hidden = true;
}

function openLeaderboard() {
  closeSettings();
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.leaderboardView.hidden = false;
  elements.dialogTitle.textContent = "Leaderboard";
  elements.dialogMessage.textContent = "Compare the best Normal and Zen runs.";
  elements.dialog.scrollTop = 0;
  void music.unlock();
  elements.leaderboardBackButton.focus({ preventScroll: true });
}

function closeLeaderboard() {
  leaderboardRequestId += 1;
  elements.leaderboardView.hidden = true;
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
  if (elements.leaderboardView.hidden) openLeaderboard();
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
  writeStoredPreference(REMEMBERED_NAME_STORAGE_KEY, name);

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
  if (!document.hidden) return;
  if (engine.state === GAME_STATES.IDLE || engine.state === GAME_STATES.GAME_OVER) {
    sound.suspend();
    music.suspend();
    return;
  }
  clearTimers();
  sound.suspend();
  music.setStage(MUSIC_STAGES.MENU);
  music.suspend();
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
elements.gameRestartButton.addEventListener("click", restartCurrentMode);
elements.gameMenuButton.addEventListener("click", showMainMenu);
elements.resultRestartButton.addEventListener("click", restartCurrentMode);
elements.mainMenuButton.addEventListener("click", showMainMenu);
elements.scoreForm.addEventListener("submit", submitScore);
elements.settingsToggle.addEventListener("click", openSettings);
elements.settingsBackButton.addEventListener("click", () => showMenuView(elements.settingsToggle));
for (const input of elements.themeInputs) {
  input.addEventListener("change", () => {
    if (input.checked) applyTheme(input.value);
  });
}
elements.colorBlindToggle.addEventListener("change", () => {
  applyColorBlindMode(elements.colorBlindToggle.checked);
});
elements.soundFxToggle.addEventListener("change", () => {
  applySoundFx(elements.soundFxToggle.checked);
  if (elements.soundFxToggle.checked) void sound.unlock();
});
elements.musicToggle.addEventListener("change", () => {
  applyMusic(elements.musicToggle.checked);
  if (elements.musicToggle.checked) void music.unlock();
});
elements.leaderboardToggle.addEventListener("click", () => loadLeaderboard(leaderboardMode));
elements.leaderboardBackButton.addEventListener("click", () => {
  showMenuView(elements.leaderboardToggle);
});
for (const tab of elements.leaderboardTabs) {
  tab.addEventListener("click", () => loadLeaderboard(tab.dataset.leaderboardMode));
}
document.addEventListener("visibilitychange", pauseForVisibilityChange);
document.addEventListener("pointerdown", () => {
  if (musicEnabled) void music.unlock();
}, { capture: true });
window.addEventListener("pagehide", () => {
  sound.suspend();
  music.suspend();
});

initializeDisplaySettings();
engine.reset();
resetResultUi();
render();
for (const mode of Object.values(GAME_MODES)) {
  void refreshTopScore(mode);
}
