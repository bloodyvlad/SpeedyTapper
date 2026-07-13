import { COLORS, GAME_MODES, THEMES, THEME_PALETTES } from "./config.js?v=20260713-3";
import { GameEngine, GAME_STATES } from "./game-engine.js?v=20260713-3";
import {
  predatesPresentation,
  reactionDeadline,
  reachedDeadline,
  remainingUntilDeadline,
  resolveInputTimestamp,
  scheduleAfterPaint,
  wasCoveredByDeadlineResolution
} from "./input-timing.js?v=20260713-3";
import {
  createMusicController,
  MUSIC_STAGES,
  resolveInteractiveMusicSection,
  resolveMusicStage
} from "./music-controller.js?v=20260713-3";
import { createSoundController } from "./sound-controller.js?v=20260713-3";
import { sanitizePlayerName } from "../lib/leaderboard-model.js?v=20260713-3";

const INTRO_COPY_HTML =
  "Tap only the squares of <strong>Your color</strong> shown above the board. Fast reactions score more. Avoid wrong colors.";
const THEME_STORAGE_KEY = "speedytapper.theme.v1";
const COLOR_BLIND_STORAGE_KEY = "speedytapper.colorBlindMode.v1";
const SOUND_FX_STORAGE_KEY = "speedytapper.soundFx.v1";
const MUSIC_STORAGE_KEY = "speedytapper.music.v1";
const INTERACTIVE_MUSIC_STORAGE_KEY = "speedytapper.interactiveMusic.v1";
const REMEMBERED_NAME_STORAGE_KEY = "speedytapper.leaderboardName.v1";

const elements = {
  app: document.querySelector("#app"),
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
  interactiveMusicToggle: document.querySelector("#interactive-music-toggle"),
  leaderboardList: document.querySelector("#leaderboard-list"),
  leaderboardBackButton: document.querySelector("#leaderboard-back-button"),
  leaderboardMenuButton: document.querySelector("#leaderboard-menu-button"),
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
  resultLeaderboardButton: document.querySelector("#result-leaderboard-button"),
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
let runStartFrame = null;
let roundActivationFrame = null;
let runEndCommit = null;
let deadlineCommit = null;
let sessionId = 0;
let completedSessionId = null;
let activeRoundVisibleAt = null;
let activeRoundId = null;
let nextRoundId = 0;
let lastDeadlineResolutionAt = null;
let roundPresentationExpired = false;
let runDeadlineAt = null;
let deferredInstallPrompt = null;
let pendingResult = null;
let leaderboardMode = GAME_MODES.NORMAL;
let leaderboardRequestId = 0;
let leaderboardReturnView = "menu";
let leaderboardReturnScrollTop = 0;
let dialogView = "menu";
let activeTheme = THEMES.CLASSIC;
let colorBlindMode = true;
let soundFxEnabled = false;
let musicEnabled = true;
let interactiveMusicEnabled = false;

const sound = createSoundController();
const presentationScheduler = Object.freeze({
  requestFrame: (callback) => window.requestAnimationFrame(callback),
  cancelFrame: (frameId) => window.cancelAnimationFrame(frameId)
});
const music = createMusicController({
  fetchImpl: async (...args) => {
    await globalThis.speedyTapperWorkerReady;
    return globalThis.fetch(...args);
  }
});

function now() {
  return performance.now();
}

function setOverlayVisible(visible) {
  elements.overlay.hidden = !visible;
  elements.app.inert = visible;
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
  const musicStatus = musicEnabled
    ? interactiveMusicEnabled ? "interactive" : "on"
    : "off";
  elements.settingsCurrent.textContent = `${themeName} · FX ${soundFxEnabled ? "on" : "off"} · Music ${musicStatus}`;
  elements.colorBlindToggle.checked = colorBlindMode;
  elements.soundFxToggle.checked = soundFxEnabled;
  elements.musicToggle.checked = musicEnabled;
  elements.interactiveMusicToggle.checked = interactiveMusicEnabled;
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
  const storedInteractiveMusic = readStoredPreference(INTERACTIVE_MUSIC_STORAGE_KEY);
  interactiveMusicEnabled = storedInteractiveMusic === "on";
  music.setInteractive(interactiveMusicEnabled);
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

function applyInteractiveMusic(enabled) {
  interactiveMusicEnabled = Boolean(enabled);
  music.setInteractive(interactiveMusicEnabled);
  music.setStage(MUSIC_STAGES.MENU);
  renderDisplaySettings();
  writeStoredPreference(
    INTERACTIVE_MUSIC_STORAGE_KEY,
    interactiveMusicEnabled ? "on" : "off"
  );
}

function musicStageFor(snapshot) {
  return resolveMusicStage(snapshot, engine.config.musicStageStartsAtMs);
}

function updateMusicForSnapshot(snapshot) {
  if (interactiveMusicEnabled) {
    music.setInteractiveSection(resolveInteractiveMusicSection(snapshot));
    return;
  }
  music.setStage(musicStageFor(snapshot));
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

function startResponseProgress(currentSession, initialSnapshot) {
  window.cancelAnimationFrame(progressFrame);
  renderResponseProgress(initialSnapshot);
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
  progressFrame = window.requestAnimationFrame(tick);
}

function clearTimers() {
  window.clearTimeout(spawnTimer);
  window.clearTimeout(deadlineTimer);
  window.clearTimeout(runEndTimer);
  window.clearInterval(clockTimer);
  window.clearTimeout(completionTimer);
  window.cancelAnimationFrame(runStartFrame);
  window.cancelAnimationFrame(roundActivationFrame);
  runEndCommit?.cancel();
  deadlineCommit?.cancel();
  stopResponseProgress();
  spawnTimer = null;
  deadlineTimer = null;
  runEndTimer = null;
  clockTimer = null;
  completionTimer = null;
  runStartFrame = null;
  roundActivationFrame = null;
  runEndCommit = null;
  deadlineCommit = null;
  activeRoundVisibleAt = null;
  activeRoundId = null;
  roundPresentationExpired = false;
  runDeadlineAt = null;
  sound.tileOff();
}

function startGame(mode) {
  clearTimers();
  void sound.startRun();
  music.setStage(MUSIC_STAGES.MENU);
  music.startRun();
  void music.unlock();
  void refreshTopScore(mode);
  const currentSession = sessionId + 1;
  sessionId = currentSession;
  completedSessionId = null;
  lastDeadlineResolutionAt = null;
  engine.reset();
  resetResultUi();
  elements.gameUtility.hidden = false;
  setOverlayVisible(false);
  elements.dialogTitle.textContent = "Ready to react?";
  runStartFrame = window.requestAnimationFrame((visibleAt) => {
    runStartFrame = null;
    if (currentSession !== sessionId || document.hidden) return;
    const initialSnapshot = engine.start(visibleAt, mode);
    updateMusicForSnapshot(initialSnapshot);
    render();

    clockTimer = window.setInterval(() => {
      const snapshot = engine.getSnapshot(now());
      renderHud(snapshot);
      updateMusicForSnapshot(snapshot);
    }, 100);

    if (mode === GAME_MODES.ZEN) {
      runDeadlineAt = visibleAt + engine.config.zenDurationMs;
      scheduleZenEnd(currentSession, runDeadlineAt);
    }

    scheduleRound(currentSession);
  });
}

function scheduleRound(currentSession, additionalDelayMs = 0) {
  if (engine.state === GAME_STATES.GAME_OVER || currentSession !== sessionId) return;

  const delayMs = engine.getNextDelayMs(now());
  spawnTimer = window.setTimeout(() => {
    if (currentSession !== sessionId || document.hidden || engine.state === GAME_STATES.GAME_OVER) return;
    spawnTimer = null;
    roundActivationFrame = window.requestAnimationFrame((visibleAt) => {
      roundActivationFrame = null;
      if (
        currentSession !== sessionId ||
        document.hidden ||
        engine.state === GAME_STATES.GAME_OVER
      ) {
        return;
      }
      const result = engine.activateRound(visibleAt);
      if (result.type !== "round-active") return;
      const roundId = nextRoundId + 1;
      nextRoundId = roundId;
      activeRoundId = roundId;
      activeRoundVisibleAt = visibleAt;
      roundPresentationExpired = false;
      updateMusicForSnapshot(result.snapshot);
      sound.tileOn();
      const deadlineAt = reactionDeadline(
        visibleAt,
        result.snapshot.difficulty.responseWindowMs
      );
      scheduleDeadline(currentSession, roundId, deadlineAt);
      render();
      startResponseProgress(currentSession, result.snapshot);
    });
  }, delayMs + additionalDelayMs);
}

function scheduleDeadline(currentSession, roundId, deadlineAt) {
  const delayMs = remainingUntilDeadline(deadlineAt, now());
  deadlineTimer = window.setTimeout(() => {
    deadlineTimer = null;
    if (
      currentSession !== sessionId ||
      roundId !== activeRoundId ||
      document.hidden ||
      engine.state !== GAME_STATES.ACTIVE
    ) {
      return;
    }
    roundPresentationExpired = true;
    sound.tileOff();
    stopResponseProgress();
    render();

    // Give pointer events already generated for the visible tile one rendering
    // turn to resolve by their original timestamps before committing expiry.
    deadlineCommit = scheduleAfterPaint(presentationScheduler, () => {
      deadlineCommit = null;
      if (
        currentSession !== sessionId ||
        roundId !== activeRoundId ||
        document.hidden ||
        engine.state !== GAME_STATES.ACTIVE
      ) {
        return;
      }
      const resolvedAt = now();
      const result = engine.expireRound(resolvedAt);
      if (result.type === "ignored" && result.reason === "not-expired") {
        roundPresentationExpired = false;
        render();
        scheduleDeadline(currentSession, roundId, deadlineAt);
        return;
      }
      lastDeadlineResolutionAt = resolvedAt;
      activeRoundVisibleAt = null;
      activeRoundId = null;
      roundPresentationExpired = false;
      if (result.type === "ignored-color") {
        showFeedback(`Dodged that! +${result.pointsAwarded}`, false);
        render();
        scheduleRound(currentSession);
        return;
      }
      if (result.type === "miss") {
        handleMiss(result, currentSession);
      }
    });
  }, delayMs);
}

function handleTileTap(event) {
  event.preventDefault();
  const cellIndex = Number.parseInt(event.currentTarget.dataset.index, 10);
  const handledAt = now();
  const inputAt = resolveInputTimestamp(event.timeStamp, handledAt);
  if (
    engine.mode === GAME_MODES.ZEN &&
    reachedDeadline(inputAt, runDeadlineAt)
  ) {
    finishZenRun(sessionId, inputAt);
    return;
  }
  if (
    engine.state === GAME_STATES.ACTIVE &&
    predatesPresentation(inputAt, activeRoundVisibleAt)
  ) {
    return;
  }
  if (
    engine.state === GAME_STATES.WAITING &&
    wasCoveredByDeadlineResolution(inputAt, lastDeadlineResolutionAt)
  ) {
    return;
  }
  const result = engine.tap(cellIndex, inputAt);
  if (result.type === "ignored") return;

  sound.tileOff();
  window.clearTimeout(spawnTimer);
  spawnTimer = null;
  window.clearTimeout(deadlineTimer);
  deadlineTimer = null;
  window.cancelAnimationFrame(roundActivationFrame);
  roundActivationFrame = null;
  deadlineCommit?.cancel();
  deadlineCommit = null;
  activeRoundVisibleAt = null;
  activeRoundId = null;
  roundPresentationExpired = false;
  if (result.type === "miss" && result.reason === "late") {
    lastDeadlineResolutionAt = handledAt;
  }
  stopResponseProgress();

  if (result.type === "hit") {
    music.playCorrectTap(result.snapshot.hits);
    updateMusicForSnapshot(result.snapshot);
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

function scheduleZenEnd(currentSession, deadlineAt) {
  const delayMs = remainingUntilDeadline(deadlineAt, now());
  runEndTimer = window.setTimeout(() => {
    runEndTimer = null;
    if (currentSession !== sessionId || document.hidden) return;
    runEndCommit = scheduleAfterPaint(presentationScheduler, () => {
      runEndCommit = null;
      finishZenRun(currentSession, now());
    });
  }, delayMs);
}

function finishZenRun(currentSession, finishedAt = now()) {
  if (currentSession !== sessionId) return;
  const result = engine.finishTimedRun(finishedAt);
  if (result.type === "ignored" && result.reason === "time-remaining") {
    scheduleZenEnd(currentSession, runDeadlineAt);
    return;
  }
  if (result.type === "time-up") {
    finishGame(result.snapshot, currentSession);
  }
}

function finishGame(snapshot, currentSession) {
  if (
    currentSession !== sessionId ||
    completedSessionId === currentSession ||
    !engine.isRunComplete()
  ) {
    return;
  }
  completedSessionId = currentSession;
  clearTimers();
  music.advanceTrack(MUSIC_STAGES.MENU);
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
  dialogView = "result";
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
  elements.resultLeaderboardButton.disabled = false;
  setScoreStatus("Enter your name to submit this run to the Top 1,000.");
  completionTimer = window.setTimeout(() => {
    if (currentSession === sessionId && engine.isRunComplete()) {
      elements.gameUtility.hidden = true;
      setOverlayVisible(true);
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
  dialogView = "menu";
  leaderboardReturnView = "menu";
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
  elements.resultLeaderboardButton.disabled = false;
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
  setOverlayVisible(true);
  elements.dialog.scrollTop = 0;
  render();
  elements.normalButton.focus({ preventScroll: true });
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
    tab.setAttribute("aria-pressed", String(selected));
  }
}

function showMenuView(focusTarget = null) {
  closeSettings();
  closeLeaderboard();
  dialogView = "menu";
  leaderboardReturnView = "menu";
  elements.resultContent.hidden = true;
  elements.mainMenuContent.hidden = false;
  elements.dialogTitle.textContent = "Ready to react?";
  elements.dialogMessage.innerHTML = INTRO_COPY_HTML;
  elements.dialog.scrollTop = 0;
  focusTarget?.focus({ preventScroll: true });
}

function openSettings() {
  closeLeaderboard();
  dialogView = "settings";
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

function openLeaderboard(returnView = "menu") {
  closeSettings();
  leaderboardReturnView = returnView;
  leaderboardReturnScrollTop = elements.dialog.scrollTop;
  dialogView = "leaderboard";
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

function showResultView(focusTarget = null) {
  if (!pendingResult) {
    showMenuView(elements.leaderboardToggle);
    return;
  }

  closeSettings();
  closeLeaderboard();
  dialogView = "result";
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = false;
  elements.dialogTitle.textContent =
    pendingResult.mode === GAME_MODES.ZEN ? "Minute complete" : "Game Over";
  renderResultMessage(pendingResult);
  elements.dialog.scrollTop = leaderboardReturnScrollTop;
  focusTarget?.focus({ preventScroll: true });
}

function returnFromLeaderboard() {
  if (leaderboardReturnView === "result" && pendingResult) {
    showResultView(elements.resultLeaderboardButton);
    return;
  }
  showMenuView(elements.leaderboardToggle);
}

function renderLeaderboard(entries, mode, playerRank = null) {
  elements.leaderboardList.replaceChildren();

  if (!Array.isArray(entries) || entries.length === 0) {
    const empty = document.createElement("li");
    empty.className = "leaderboard-empty";
    empty.textContent = "No scores yet — the first place is waiting.";
    elements.leaderboardList.append(empty);
    return;
  }

  const fragment = document.createDocumentFragment();
  let previousRank = null;
  for (const [index, entry] of entries.entries()) {
    const entryRank = Number.isInteger(entry.rank) ? entry.rank : index + 1;
    if (previousRank !== null && entryRank > previousRank + 1) {
      const gap = document.createElement("li");
      gap.className = "leaderboard-gap";
      gap.setAttribute("aria-label", `Ranks ${previousRank + 1} through ${entryRank - 1} omitted`);
      gap.textContent = "…";
      fragment.append(gap);
    }

    const row = document.createElement("li");
    row.className = "leaderboard-entry";
    const isPlayerResult = entryRank === playerRank;
    row.classList.toggle("is-current", isPlayerResult);

    const rank = document.createElement("span");
    rank.className = "leaderboard-entry__rank";
    rank.textContent = String(entryRank);

    const player = document.createElement("div");
    player.className = "leaderboard-entry__player";
    const nameLine = document.createElement("div");
    nameLine.className = "leaderboard-entry__name-line";
    const name = document.createElement("div");
    name.className = "leaderboard-entry__name";
    name.textContent = entry.name;
    nameLine.append(name);
    if (isPlayerResult) {
      const currentBadge = document.createElement("span");
      currentBadge.className = "leaderboard-entry__current";
      currentBadge.textContent = "This run";
      nameLine.append(currentBadge);
    }
    const meta = document.createElement("div");
    meta.className = "leaderboard-entry__meta";
    const runMeta = document.createElement("span");
    runMeta.textContent = `${formatDuration(entry.survivalMs, true)} ${
      mode === GAME_MODES.NORMAL ? "survived" : "played"
    } · ${entry.hits.toLocaleString()} taps · ${(entry.dodges ?? 0).toLocaleString()} dodged`;
    const reactionMeta = document.createElement("span");
    reactionMeta.textContent = `Fastest ${formatReaction(entry.fastestReactionMs)} · Average ${formatReaction(entry.averageReactionMs)}`;
    meta.append(runMeta, reactionMeta);
    player.append(nameLine, meta);

    const score = document.createElement("strong");
    score.className = "leaderboard-entry__score";
    score.textContent = entry.score.toLocaleString();

    row.append(rank, player, score);
    fragment.append(row);
    previousRank = entryRank;
  }
  elements.leaderboardList.append(fragment);
}

function setLeaderboardSummary(totalEntries, playerRank = null, hasSubmittedResult = false) {
  const safeTotal = Number.isInteger(totalEntries) ? totalEntries : 0;
  if (safeTotal === 0) {
    setLeaderboardStatus("No ranked results yet.");
    return;
  }
  if (hasSubmittedResult && playerRank === null) {
    setLeaderboardStatus(
      `${safeTotal.toLocaleString()} ranked results · This run is outside the Top 1,000.`
    );
    return;
  }
  if (playerRank !== null) {
    setLeaderboardStatus(
      `${safeTotal.toLocaleString()} ranked ${safeTotal === 1 ? "result" : "results"} · This run is #${playerRank.toLocaleString()}.`
    );
    return;
  }
  setLeaderboardStatus(
    `${safeTotal.toLocaleString()} ranked ${safeTotal === 1 ? "result" : "results"} · Showing the top ${Math.min(5, safeTotal)}.`
  );
}

function renderSubmittedLeaderboard(mode) {
  if (
    pendingResult?.mode !== mode ||
    !Array.isArray(pendingResult.leaderboardEntries)
  ) {
    return false;
  }

  leaderboardRequestId += 1;
  selectLeaderboardMode(mode);
  renderLeaderboard(pendingResult.leaderboardEntries, mode, pendingResult.rank);
  setLeaderboardSummary(
    pendingResult.leaderboardTotalEntries,
    pendingResult.rank,
    pendingResult.submitted
  );
  return true;
}

function showLeaderboardMode(mode) {
  if (leaderboardReturnView === "result" && renderSubmittedLeaderboard(mode)) return;
  void loadLeaderboard(mode);
}

function openResultLeaderboard() {
  if (!pendingResult) return;
  openLeaderboard("result");
  showLeaderboardMode(pendingResult.mode);
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
  if (dialogView === "result" && pendingResult?.mode === mode) {
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

async function loadLeaderboard(mode = leaderboardMode, returnView = leaderboardReturnView) {
  selectLeaderboardMode(mode);
  if (elements.leaderboardView.hidden) openLeaderboard(returnView);
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
    setLeaderboardSummary(body.totalEntries);
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
  elements.resultLeaderboardButton.disabled = true;
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
    submittedResult.rank = body.rank;
    submittedResult.leaderboardEntries = body.entries;
    submittedResult.leaderboardTotalEntries = body.totalEntries;
    topScoreRevisions[body.mode] += 1;
    updateTopScore(body.mode, body.entries);
    if (pendingResult === submittedResult) {
      elements.scoreSubmit.textContent = body.rank === null ? "Not ranked" : "Saved";
      setScoreStatus(
        body.rank === null
          ? "This result did not reach the current Top 1,000."
          : `Score saved at #${body.rank}.`
      );
      elements.resultLeaderboardButton.disabled = false;
    }
  } catch (error) {
    if (pendingResult === submittedResult) {
      elements.scoreSubmit.disabled = false;
      elements.playerName.disabled = false;
      elements.resultLeaderboardButton.disabled = false;
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
    const cell = roundPresentationExpired && snapshot.state === GAME_STATES.ACTIVE
      ? { kind: "idle", colorIndex: null }
      : snapshot.cells[index] ?? { kind: "idle", colorIndex: null };
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

function stopRunForPageExit() {
  if (
    runStartFrame === null &&
    engine.state !== GAME_STATES.WAITING &&
    engine.state !== GAME_STATES.ACTIVE
  ) {
    return;
  }
  clearTimers();
  music.setStage(MUSIC_STAGES.MENU);
  sessionId += 1;
  resetResultUi();
  elements.dialogTitle.textContent = "Run paused";
  elements.dialogMessage.textContent = "The app moved into the background, so this run was stopped. Choose a mode to restart.";
  setOverlayVisible(true);
}

function pauseForVisibilityChange() {
  if (!document.hidden) return;
  stopRunForPageExit();
  sound.suspend();
  music.suspend();
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
elements.resultLeaderboardButton.addEventListener("click", openResultLeaderboard);
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
elements.interactiveMusicToggle.addEventListener("change", () => {
  applyInteractiveMusic(elements.interactiveMusicToggle.checked);
  if (musicEnabled) void music.unlock();
});
elements.leaderboardToggle.addEventListener("click", () => loadLeaderboard(leaderboardMode, "menu"));
elements.leaderboardBackButton.addEventListener("click", returnFromLeaderboard);
elements.leaderboardMenuButton.addEventListener("click", showMainMenu);
for (const tab of elements.leaderboardTabs) {
  tab.addEventListener("click", () => showLeaderboardMode(tab.dataset.leaderboardMode));
}
document.addEventListener("visibilitychange", pauseForVisibilityChange);
document.addEventListener("pointerdown", () => {
  if (musicEnabled) void music.unlock();
}, { capture: true });
window.addEventListener("pagehide", () => {
  stopRunForPageExit();
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
