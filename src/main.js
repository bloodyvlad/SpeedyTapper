import { COLORS, GAME_MODES, THEMES, THEME_PALETTES } from "./config.js?v=20260714-1";
import { GameEngine, GAME_STATES } from "./game-engine.js?v=20260714-1";
import {
  predatesPresentation,
  reactionDeadline,
  reachedDeadline,
  remainingUntilDeadline,
  resolveInputTimestamp,
  scheduleAfterPaint,
  wasCoveredByDeadlineResolution
} from "./input-timing.js?v=20260714-1";
import {
  createMusicController,
  MUSIC_STAGES,
  resolveInteractiveMusicSection,
  resolveMusicStage
} from "./music-controller.js?v=20260714-1";
import {
  getPet,
  isPetId,
  normalizeOwnedPetIds,
  PET_CATALOG
} from "./pet-catalog.js?v=20260714-1";
import { createPetController } from "./pet-controller.js?v=20260714-1";
import { createSoundController } from "./sound-controller.js?v=20260714-1";
import { createProfileClient, ProfileApiError } from "./profile-client.js?v=20260714-1";

const INTRO_COPY_HTML =
  "Tap only the squares of <strong>Your color</strong> shown above the board. Fast reactions score more. Avoid wrong colors.";
const APP_BUILD_ID = "20260714-1";
const THEME_STORAGE_KEY = "speedytapper.theme.v1";
const COLOR_BLIND_STORAGE_KEY = "speedytapper.colorBlindMode.v1";
const SOUND_FX_STORAGE_KEY = "speedytapper.soundFx.v1";
const MUSIC_STORAGE_KEY = "speedytapper.music.v1";
const INTERACTIVE_MUSIC_STORAGE_KEY = "speedytapper.interactiveMusic.v1";
const SPEED_RATING_ORDER = Object.freeze(["godlike", "perfect", "great", "good"]);
const SPEED_RATING_LABELS = Object.freeze({
  godlike: "Godlike",
  perfect: "Perfect",
  great: "Great",
  good: "Good"
});

const elements = {
  achievementCards: [...document.querySelectorAll("[data-achievement-id]")],
  achievementsBackButton: document.querySelector("#achievements-back-button"),
  achievementsList: document.querySelector("#achievements-list"),
  achievementsProfileButton: document.querySelector("#achievements-profile-button"),
  achievementsProgress: document.querySelector("#achievements-progress"),
  achievementsStatus: document.querySelector("#achievements-status"),
  achievementsSummary: document.querySelector("#achievements-summary"),
  achievementsToggle: document.querySelector("#achievements-toggle"),
  achievementsView: document.querySelector("#achievements-view"),
  app: document.querySelector("#app"),
  board: document.querySelector("#board"),
  colorBlindToggle: document.querySelector("#color-blind-toggle"),
  coinBalance: document.querySelector("#coin-balance"),
  coinCount: document.querySelector("#coin-count"),
  colorHero: document.querySelector("#color-hero"),
  colorGlyph: document.querySelector("#color-glyph"),
  colorName: document.querySelector("#color-name"),
  colorSwatch: document.querySelector("#color-swatch"),
  dialog: document.querySelector(".dialog"),
  dialogUtility: document.querySelector("#dialog-utility"),
  dialogMessage: document.querySelector("#dialog-message"),
  dialogTitle: document.querySelector("#dialog-title"),
  feedback: document.querySelector("#feedback"),
  gameArea: document.querySelector(".game"),
  gameMenuButton: document.querySelector("#game-menu-button"),
  gameRestartButton: document.querySelector("#game-restart-button"),
  gameUtility: document.querySelector("#game-utility"),
  highScore: document.querySelector("#high-score"),
  installButton: document.querySelector("#install-button"),
  interactiveMusicToggle: document.querySelector("#interactive-music-toggle"),
  leaderboardRank: document.querySelector("#leaderboard-rank"),
  leaderboardList: document.querySelector("#leaderboard-list"),
  leaderboardBackButton: document.querySelector("#leaderboard-back-button"),
  leaderboardMenuButton: document.querySelector("#leaderboard-menu-button"),
  leaderboardPanel: document.querySelector("#leaderboard-panel"),
  leaderboardPlayerPosition: document.querySelector("#leaderboard-player-position"),
  leaderboardStatus: document.querySelector("#leaderboard-status"),
  leaderboardTabs: [...document.querySelectorAll("[data-leaderboard-mode]")],
  leaderboardToggle: document.querySelector("#leaderboard-toggle"),
  leaderboardView: document.querySelector("#leaderboard-view"),
  mainMenuButton: document.querySelector("#main-menu-button"),
  mainMenuContent: document.querySelector("#main-menu-content"),
  gamePetScene: document.querySelector("#game-pet-scene"),
  menuPetScene: document.querySelector("#menu-pet-scene"),
  modeLabel: document.querySelector("#mode-label"),
  modeName: document.querySelector("#mode-name"),
  musicToggle: document.querySelector("#music-toggle"),
  normalButton: document.querySelector("#normal-button"),
  overlay: document.querySelector("#overlay"),
  points: document.querySelector("#points"),
  profileAuthStatus: document.querySelector("#profile-auth-status"),
  profileBackButton: document.querySelector("#profile-back-button"),
  profileForm: document.querySelector("#profile-form"),
  profileGoogleSignin: document.querySelector("#profile-google-signin"),
  profileLogout: document.querySelector("#profile-logout"),
  profileMenuButton: document.querySelector("#profile-menu-button"),
  profileModeTabs: [...document.querySelectorAll("[data-profile-mode]")],
  profileNeighbors: document.querySelector("#profile-neighbors"),
  profileNickname: document.querySelector("#profile-nickname"),
  profileRankCard: document.querySelector("#profile-rank-card"),
  profileSave: document.querySelector("#profile-save"),
  profileSignedIn: document.querySelector("#profile-signed-in"),
  profileSignedOut: document.querySelector("#profile-signed-out"),
  profileStatus: document.querySelector("#profile-status"),
  profileToggle: document.querySelector("#profile-toggle"),
  profileView: document.querySelector("#profile-view"),
  petShopActions: [...document.querySelectorAll("[data-pet-action]")],
  petShopBackButton: document.querySelector("#pet-shop-back-button"),
  petShopBalance: document.querySelector("#pet-shop-balance"),
  petShopCards: [...document.querySelectorAll("[data-pet-card]")],
  petShopCurrent: document.querySelector("#pet-shop-current"),
  petShopEquippedBadges: [...document.querySelectorAll("[data-pet-equipped]")],
  petShopPreviews: [...document.querySelectorAll("[data-pet-preview]")],
  petShopStatus: document.querySelector("#pet-shop-status"),
  petShopToggle: document.querySelector("#pet-shop-toggle"),
  petShopView: document.querySelector("#pet-shop-view"),
  responseProgress: document.querySelector("#response-progress"),
  responseProgressFill: document.querySelector("#response-progress-fill"),
  resultRestartButton: document.querySelector("#result-restart-button"),
  resultAverageValue: document.querySelector("#result-average-value"),
  resultContent: document.querySelector("#result-content"),
  resultDodgesValue: document.querySelector("#result-dodges-value"),
  resultDurationLabel: document.querySelector("#result-duration-label"),
  resultDurationValue: document.querySelector("#result-duration-value"),
  resultFastestValue: document.querySelector("#result-fastest-value"),
  resultGoogleSignin: document.querySelector("#result-google-signin"),
  resultSavePanel: document.querySelector("#result-save-panel"),
  resultSaveStatus: document.querySelector("#result-save-status"),
  resultScoreValue: document.querySelector("#result-score-value"),
  resultStats: document.querySelector("#result-stats"),
  settingsCurrent: document.querySelector("#settings-current"),
  settingsBackButton: document.querySelector("#settings-back-button"),
  settingsPanel: document.querySelector("#settings-panel"),
  settingsToggle: document.querySelector("#settings-toggle"),
  settingsView: document.querySelector("#settings-view"),
  soundFxToggle: document.querySelector("#sound-fx-toggle"),
  scoreMultiplier: document.querySelector("#score-multiplier"),
  speedRatingOverlay: document.querySelector("#speed-rating-overlay"),
  speedSummaryBar: document.querySelector("#speed-summary-bar"),
  speedSummaryLegend: document.querySelector("#speed-summary-legend"),
  speedSummarySegments: [...document.querySelectorAll("[data-speed-segment]")],
  speedSummaryTotal: document.querySelector("#speed-summary-total"),
  streakMeter: document.querySelector("#streak-meter"),
  statusLabel: document.querySelector("#status-label"),
  statusValue: document.querySelector("#status-value"),
  themeInputs: [...document.querySelectorAll('input[name="theme"]')],
  themeColorMeta: document.querySelector('meta[name="theme-color"]'),
  zenButton: document.querySelector("#zen-button")
};

const engine = new GameEngine();
const profileClient = createProfileClient();
const pets = createPetController({
  menuScene: elements.menuPetScene,
  gameplayScene: elements.gamePetScene,
  board: elements.board,
  dialog: elements.dialog,
  gameArea: elements.gameArea,
  streakMeter: elements.streakMeter
});
const topScores = {
  [GAME_MODES.NORMAL]: null,
  [GAME_MODES.ZEN]: null
};
const topScoreRevisions = {
  [GAME_MODES.NORMAL]: 0,
  [GAME_MODES.ZEN]: 0
};
let spawnTimer = null;
let decoySpawnTimer = null;
let decoyExpiryTimer = null;
let deadlineTimer = null;
let runEndTimer = null;
let clockTimer = null;
let feedbackTimer = null;
let completionTimer = null;
let progressFrame = null;
let runStartFrame = null;
let roundActivationFrame = null;
let decoyActivationFrame = null;
let decoyCadenceId = 0;
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
let currentRunId = null;
let currentRunTicket = null;
let runStartRequestId = 0;
let deferredInstallPrompt = null;
let pendingResult = null;
let leaderboardMode = GAME_MODES.NORMAL;
let leaderboardRequestId = 0;
let leaderboardReturnView = "menu";
let leaderboardReturnScrollTop = 0;
let dialogView = "menu";
let profileReturnView = "menu";
let profileMode = GAME_MODES.NORMAL;
let profileRequestId = 0;
let petShopMutationId = 0;
let petShopPendingPetId = null;
const petPreviewTimers = new Map();
let achievementsRequestId = 0;
let achievementsPayload = null;
let achievementClaimId = null;
let profileSession = Object.freeze({
  authenticated: false,
  googleClientId: null,
  profile: null,
  ranks: Object.freeze({}),
  coinBalance: 0
});
let googleIdentityPromise = null;
let googleIdentityClientId = null;
let speedRatingTimer = null;
let speedRatingPlacementRight = false;
let activeTheme = THEMES.CLASSIC;
let colorBlindMode = true;
let soundFxEnabled = true;
let musicEnabled = true;
let interactiveMusicEnabled = true;

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

function createRunId() {
  if (typeof globalThis.crypto?.randomUUID === "function") {
    return globalThis.crypto.randomUUID();
  }
  const bytes = new Uint8Array(16);
  if (typeof globalThis.crypto?.getRandomValues === "function") {
    globalThis.crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((value) => value.toString(16).padStart(2, "0"));
  return `${hex.slice(0, 4).join("")}-${hex.slice(4, 6).join("")}-${hex.slice(6, 8).join("")}-${hex.slice(8, 10).join("")}-${hex.slice(10).join("")}`;
}

function setOverlayVisible(visible) {
  elements.overlay.hidden = !visible;
  elements.app.inert = visible;
  pets.setGameplayVisible(!visible);
}

function setDialogView(view) {
  dialogView = view;
  pets.setMenuView(view);
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

function normalizeSpeedRatings(value) {
  return Object.fromEntries(
    SPEED_RATING_ORDER.map((rating) => [
      rating,
      Number.isInteger(value?.[rating]) && value[rating] >= 0 ? value[rating] : 0
    ])
  );
}

function hideSpeedRating() {
  if (!elements.speedRatingOverlay) return;
  elements.speedRatingOverlay.className = "speed-rating-overlay";
  elements.speedRatingOverlay.textContent = "";
}

function showSpeedRating(speedRating) {
  if (!speedRating?.id || !SPEED_RATING_LABELS[speedRating.id]) return;
  window.clearTimeout(speedRatingTimer);
  speedRatingPlacementRight = Math.random() >= 0.5;
  const placement = speedRatingPlacementRight ? "right" : "left";
  elements.speedRatingOverlay.className = "speed-rating-overlay";
  void elements.speedRatingOverlay.offsetWidth;
  elements.speedRatingOverlay.textContent = SPEED_RATING_LABELS[speedRating.id];
  elements.speedRatingOverlay.className = [
    "speed-rating-overlay",
    `speed-rating-overlay--${placement}`,
    `speed-rating-overlay--${speedRating.id}`,
    "speed-rating-overlay--visible"
  ].join(" ");
  speedRatingTimer = window.setTimeout(hideSpeedRating, 640);
}

function renderSpeedSummary(speedRatings) {
  const ratings = normalizeSpeedRatings(speedRatings);
  const total = SPEED_RATING_ORDER.reduce((sum, rating) => sum + ratings[rating], 0);
  elements.speedSummaryTotal.textContent = `${total.toLocaleString()} ${total === 1 ? "tap" : "taps"}`;
  elements.speedSummaryLegend.replaceChildren();

  for (const segment of elements.speedSummarySegments) {
    const rating = segment.dataset.speedSegment;
    const percentage = total > 0 ? (ratings[rating] / total) * 100 : 0;
    segment.style.flexBasis = `${percentage}%`;
  }

  const summary = SPEED_RATING_ORDER.map(
    (rating) => `${SPEED_RATING_LABELS[rating]} ${ratings[rating].toLocaleString()}`
  ).join(", ");
  elements.speedSummaryBar.setAttribute(
    "aria-label",
    total > 0 ? `${summary}.` : "No rated taps yet."
  );

  const fragment = document.createDocumentFragment();
  for (const rating of SPEED_RATING_ORDER) {
    const item = document.createElement("div");
    item.className = `speed-summary__item speed-summary__item--${rating}`;
    const label = document.createElement("span");
    label.className = "speed-summary__label";
    const dot = document.createElement("span");
    dot.className = "speed-summary__dot";
    dot.setAttribute("aria-hidden", "true");
    const labelText = document.createElement("span");
    labelText.textContent = SPEED_RATING_LABELS[rating];
    label.append(dot, labelText);
    const count = document.createElement("strong");
    count.textContent = ratings[rating].toLocaleString();
    item.append(label, count);
    fragment.append(item);
  }
  elements.speedSummaryLegend.append(fragment);
}

function createLeaderboardSpeedBar(speedRatings) {
  const ratings = normalizeSpeedRatings(speedRatings);
  const total = SPEED_RATING_ORDER.reduce((sum, rating) => sum + ratings[rating], 0);
  const bar = document.createElement("div");
  bar.className = "leaderboard-entry__speed-bar";
  bar.setAttribute("aria-hidden", "true");
  for (const rating of SPEED_RATING_ORDER) {
    const segment = document.createElement("span");
    segment.className = `leaderboard-entry__speed-segment leaderboard-entry__speed-segment--${rating}`;
    segment.style.flexBasis = `${total > 0 ? (ratings[rating] / total) * 100 : 0}%`;
    bar.append(segment);
  }
  return bar;
}

function renderStreak(snapshot) {
  const maximumReached = snapshot.multiplier >= snapshot.maximumMultiplier;
  const progress = maximumReached
    ? 1
    : Math.max(0, Math.min(1, snapshot.streakProgress / snapshot.streakTarget));
  elements.streakMeter.style.setProperty("--streak-progress", String(progress));
  elements.streakMeter.classList.toggle("streak-meter--full", maximumReached);
  elements.streakMeter.dataset.multiplier = String(snapshot.multiplier);
  elements.scoreMultiplier.textContent = `x${snapshot.multiplier}`;
  const nextMultiplier = Math.min(snapshot.maximumMultiplier, snapshot.multiplier + 1);
  elements.streakMeter.setAttribute(
    "aria-label",
    maximumReached
      ? `Maximum ${snapshot.multiplier} times score multiplier reached`
      : `Fast-reaction streak: ${snapshot.streakProgress} of ${snapshot.streakTarget} toward ${nextMultiplier} times score`
  );
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
  const musicStatus = musicEnabled ? "on" : "off";
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
  soundFxEnabled = storedSoundFx !== "off";
  sound.setEnabled(soundFxEnabled);
  const storedInteractiveMusic = readStoredPreference(INTERACTIVE_MUSIC_STORAGE_KEY);
  interactiveMusicEnabled = storedInteractiveMusic !== "off";
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
  return resolveMusicStage(snapshot);
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
  cancelDecoyCadence();
  window.clearTimeout(deadlineTimer);
  window.clearTimeout(runEndTimer);
  window.clearInterval(clockTimer);
  window.clearTimeout(completionTimer);
  window.clearTimeout(speedRatingTimer);
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
  speedRatingTimer = null;
  runStartFrame = null;
  roundActivationFrame = null;
  runEndCommit = null;
  deadlineCommit = null;
  activeRoundVisibleAt = null;
  activeRoundId = null;
  roundPresentationExpired = false;
  runDeadlineAt = null;
  sound.tileOff();
  hideSpeedRating();
}

function cancelDecoyCadence() {
  decoyCadenceId += 1;
  window.clearTimeout(decoySpawnTimer);
  window.clearTimeout(decoyExpiryTimer);
  window.cancelAnimationFrame(decoyActivationFrame);
  decoySpawnTimer = null;
  decoyExpiryTimer = null;
  decoyActivationFrame = null;
}

function setRunStartControlsDisabled(disabled) {
  elements.normalButton.disabled = disabled;
  elements.zenButton.disabled = disabled;
  elements.resultRestartButton.disabled = disabled;
  elements.gameRestartButton.disabled = disabled;
}

function abandonCurrentRun() {
  const runId = currentRunTicket?.runId;
  const shouldAbandon = !pendingResult?.submitting && !pendingResult?.submitted;
  currentRunTicket = null;
  if (shouldAbandon && typeof runId === "string") {
    void profileClient.abandonRun(runId).catch(() => {});
  }
}

async function startGame(mode) {
  clearTimers();
  void sound.startRun();
  music.setStage(MUSIC_STAGES.MENU);
  music.startRun();
  void music.unlock();
  void refreshTopScore(mode);
  abandonCurrentRun();
  const startRequestId = runStartRequestId + 1;
  runStartRequestId = startRequestId;
  let ticket = null;
  if (hasConfirmedProfile()) {
    setRunStartControlsDisabled(true);
    try {
      ticket = await profileClient.startRun(mode, APP_BUILD_ID);
    } catch {
      // The game remains available as an unranked local practice run when the
      // security service is offline. Such a run can never earn rank or coins.
    } finally {
      if (startRequestId === runStartRequestId) setRunStartControlsDisabled(false);
    }
  }
  if (startRequestId !== runStartRequestId) {
    if (ticket?.runId) void profileClient.abandonRun(ticket.runId).catch(() => {});
    return;
  }
  const currentSession = sessionId + 1;
  sessionId = currentSession;
  currentRunTicket = ticket;
  currentRunId = ticket?.runId ?? createRunId();
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
    scheduleDecoySpawn(currentSession);
  });
}

function showDodgeAward(result) {
  const dodgesAwarded = Number.isInteger(result?.dodgesAwarded) ? result.dodgesAwarded : 0;
  if (dodgesAwarded <= 0) return false;
  const pointsAwarded = result.pointsAwarded ?? result.dodgePointsAwarded ?? 0;
  const label = dodgesAwarded === 1 ? "Dodged that!" : `${dodgesAwarded} dodged!`;
  showFeedback(
    pointsAwarded > 0 ? `${label} +${pointsAwarded.toLocaleString()}` : label,
    false
  );
  return true;
}

function scheduleDecoyExpiry(currentSession, cadenceId = decoyCadenceId) {
  window.clearTimeout(decoyExpiryTimer);
  decoyExpiryTimer = null;
  if (
    currentSession !== sessionId ||
    cadenceId !== decoyCadenceId ||
    engine.state === GAME_STATES.GAME_OVER
  ) return;
  const expiryAt = engine.getSnapshot(now()).nextDecoyExpiryAt;
  if (expiryAt === null) return;

  const expiryTimerId = window.setTimeout(() => {
    if (
      currentSession !== sessionId ||
      cadenceId !== decoyCadenceId ||
      decoyExpiryTimer !== expiryTimerId ||
      document.hidden ||
      engine.state === GAME_STATES.GAME_OVER
    ) {
      return;
    }
    decoyExpiryTimer = null;
    const expiredAt = now();
    if (engine.mode === GAME_MODES.ZEN && reachedDeadline(expiredAt, runDeadlineAt)) return;
    const result = engine.expireDecoys(expiredAt);
    if (result.type === "decoys-dodged") {
      showDodgeAward(result);
      render();
    }
    scheduleDecoyExpiry(currentSession, cadenceId);
  }, remainingUntilDeadline(expiryAt, now()));
  decoyExpiryTimer = expiryTimerId;
}

function scheduleDecoySpawn(currentSession, cadenceId = decoyCadenceId) {
  window.clearTimeout(decoySpawnTimer);
  decoySpawnTimer = null;
  if (
    currentSession !== sessionId ||
    cadenceId !== decoyCadenceId ||
    engine.state === GAME_STATES.GAME_OVER
  ) return;
  const delayMs = engine.getNextDecoyDelayMs(now());
  if (delayMs === null) return;

  const spawnTimerId = window.setTimeout(() => {
    if (
      currentSession !== sessionId ||
      cadenceId !== decoyCadenceId ||
      decoySpawnTimer !== spawnTimerId ||
      document.hidden ||
      engine.state === GAME_STATES.GAME_OVER
    ) {
      return;
    }
    decoySpawnTimer = null;
    const activationFrameId = window.requestAnimationFrame((visibleAt) => {
      if (
        currentSession !== sessionId ||
        cadenceId !== decoyCadenceId ||
        decoyActivationFrame !== activationFrameId ||
        document.hidden ||
        engine.state === GAME_STATES.GAME_OVER
      ) {
        return;
      }
      decoyActivationFrame = null;
      if (engine.mode === GAME_MODES.ZEN && reachedDeadline(visibleAt, runDeadlineAt)) {
        finishZenRun(currentSession, visibleAt);
        return;
      }
      const result = engine.activateDecoy(visibleAt);
      showDodgeAward(result);
      render();
      scheduleDecoyExpiry(currentSession, cadenceId);
      scheduleDecoySpawn(currentSession, cadenceId);
    });
    decoyActivationFrame = activationFrameId;
  }, delayMs);
  decoySpawnTimer = spawnTimerId;
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
      if (engine.mode === GAME_MODES.ZEN && reachedDeadline(visibleAt, runDeadlineAt)) return;
      const result = engine.activateRound(visibleAt);
      showDodgeAward(result);
      if (result.type !== "round-active") {
        render();
        scheduleDecoyExpiry(currentSession);
        scheduleRound(currentSession, engine.config.decoys.retryDelayMs);
        return;
      }
      const roundId = nextRoundId + 1;
      nextRoundId = roundId;
      activeRoundId = roundId;
      activeRoundVisibleAt = visibleAt;
      roundPresentationExpired = false;
      updateMusicForSnapshot(result.snapshot);
      sound.tileOn();
      if (engine.mode !== GAME_MODES.ZEN) {
        const deadlineAt = reactionDeadline(
          visibleAt,
          result.snapshot.difficulty.responseWindowMs
        );
        scheduleDeadline(currentSession, roundId, deadlineAt);
      }
      render();
      if (engine.mode !== GAME_MODES.ZEN) {
        startResponseProgress(currentSession, result.snapshot);
      }
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
      if (engine.mode === GAME_MODES.ZEN && reachedDeadline(resolvedAt, runDeadlineAt)) return;
      const result = engine.expireRound(resolvedAt);
      showDodgeAward(result);
      if (result.type === "ignored" && result.reason === "not-expired") {
        roundPresentationExpired = false;
        render();
        scheduleDecoyExpiry(currentSession);
        scheduleDeadline(currentSession, roundId, deadlineAt);
        return;
      }
      lastDeadlineResolutionAt = resolvedAt;
      activeRoundVisibleAt = null;
      activeRoundId = null;
      roundPresentationExpired = false;
      window.clearTimeout(decoyExpiryTimer);
      decoyExpiryTimer = null;
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
  const result = engine.tap(cellIndex, inputAt, handledAt);
  if (result.type === "ignored") return;
  pets.handleGameplayTap(event.clientX);

  const pendingZenTarget =
    engine.mode === GAME_MODES.ZEN &&
    result.type === "miss" &&
    result.reason === "empty" &&
    (spawnTimer !== null || roundActivationFrame !== null);
  if (result.targetRetained === true || pendingZenTarget) {
    window.clearTimeout(decoyExpiryTimer);
    decoyExpiryTimer = null;
    handleMiss(result, sessionId, false);
    return;
  }

  sound.tileOff();
  window.clearTimeout(spawnTimer);
  spawnTimer = null;
  window.clearTimeout(deadlineTimer);
  deadlineTimer = null;
  window.clearTimeout(decoyExpiryTimer);
  decoyExpiryTimer = null;
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
    updateMusicForSnapshot(result.snapshot);
    music.playCorrectTap(result.snapshot.hits);
    showSpeedRating(result.speedRating);
    const dodgeCopy = result.dodgesAwarded > 0
      ? ` · ${result.dodgesAwarded} ${result.dodgesAwarded === 1 ? "dodge" : "dodges"}`
      : "";
    const multiplierCopy = result.multiplierRaised
      ? ` · ${result.multiplierAfter}× ready`
      : result.multiplierUsed > 1 ? ` · ${result.multiplierUsed}×` : "";
    showFeedback(
      `+${result.pointsAwarded.toLocaleString()} · ${result.displayedReactionMs} ms${multiplierCopy}${dodgeCopy}`,
      false
    );
    render();
    scheduleRound(sessionId);
    return;
  }

  handleMiss(result, sessionId);
}

function handleMiss(result, currentSession, scheduleNextTarget = true) {
  if (result.lifeLost) cancelDecoyCadence();
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
    if (scheduleNextTarget) scheduleRound(currentSession);
    if (result.lifeLost) scheduleDecoySpawn(currentSession);
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
  elements.dialogTitle.textContent = isZen ? "Three minutes complete" : "Game Over";
  elements.resultStats.hidden = false;
  elements.resultDurationLabel.textContent = isZen ? "Duration" : "Survived";
  elements.resultDurationValue.textContent = formatDuration(snapshot.elapsedMs, true);
  elements.resultFastestValue.textContent = formatReaction(snapshot.fastestReactionMs);
  elements.resultAverageValue.textContent = formatReaction(snapshot.averageReactionMs);
  elements.resultDodgesValue.textContent = snapshot.dodges.toLocaleString();
  elements.resultScoreValue.textContent = snapshot.points.toLocaleString();
  renderSpeedSummary(snapshot.speedRatings);
  elements.resultRestartButton.setAttribute(
    "aria-label",
    `Restart ${isZen ? "Zen" : "Arcade"} mode`
  );
  setDialogView("result");
  pendingResult = {
    runId: currentRunId,
    verificationAvailable:
      currentRunTicket?.runId === currentRunId &&
      currentRunTicket?.ruleset === "reaction-proof-v2",
    proof: {
      proofVersion: currentRunTicket?.proofVersion ?? 1,
      ruleset: currentRunTicket?.ruleset ?? "reaction-proof-v2",
      buildId: currentRunTicket?.buildId ?? APP_BUILD_ID,
      events: engine.getRunProofEvents()
    },
    mode: snapshot.mode,
    score: snapshot.points,
    hits: snapshot.hits,
    dodges: snapshot.dodges,
    fastestReactionMs:
      snapshot.fastestReactionMs === null ? null : Math.round(snapshot.fastestReactionMs),
    averageReactionMs:
      snapshot.averageReactionMs === null ? null : Math.round(snapshot.averageReactionMs),
    survivalMs: Math.round(snapshot.elapsedMs),
    speedRatings: normalizeSpeedRatings(snapshot.speedRatings),
    reactionBasePoints: snapshot.reactionBasePoints,
    multiplierBonusPoints: snapshot.multiplierBonusPoints,
    multiplierHitCounts: {
      one: snapshot.multiplierHitCounts[1] ?? 0,
      two: snapshot.multiplierHitCounts[2] ?? 0,
      three: snapshot.multiplierHitCounts[3] ?? 0,
      four: snapshot.multiplierHitCounts[4] ?? 0,
      five: snapshot.multiplierHitCounts[5] ?? 0
    },
    multiplierBasePoints: {
      one: snapshot.multiplierBasePoints[1] ?? 0,
      two: snapshot.multiplierBasePoints[2] ?? 0,
      three: snapshot.multiplierBasePoints[3] ?? 0,
      four: snapshot.multiplierBasePoints[4] ?? 0,
      five: snapshot.multiplierBasePoints[5] ?? 0
    },
    maxMultiplier: snapshot.maximumMultiplierUsed,
    submitted: false
  };
  selectLeaderboardMode(snapshot.mode);
  renderResultMessage(pendingResult);
  void refreshTopScore(snapshot.mode);
  closeLeaderboard();
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = false;
  elements.dialogUtility.hidden = false;
  renderResultSaveState();
  void submitPendingResult();
  completionTimer = window.setTimeout(() => {
    if (currentSession === sessionId && engine.isRunComplete()) {
      elements.gameUtility.hidden = true;
      setOverlayVisible(true);
      elements.dialog.scrollTop = 0;
      elements.resultRestartButton.focus({ preventScroll: true });
      if (!profileSession.authenticated) void renderGoogleButtons();
    }
  }, 400);
}

function resetResultUi() {
  pendingResult = null;
  setDialogView("menu");
  leaderboardReturnView = "menu";
  elements.gameUtility.hidden = true;
  elements.resultContent.hidden = true;
  elements.resultStats.hidden = true;
  elements.mainMenuContent.hidden = false;
  elements.dialogUtility.hidden = false;
  elements.resultSaveStatus.textContent = "";
  elements.resultScoreValue.textContent = "0";
  elements.resultGoogleSignin.hidden = true;
  renderSpeedSummary(null);
  closePetShop();
  closeSettings();
  closeAchievements();
  closeLeaderboard();
  closeProfile();
}

function renderResultMessage(result) {
  if (!result) return;
  const isZen = result.mode === GAME_MODES.ZEN;
  const modeName = isZen ? "Zen" : "Arcade";
  const completionReason = isZen ? "The three-minute timer ended." : "You are out of lives.";
  const topScore = topScores[result.mode];
  const bestScore = topScore === null
    ? "<strong>unavailable right now</strong>"
    : `<strong>${topScore.toLocaleString()}</strong>`;
  elements.dialogMessage.innerHTML = `${completionReason} You made <strong>${result.hits}</strong> correct taps. The best score for ${modeName} mode is ${bestScore}.`;
}

function showMainMenu() {
  runStartRequestId += 1;
  setRunStartControlsDisabled(false);
  abandonCurrentRun();
  clearTimers();
  music.setStage(MUSIC_STAGES.MENU);
  void music.unlock();
  sessionId += 1;
  engine.reset();
  currentRunId = null;
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

function setLeaderboardStatus(message, isError = false) {
  elements.leaderboardStatus.textContent = message;
  elements.leaderboardStatus.classList.toggle("is-error", isError);
}

function setAchievementsStatus(message, isError = false) {
  elements.achievementsStatus.textContent = message;
  elements.achievementsStatus.classList.toggle("is-error", isError);
}

function renderAchievements({ updateStatus = true } = {}) {
  const items = Array.isArray(achievementsPayload?.achievements)
    ? achievementsPayload.achievements
    : [];
  const achievementsById = new Map(
    items
      .filter((achievement) => achievement && typeof achievement.id === "string")
      .map((achievement) => [achievement.id, achievement])
  );
  const authenticated = achievementsPayload?.authenticated === true && profileSession.authenticated;
  const totalCount = Number.isInteger(achievementsPayload?.totalCount)
    ? achievementsPayload.totalCount
    : elements.achievementCards.length;
  const claimedCount = Number.isInteger(achievementsPayload?.claimedCount)
    ? Math.min(totalCount, Math.max(0, achievementsPayload.claimedCount))
    : 0;
  const claimableCount = items.filter((achievement) => achievement?.state === "claimable").length;

  elements.achievementsProgress.textContent = `${claimedCount} of ${totalCount} claimed`;
  elements.achievementsSummary.textContent = authenticated
    ? `${claimedCount} / ${totalCount} claimed`
    : profileSession.authenticated ? "Loading…" : "Sign in to claim";
  elements.achievementsProfileButton.hidden = authenticated;

  for (const card of elements.achievementCards) {
    const achievement = achievementsById.get(card.dataset.achievementId);
    const state = authenticated && ["locked", "claimable", "claimed"].includes(achievement?.state)
      ? achievement.state
      : "locked";
    const rewardCoins = Number.isInteger(achievement?.rewardCoins) && achievement.rewardCoins > 0
      ? achievement.rewardCoins
      : Number.parseInt(card.querySelector(".achievement-card__action strong")?.textContent, 10) || 0;
    const copy = card.querySelector(".achievement-card__copy");
    const action = card.querySelector(".achievement-card__action");
    const isClaiming = achievementClaimId === card.dataset.achievementId;
    const actionLabel = isClaiming
      ? "Claiming…"
      : state === "claimable" ? "Claim coins" : state === "claimed" ? "Claimed" : authenticated ? "In progress" : "Sign in";

    if (achievement && copy) {
      copy.querySelector("strong").textContent = achievement.title;
      copy.querySelector("small").textContent = achievement.description;
    }
    if (action) {
      action.querySelector("strong").textContent = `+${rewardCoins} ${rewardCoins === 1 ? "coin" : "coins"}`;
      action.querySelector("small").textContent = actionLabel;
    }
    card.classList.remove(
      "achievement-card--locked",
      "achievement-card--claimable",
      "achievement-card--claimed"
    );
    card.classList.add(`achievement-card--${state}`);
    card.disabled = state !== "claimable" || achievementClaimId !== null;
    card.toggleAttribute("aria-busy", isClaiming);
    card.setAttribute(
      "aria-label",
      `${achievement?.title ?? copy?.querySelector("strong")?.textContent ?? "Achievement"}. ${actionLabel}. Reward: ${rewardCoins} ${rewardCoins === 1 ? "coin" : "coins"}.`
    );
  }

  if (!updateStatus) return;
  if (!authenticated) {
    setAchievementsStatus("Sign in with Google in Profile to track and claim achievements.");
  } else if (claimableCount > 0) {
    setAchievementsStatus("Tap a green check to collect its coin reward.");
  } else {
    setAchievementsStatus("Keep playing to unlock more achievements.");
  }
}

async function loadAchievements({ showLoading = true } = {}) {
  const requestId = achievementsRequestId + 1;
  achievementsRequestId = requestId;
  if (showLoading && !elements.achievementsView.hidden) {
    setAchievementsStatus("Loading achievements…");
  }

  try {
    const body = await profileClient.getAchievements();
    if (requestId !== achievementsRequestId) return;
    if (!body || !Array.isArray(body.achievements)) {
      throw new Error("Achievements are temporarily unavailable.");
    }
    achievementsPayload = body;
    if (body.authenticated === true && Number.isInteger(body.coinBalance)) {
      profileSession = normalizeProfileSession({
        ...profileSession,
        authenticated: true,
        coinBalance: body.coinBalance
      });
      renderUtilityRank();
    } else if (body.authenticated === false && profileSession.authenticated) {
      profileSession = normalizeProfileSession({ authenticated: false });
      renderUtilityRank();
      renderProfile();
      renderResultSaveState();
    }
    renderAchievements();
  } catch (error) {
    if (requestId !== achievementsRequestId) return;
    renderAchievements({ updateStatus: false });
    setAchievementsStatus(error.message, true);
  }
}

async function claimAchievement(achievementId) {
  if (!profileSession.authenticated || achievementClaimId !== null) return;
  const achievement = achievementsPayload?.achievements?.find(
    (item) => item?.id === achievementId && item.state === "claimable"
  );
  if (!achievement) return;

  achievementsRequestId += 1;
  achievementClaimId = achievementId;
  renderAchievements({ updateStatus: false });
  setAchievementsStatus(`Claiming “${achievement.title}”…`);
  try {
    const body = await profileClient.claimAchievement(achievementId);
    if (!body || !Array.isArray(body.achievements)) {
      throw new Error("The reward response was invalid. Refresh and try again.");
    }
    achievementsPayload = body;
    if (Number.isInteger(body.coinBalance) && body.coinBalance >= 0) {
      profileSession = normalizeProfileSession({
        ...profileSession,
        coinBalance: body.coinBalance
      });
      renderUtilityRank();
    }
    const coinsEarned = Number.isInteger(body.coinsEarned) ? body.coinsEarned : 0;
    renderAchievements({ updateStatus: false });
    setAchievementsStatus(
      body.duplicate === true
        ? `“${achievement.title}” was already claimed.`
        : `${achievement.title} claimed — ${coinsEarned} ${coinsEarned === 1 ? "coin" : "coins"} added.`
    );
  } catch (error) {
    if (error instanceof ProfileApiError && error.status === 401) {
      profileSession = normalizeProfileSession({ authenticated: false });
      achievementsPayload = null;
      renderUtilityRank();
      renderProfile();
    }
    setAchievementsStatus(error.message, true);
  } finally {
    achievementClaimId = null;
    renderAchievements({ updateStatus: false });
  }
}

function openAchievements() {
  closeSettings();
  closeLeaderboard();
  closeProfile();
  dialogView = "achievements";
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.achievementsView.hidden = false;
  elements.dialogUtility.hidden = true;
  elements.dialogTitle.textContent = "Achievements";
  elements.dialogMessage.textContent = "Complete challenges, then tap a green check to collect the reward.";
  elements.dialog.scrollTop = 0;
  renderAchievements();
  void loadAchievements();
  elements.achievementsBackButton.focus({ preventScroll: true });
}

function closeAchievements() {
  achievementsRequestId += 1;
  elements.achievementsView.hidden = true;
}

function selectLeaderboardMode(mode) {
  leaderboardMode = mode;
  for (const tab of elements.leaderboardTabs) {
    const selected = tab.dataset.leaderboardMode === mode;
    tab.classList.toggle("is-active", selected);
    tab.setAttribute("aria-pressed", String(selected));
  }
  renderUtilityRank();
}

function showMenuView(focusTarget = null) {
  closePetShop();
  closeSettings();
  closeAchievements();
  closeLeaderboard();
  closeProfile();
  setDialogView("menu");
  leaderboardReturnView = "menu";
  elements.resultContent.hidden = true;
  elements.mainMenuContent.hidden = false;
  elements.dialogUtility.hidden = false;
  elements.dialogTitle.textContent = "Ready to react?";
  elements.dialogMessage.innerHTML = INTRO_COPY_HTML;
  elements.dialog.scrollTop = 0;
  focusTarget?.focus({ preventScroll: true });
}

function openPetShop() {
  closeSettings();
  closeAchievements();
  closeLeaderboard();
  closeProfile();
  setDialogView("pet-shop");
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.petShopView.hidden = false;
  elements.dialogUtility.hidden = true;
  elements.dialogTitle.textContent = "Pet Shop";
  elements.dialogMessage.textContent = "Tap a pet to preview its animation. Buy equips immediately; Change selects a pet you own.";
  elements.dialog.scrollTop = 0;
  elements.petShopToggle.setAttribute("aria-expanded", "true");
  setPetShopStatus(
    profileSession.authenticated
      ? "Tap an icon to play its animation."
      : "Sign in from Profile before buying pets."
  );
  renderPetShop();
  void music.unlock();
  elements.petShopBackButton.focus({ preventScroll: true });
}

function closePetShop() {
  elements.petShopView.hidden = true;
  elements.petShopToggle.setAttribute("aria-expanded", "false");
}

function openSettings() {
  closePetShop();
  closeAchievements();
  closeLeaderboard();
  closeProfile();
  setDialogView("settings");
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.settingsView.hidden = false;
  elements.dialogUtility.hidden = true;
  elements.dialogTitle.textContent = "Settings";
  elements.dialogMessage.textContent = "Choose how SpeedyTapper looks and sounds.";
  elements.dialog.scrollTop = 0;
  void music.unlock();
  elements.settingsBackButton.focus({ preventScroll: true });
}

function closeSettings() {
  elements.settingsView.hidden = true;
}

function openProfile(returnView = dialogView === "result" ? "result" : "menu") {
  closePetShop();
  closeSettings();
  closeAchievements();
  closeLeaderboard();
  profileReturnView = returnView;
  setDialogView("profile");
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.profileView.hidden = false;
  elements.dialogUtility.hidden = true;
  elements.dialogTitle.textContent = "Profile";
  elements.dialogMessage.textContent = profileSession.authenticated
    ? "Manage your public nickname and review your best leaderboard position."
    : "Use Google to keep one SpeedyTapper identity across devices.";
  elements.dialog.scrollTop = 0;
  renderProfile();
  if (profileSession.authenticated) {
    void loadProfileContext(profileMode);
  } else {
    void renderGoogleButtons();
  }
  elements.profileBackButton.focus({ preventScroll: true });
}

function closeProfile() {
  profileRequestId += 1;
  elements.profileView.hidden = true;
}

function returnFromProfile() {
  if (profileReturnView === "achievements") {
    openAchievements();
    return;
  }
  if (profileReturnView === "result" && pendingResult) {
    showResultView(elements.profileToggle);
    return;
  }
  showMenuView(elements.profileToggle);
}

function openLeaderboard(returnView = "menu") {
  closePetShop();
  closeSettings();
  closeAchievements();
  closeProfile();
  leaderboardReturnView = returnView;
  leaderboardReturnScrollTop = elements.dialog.scrollTop;
  setDialogView("leaderboard");
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = true;
  elements.leaderboardView.hidden = false;
  elements.dialogUtility.hidden = true;
  elements.dialogTitle.textContent = "Leaderboard";
  elements.dialogMessage.textContent = "Compare every ranked Arcade and Zen result.";
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
  closePetShop();
  closeAchievements();
  closeLeaderboard();
  closeProfile();
  setDialogView("result");
  elements.mainMenuContent.hidden = true;
  elements.resultContent.hidden = false;
  elements.dialogUtility.hidden = false;
  elements.dialogTitle.textContent =
    pendingResult.mode === GAME_MODES.ZEN ? "Three minutes complete" : "Game Over";
  renderResultMessage(pendingResult);
  renderResultSaveState();
  if (!profileSession.authenticated) void renderGoogleButtons();
  elements.dialog.scrollTop = leaderboardReturnScrollTop;
  focusTarget?.focus({ preventScroll: true });
}

function returnFromLeaderboard() {
  if (leaderboardReturnView === "result" && pendingResult) {
    showResultView(elements.leaderboardToggle);
    return;
  }
  showMenuView(elements.leaderboardToggle);
}

function renderLeaderboard(
  entries,
  mode,
  contextRank = null,
  listElement = elements.leaderboardList
) {
  listElement.replaceChildren();

  if (!Array.isArray(entries) || entries.length === 0) {
    const empty = document.createElement("li");
    empty.className = "leaderboard-empty";
    empty.textContent = "No scores yet — the first place is waiting.";
    listElement.append(empty);
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
    const isCurrentPlayer = entry.isCurrentPlayer === true || entry.isCurrent === true;
    const isContextResult = entry.isContextResult === true || entryRank === contextRank;
    row.classList.toggle("is-current", isContextResult);

    const rank = document.createElement("span");
    rank.className = "leaderboard-entry__rank";
    rank.textContent = String(entryRank);

    const avatar = document.createElement("span");
    avatar.className = "leaderboard-entry__avatar";
    avatar.setAttribute("aria-hidden", "true");
    const avatarPetId = isPetId(entry.petId) ? entry.petId : null;
    avatar.dataset.pet = avatarPetId ?? "none";
    if (avatarPetId !== null) {
      const sprite = document.createElement("span");
      sprite.className = "pet-sprite";
      avatar.append(sprite);
    }

    const player = document.createElement("div");
    player.className = "leaderboard-entry__player";
    const nameLine = document.createElement("div");
    nameLine.className = "leaderboard-entry__name-line";
    const name = document.createElement("div");
    name.className = "leaderboard-entry__name";
    name.textContent = entry.nickname ?? entry.name ?? "Player";
    nameLine.append(name);
    if (isCurrentPlayer) {
      const currentBadge = document.createElement("span");
      currentBadge.className = "leaderboard-entry__current";
      currentBadge.textContent = "You";
      nameLine.append(currentBadge);
    }
    if (entry.verification === "legacy") {
      const legacyBadge = document.createElement("span");
      legacyBadge.className = "leaderboard-entry__verification";
      legacyBadge.textContent = "Legacy";
      legacyBadge.title = "Created before gameplay proof verification";
      nameLine.append(legacyBadge);
    }
    const meta = document.createElement("div");
    meta.className = "leaderboard-entry__meta";
    const runMeta = document.createElement("span");
    runMeta.textContent = `${formatDuration(entry.survivalMs, true)} ${
      mode === GAME_MODES.NORMAL ? "survived" : "played"
    } · ${entry.hits.toLocaleString()} taps · ${(entry.dodges ?? 0).toLocaleString()} dodged`;
    const reactionMeta = document.createElement("span");
    reactionMeta.textContent = `Fastest ${formatReaction(entry.fastestReactionMs)} · Average ${formatReaction(entry.averageReactionMs)}`;
    const ratingMeta = document.createElement("span");
    const ratings = normalizeSpeedRatings(entry.speedRatings ?? entry);
    ratingMeta.textContent = SPEED_RATING_ORDER.map(
      (rating) => `${SPEED_RATING_LABELS[rating]} ${ratings[rating]}`
    ).join(" · ");
    meta.append(runMeta, reactionMeta, ratingMeta);
    player.append(nameLine, createLeaderboardSpeedBar(ratings), meta);

    const score = document.createElement("strong");
    score.className = "leaderboard-entry__score";
    score.textContent = entry.score.toLocaleString();

    row.append(rank, avatar, player, score);
    fragment.append(row);
    previousRank = entryRank;
  }
  listElement.append(fragment);
}

function renderLeaderboardPlayerPosition(
  totalEntries,
  playerRank = null,
  topPercent = null,
  label = "Your best position"
) {
  if (!profileSession.authenticated) {
    elements.leaderboardPlayerPosition.hidden = true;
    elements.leaderboardPlayerPosition.textContent = "";
    return;
  }

  const safeTotal = Number.isInteger(totalEntries) && totalEntries > 0 ? totalEntries : 0;
  const safeRank = Number.isInteger(playerRank) && playerRank > 0 ? playerRank : null;
  if (safeTotal === 0 || safeRank === null) {
    elements.leaderboardPlayerPosition.hidden = false;
    elements.leaderboardPlayerPosition.textContent = `${label}: Unranked`;
    return;
  }

  const calculatedPercent = Math.ceil((safeRank / safeTotal) * 100);
  const safePercent = Number.isFinite(topPercent)
    ? Math.max(1, Math.min(100, Math.ceil(topPercent)))
    : Math.max(1, Math.min(100, calculatedPercent));
  elements.leaderboardPlayerPosition.hidden = false;
  elements.leaderboardPlayerPosition.textContent =
    `${label}: #${safeRank.toLocaleString()} · Top ${safePercent}% of results`;
}

function setLeaderboardSummary(
  totalEntries,
  playerRank = null,
  topPercent = null,
  positionLabel = "Your best position"
) {
  const safeTotal = Number.isInteger(totalEntries) ? totalEntries : 0;
  renderLeaderboardPlayerPosition(safeTotal, playerRank, topPercent, positionLabel);
  if (safeTotal === 0) {
    setLeaderboardStatus("No ranked results yet.");
    return;
  }
  if (playerRank !== null) {
    setLeaderboardStatus(
      `${safeTotal.toLocaleString()} ranked ${safeTotal === 1 ? "result" : "results"} · Showing the top ${Math.min(5, safeTotal)} and nearby positions.`
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
  renderLeaderboard(
    pendingResult.leaderboardEntries,
    mode,
    pendingResult.leaderboardContextRank
  );
  setLeaderboardSummary(
    pendingResult.leaderboardTotalEntries,
    pendingResult.leaderboardContextRank,
    pendingResult.leaderboardContextTopPercent,
    pendingResult.hasExactLeaderboardContext ? "This result" : "Your best position"
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

function normalizeRank(value) {
  if (!value || typeof value !== "object") return null;
  const rank = Number.isInteger(value.rank) && value.rank > 0 ? value.rank : null;
  const totalEntries = Number.isInteger(value.totalEntries) && value.totalEntries >= 0
    ? value.totalEntries
    : 0;
  const topPercent = Number.isFinite(value.topPercent)
    ? Math.max(0, Math.min(100, value.topPercent))
    : rank !== null && totalEntries > 0
      ? (rank / totalEntries) * 100
      : null;
  return Object.freeze({
    ...value,
    rank,
    totalEntries,
    topPercent,
    entries: Array.isArray(value.entries) ? value.entries : []
  });
}

function normalizeProfile(value) {
  if (!value || typeof value !== "object") return null;
  const normalized = { ...value };
  const hasOwnedPetIds = Object.hasOwn(value, "ownedPetIds");
  const hasEquippedPetId = Object.hasOwn(value, "equippedPetId");
  if (hasOwnedPetIds) {
    normalized.ownedPetIds = normalizeOwnedPetIds(value.ownedPetIds);
  }
  if (hasEquippedPetId) {
    const equippedPetId = isPetId(value.equippedPetId) ? value.equippedPetId : null;
    normalized.equippedPetId = equippedPetId !== null
      && (!hasOwnedPetIds || normalized.ownedPetIds.includes(equippedPetId))
      ? equippedPetId
      : null;
  }
  return Object.freeze(normalized);
}

function normalizeProfileSession(body = {}) {
  const authenticated = body.authenticated === undefined
    ? profileSession.authenticated
    : body.authenticated === true;
  const rawRanks = authenticated
    ? body.ranks === undefined
      ? profileSession.ranks ?? {}
      : body.ranks ?? {}
    : {};
  const ranks = Object.fromEntries(
    Object.values(GAME_MODES).map((mode) => [mode, normalizeRank(rawRanks[mode])])
  );
  const leaderboard = body.leaderboard;
  if (leaderboard && Object.values(GAME_MODES).includes(leaderboard.mode)) {
    ranks[leaderboard.mode] = normalizeRank({
      ...(ranks[leaderboard.mode] ?? {}),
      rank: leaderboard.playerRank,
      totalEntries: leaderboard.totalEntries,
      topPercent: leaderboard.topPercent,
      entries: leaderboard.entries
    });
  }
  const receivedCoinBalance = [
    body.profile?.coins,
    body.profile?.coinBalance,
    body.coinBalance,
    body.coins
  ].find((value) => Number.isInteger(value) && value >= 0);
  const profile = body.profile === undefined
    ? authenticated ? profileSession.profile : null
    : normalizeProfile(body.profile);
  return Object.freeze({
    authenticated,
    googleClientId:
      typeof body.googleClientId === "string" && body.googleClientId.length > 0
        ? body.googleClientId
        : profileSession.googleClientId,
    profile,
    ranks: Object.freeze(ranks),
    coinBalance: authenticated
      ? receivedCoinBalance ?? profileSession.coinBalance ?? 0
      : 0
  });
}

function hasConfirmedProfile() {
  return profileSession.authenticated && profileSession.profile?.nicknameConfirmed === true;
}

function currentRank(mode = leaderboardMode) {
  return normalizeRank(profileSession.ranks?.[mode]);
}

function renderUtilityRank() {
  const rank = currentRank(leaderboardMode)?.rank ?? null;
  elements.leaderboardRank.hidden = rank === null;
  elements.leaderboardRank.textContent = rank === null ? "" : `#${rank.toLocaleString()}`;
  elements.coinCount.textContent = profileSession.coinBalance.toLocaleString();
  elements.coinBalance.setAttribute(
    "aria-label",
    `${profileSession.coinBalance.toLocaleString()} ${profileSession.coinBalance === 1 ? "coin" : "coins"}`
  );
  elements.profileToggle.classList.toggle("is-authenticated", profileSession.authenticated);
  pets.setProfileSession(profileSession);
  renderPetShop();
}

function currentPetShopState() {
  const equippedPetId = pets.getState().petId;
  const ownedPetIds = new Set(normalizeOwnedPetIds(profileSession.profile?.ownedPetIds));
  const hasPersistedPetState = profileSession.profile
    && (Object.hasOwn(profileSession.profile, "ownedPetIds")
      || Object.hasOwn(profileSession.profile, "equippedPetId"));
  if (!hasPersistedPetState && equippedPetId !== null) ownedPetIds.add(equippedPetId);
  return { equippedPetId, ownedPetIds };
}

function setPetShopStatus(message = "", isError = false) {
  elements.petShopStatus.textContent = message;
  elements.petShopStatus.classList.toggle("is-error", isError);
}

function renderPetShop() {
  const { equippedPetId, ownedPetIds } = currentPetShopState();
  const equippedPet = getPet(equippedPetId);
  elements.petShopCurrent.textContent = equippedPet?.name ?? "No pet";
  elements.petShopBalance.textContent = `${profileSession.coinBalance.toLocaleString()} ${profileSession.coinBalance === 1 ? "coin" : "coins"}`;

  for (const pet of PET_CATALOG) {
    const owned = ownedPetIds.has(pet.id);
    const equipped = equippedPetId === pet.id;
    const card = elements.petShopCards.find((item) => item.dataset.petCard === pet.id);
    const action = elements.petShopActions.find((item) => item.dataset.petAction === pet.id);
    const badge = elements.petShopEquippedBadges.find(
      (item) => item.dataset.petEquipped === pet.id
    );
    card?.classList.toggle("is-equipped", equipped);
    if (!action || !badge) continue;
    action.textContent = owned ? "Change" : "Buy";
    action.hidden = equipped;
    badge.hidden = !equipped;
    action.disabled = petShopPendingPetId !== null
      || !profileSession.authenticated
      || (!owned && profileSession.coinBalance < pet.priceCoins);
    action.setAttribute(
      "aria-label",
      owned
        ? `Change pet to ${pet.name}`
        : `Buy ${pet.name} for ${pet.priceCoins} coins and equip immediately`
    );
  }
}

function playPetPreview(button) {
  const scene = button.querySelector(".pet-preview-scene");
  if (!scene) return;
  const existingTimer = petPreviewTimers.get(scene);
  if (existingTimer !== undefined) window.clearTimeout(existingTimer);
  if (scene.dataset.pet === "pancake") {
    scene.dataset.facing = scene.dataset.facing === "left" ? "right" : "left";
  }
  scene.classList.remove("is-animating");
  void scene.offsetWidth;
  scene.classList.add("is-animating");
  const timerId = window.setTimeout(() => {
    scene.classList.remove("is-animating");
    petPreviewTimers.delete(scene);
  }, 1_500);
  petPreviewTimers.set(scene, timerId);
}

function invalidatePetShopMutation() {
  petShopMutationId += 1;
  petShopPendingPetId = null;
}

async function selectPet(petId) {
  const pet = getPet(petId);
  if (!pet || petShopPendingPetId !== null) return;
  if (!profileSession.authenticated) {
    setPetShopStatus("Sign in from Profile before buying or changing pets.", true);
    return;
  }
  const { ownedPetIds } = currentPetShopState();
  if (!ownedPetIds.has(petId) && profileSession.coinBalance < pet.priceCoins) {
    setPetShopStatus(`You need ${pet.priceCoins.toLocaleString()} coins to buy ${pet.name}.`, true);
    return;
  }

  const mutationId = petShopMutationId + 1;
  petShopMutationId = mutationId;
  petShopPendingPetId = petId;
  setPetShopStatus(ownedPetIds.has(petId) ? `Changing to ${pet.name}…` : `Buying ${pet.name}…`);
  renderPetShop();
  try {
    const body = await profileClient.selectPet(petId);
    if (mutationId !== petShopMutationId) return;
    profileSession = normalizeProfileSession({
      ...body,
      authenticated: true,
      googleClientId: profileSession.googleClientId
    });
    renderUtilityRank();
    renderProfile();
    if (body.pet?.purchased === true) {
      achievementsPayload = null;
      void loadAchievements({ showLoading: false });
    }
    setPetShopStatus(
      body.pet?.purchased === true
        ? `${pet.name} is yours and equipped.`
        : `${pet.name} is equipped.`
    );
    elements.petShopPreviews
      .find((button) => button.dataset.petPreview === petId)
      ?.focus({ preventScroll: true });
  } catch (error) {
    if (mutationId !== petShopMutationId) return;
    setPetShopStatus(
      error instanceof ProfileApiError ? error.message : "The pet could not be selected right now.",
      true
    );
  } finally {
    if (mutationId === petShopMutationId) {
      petShopPendingPetId = null;
      renderPetShop();
    }
  }
}

async function refreshProfileSession() {
  invalidatePetShopMutation();
  try {
    profileSession = normalizeProfileSession(await profileClient.getSession());
  } catch {
    profileSession = normalizeProfileSession({ authenticated: false });
  }
  renderUtilityRank();
  renderProfile();
  renderResultSaveState();
  renderAchievements();
  void loadAchievements({ showLoading: false });
  if (hasConfirmedProfile() && pendingResult && !pendingResult.submitted) {
    void submitPendingResult();
  }
  return profileSession;
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
    const body = await profileClient.getLeaderboard(mode);
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
  const cachedRank = currentRank(mode);
  renderLeaderboardPlayerPosition(
    cachedRank?.totalEntries ?? 0,
    cachedRank?.rank ?? null,
    cachedRank?.topPercent ?? null
  );
  elements.leaderboardList.replaceChildren();

  try {
    const body = await profileClient.getLeaderboard(mode);
    updateTopScore(mode, body.entries, topScoreRevision);
    if (requestId !== leaderboardRequestId) return;
    const playerRank = body.playerRank ?? body.rank ?? null;
    renderLeaderboard(body.entries, mode, body.contextRank ?? playerRank);
    setLeaderboardSummary(body.totalEntries, playerRank, body.topPercent);
    if (profileSession.authenticated) {
      profileSession = normalizeProfileSession({
        ...profileSession,
        ranks: {
          ...profileSession.ranks,
          [mode]: {
            ...(profileSession.ranks?.[mode] ?? {}),
            rank: playerRank,
            totalEntries: body.totalEntries,
            topPercent: body.topPercent,
            entries: body.playerEntries ?? body.entries
          }
        }
      });
      renderUtilityRank();
    }
  } catch (error) {
    if (requestId !== leaderboardRequestId) return;
    renderLeaderboard([], mode);
    elements.leaderboardPlayerPosition.hidden = true;
    elements.leaderboardPlayerPosition.textContent = "";
    setLeaderboardStatus(error.message, true);
  }
}

function setResultSaveStatus(message, isError = false) {
  elements.resultSaveStatus.textContent = message;
  elements.resultSaveStatus.classList.toggle("is-error", isError);
}

function renderResultSaveState() {
  if (!pendingResult) return;
  if (pendingResult.submitting) {
    elements.resultGoogleSignin.hidden = true;
    setResultSaveStatus("Saving this result to the leaderboard…");
    return;
  }
  if (pendingResult.submitted) {
    elements.resultGoogleSignin.hidden = true;
    if (pendingResult.verificationStatus === "review") {
      setResultSaveStatus(
        "Result saved for security review. It has not been ranked and no coins were awarded yet."
      );
      return;
    }
    if (pendingResult.verificationStatus === "quarantined") {
      setResultSaveStatus(
        "Result was not ranked and no coins were awarded because its gameplay trace matched an earlier submission."
      );
      return;
    }
    if (pendingResult.legacyContextUnavailable) {
      setResultSaveStatus(
        "Result was already processed. Its exact historical leaderboard position is unavailable."
      );
      return;
    }
    if (pendingResult.improved === false) {
      const rankCopy = pendingResult.rank === null
        ? ""
        : ` This result is #${pendingResult.rank.toLocaleString()}.`;
      setResultSaveStatus(`Result saved.${rankCopy} Your personal best is unchanged.`);
      return;
    }
    const rankCopy = pendingResult.rank === null
      ? ""
      : ` This result is #${pendingResult.rank.toLocaleString()}.`;
    setResultSaveStatus(`Result saved as a new personal best.${rankCopy}`);
    return;
  }
  if (!pendingResult.verificationAvailable) {
    elements.resultGoogleSignin.hidden = true;
    setResultSaveStatus(
      hasConfirmedProfile()
        ? "Local practice result only. Ranked run verification was unavailable when this game started."
        : "Local practice result only. Sign in and choose a nickname before starting to earn rank and coins."
    );
    return;
  }
  if (!profileSession.authenticated) {
    elements.resultGoogleSignin.hidden = false;
    setResultSaveStatus("Sign in with Google to save this result and your leaderboard history.");
    return;
  }
  if (!hasConfirmedProfile()) {
    elements.resultGoogleSignin.hidden = true;
    setResultSaveStatus("Choose a public nickname in Profile before this run can be ranked.");
    return;
  }
  elements.resultGoogleSignin.hidden = true;
  setResultSaveStatus("Ready to save this result.");
}

async function submitPendingResult() {
  if (
    !pendingResult ||
    pendingResult.submitted ||
    pendingResult.submitting ||
    !pendingResult.verificationAvailable ||
    !hasConfirmedProfile()
  ) {
    renderResultSaveState();
    return;
  }
  const submittedResult = pendingResult;
  submittedResult.submitting = true;
  renderResultSaveState();

  try {
    const body = await profileClient.submitResult({
      runId: submittedResult.runId,
      mode: submittedResult.mode,
      ...submittedResult.proof
    });
    submittedResult.submitting = false;
    submittedResult.submitted = true;
    submittedResult.duplicate = body.duplicate === true;
    submittedResult.improved = body.improved === true;
    submittedResult.verificationStatus = body.verificationStatus ?? "verified";
    submittedResult.hasExactLeaderboardContext = body.submittedEntryId === submittedResult.runId;
    submittedResult.rank = submittedResult.hasExactLeaderboardContext
      ? body.submittedRank ?? body.contextRank ?? body.rank ?? null
      : null;
    submittedResult.topPercent = submittedResult.hasExactLeaderboardContext
      ? body.contextTopPercent ?? body.topPercent ?? null
      : null;
    submittedResult.legacyContextUnavailable =
      submittedResult.duplicate && !submittedResult.hasExactLeaderboardContext;
    submittedResult.leaderboardContextRank = body.contextRank ?? body.playerRank ?? null;
    submittedResult.leaderboardContextTopPercent =
      body.contextTopPercent ?? body.topPercent ?? null;
    submittedResult.leaderboardEntries = body.entries;
    submittedResult.leaderboardTotalEntries = body.totalEntries;
    submittedResult.coinsEarned = Number.isInteger(body.coinsEarned) ? body.coinsEarned : 0;
    if (Number.isInteger(body.coinBalance) && body.coinBalance >= 0) {
      profileSession = normalizeProfileSession({
        ...profileSession,
        coinBalance: body.coinBalance
      });
      renderUtilityRank();
    }
    topScoreRevisions[body.mode] += 1;
    updateTopScore(body.mode, body.entries);
    if (pendingResult === submittedResult) {
      renderResultSaveState();
      await refreshProfileSession();
    }
  } catch (error) {
    submittedResult.submitting = false;
    if (pendingResult === submittedResult) {
      if (error instanceof ProfileApiError && error.status === 401) {
        profileSession = normalizeProfileSession({ authenticated: false });
        achievementsPayload = null;
        renderUtilityRank();
        renderResultSaveState();
        renderAchievements();
        void loadAchievements({ showLoading: false });
        void renderGoogleButtons();
      } else {
        setResultSaveStatus(error.message, true);
      }
    }
  }
}

function loadGoogleIdentity() {
  if (globalThis.google?.accounts?.id) return Promise.resolve(globalThis.google);
  if (googleIdentityPromise) return googleIdentityPromise;
  googleIdentityPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-google-identity="true"]');
    existing?.remove();
    const script = document.createElement("script");
    script.src = "https://accounts.google.com/gsi/client";
    script.async = true;
    script.defer = true;
    script.dataset.googleIdentity = "true";
    script.addEventListener("load", () => {
      if (!globalThis.google?.accounts?.id) {
        script.remove();
        reject(new Error("Google sign-in did not initialize."));
        return;
      }
      resolve(globalThis.google);
    }, { once: true });
    script.addEventListener("error", () => reject(new Error("Google sign-in could not be loaded.")), {
      once: true
    });
    document.head.append(script);
  }).catch((error) => {
    document.querySelector('script[data-google-identity="true"]')?.remove();
    googleIdentityPromise = null;
    throw error;
  });
  return googleIdentityPromise;
}

async function renderGoogleButtons() {
  if (profileSession.authenticated) return;
  const clientId = profileSession.googleClientId;
  if (!clientId) {
    elements.profileAuthStatus.textContent = "Google sign-in is not configured yet.";
    return;
  }

  try {
    const google = await loadGoogleIdentity();
    if (googleIdentityClientId !== clientId) {
      google.accounts.id.initialize({
        client_id: clientId,
        callback: handleGoogleCredential,
        auto_select: false,
        cancel_on_tap_outside: true
      });
      googleIdentityClientId = clientId;
    }
    for (const container of [elements.profileGoogleSignin, elements.resultGoogleSignin]) {
      if (container.hidden || container.offsetParent === null) continue;
      container.replaceChildren();
      google.accounts.id.renderButton(container, {
        type: "standard",
        theme: "outline",
        size: "large",
        shape: "pill",
        text: "continue_with",
        width: Math.min(320, Math.max(220, container.clientWidth || 260))
      });
    }
  } catch (error) {
    elements.profileAuthStatus.textContent = error.message;
    if (pendingResult && !elements.resultGoogleSignin.hidden) {
      setResultSaveStatus(error.message, true);
    }
  }
}

async function handleGoogleCredential(response) {
  const credential = response?.credential;
  if (!credential) return;
  elements.profileAuthStatus.textContent = "Signing in…";
  try {
    profileSession = normalizeProfileSession(
      await profileClient.loginWithGoogleCredential(credential)
    );
    renderUtilityRank();
    renderProfile();
    renderResultSaveState();
    renderAchievements();
    void loadAchievements({ showLoading: false });
    if (!hasConfirmedProfile()) {
      const returnView = dialogView === "profile"
        ? profileReturnView
        : dialogView === "result" ? "result" : "menu";
      openProfile(returnView);
      elements.profileStatus.textContent = "Choose a public nickname before saving scores.";
      elements.profileNickname.focus({ preventScroll: true });
      return;
    }
    if (dialogView === "profile") await loadProfileContext(profileMode);
    await submitPendingResult();
  } catch (error) {
    elements.profileAuthStatus.textContent = error.message;
    if (pendingResult) setResultSaveStatus(error.message, true);
  }
}

async function loadProfileContext(mode) {
  if (!profileSession.authenticated) return;
  const requestId = profileRequestId + 1;
  profileRequestId = requestId;
  elements.profileStatus.classList.remove("is-error");
  elements.profileStatus.textContent = "Loading leaderboard position…";
  try {
    const body = await profileClient.getProfile(mode);
    if (requestId !== profileRequestId) return;
    profileSession = normalizeProfileSession({
      ...profileSession,
      ...body,
      authenticated: true,
      googleClientId: profileSession.googleClientId
    });
    renderUtilityRank();
    renderProfile();
    elements.profileStatus.textContent = "";
  } catch (error) {
    if (requestId !== profileRequestId) return;
    if (error instanceof ProfileApiError && error.status === 401) {
      await refreshProfileSession();
      void renderGoogleButtons();
      return;
    }
    elements.profileStatus.classList.add("is-error");
    elements.profileStatus.textContent = error.message;
  }
}

function selectProfileMode(mode) {
  profileMode = mode;
  for (const tab of elements.profileModeTabs) {
    const selected = tab.dataset.profileMode === mode;
    tab.classList.toggle("is-active", selected);
    tab.setAttribute("aria-pressed", String(selected));
  }
  renderProfileRank();
}

function renderProfileRank() {
  if (!profileSession.authenticated) return;
  const rank = currentRank(profileMode);
  elements.profileRankCard.replaceChildren();
  const metrics = [
    ["Position", rank?.rank === null || !rank ? "Unranked" : `#${rank.rank.toLocaleString()}`],
    ["Top results", rank?.topPercent === null || !rank ? "—" : `${Math.max(0.1, rank.topPercent).toFixed(1)}%`]
  ];
  for (const [labelText, valueText] of metrics) {
    const metric = document.createElement("div");
    metric.className = "profile-rank-card__metric";
    const label = document.createElement("span");
    label.textContent = labelText;
    const value = document.createElement("strong");
    value.textContent = valueText;
    metric.append(label, value);
    elements.profileRankCard.append(metric);
  }
  const neighboringEntries = rank?.rank === null || !rank
    ? []
    : (rank.entries ?? []).filter(
        (entry) => Number.isInteger(entry.rank) && Math.abs(entry.rank - rank.rank) <= 2
      );
  renderLeaderboard(
    neighboringEntries,
    profileMode,
    rank?.rank ?? null,
    elements.profileNeighbors
  );
}

function renderProfile() {
  if (!elements.profileView) return;
  pets.setProfileSession(profileSession);
  elements.profileSignedIn.hidden = !profileSession.authenticated;
  elements.profileSignedOut.hidden = profileSession.authenticated;
  if (!profileSession.authenticated) {
    elements.profileAuthStatus.textContent = profileSession.googleClientId
      ? ""
      : "Google sign-in is not configured yet.";
    return;
  }
  elements.profileNickname.value = profileSession.profile?.nickname ?? "";
  selectProfileMode(profileMode);
}

async function saveProfileNickname(event) {
  event.preventDefault();
  const nickname = elements.profileNickname.value.trim();
  elements.profileSave.disabled = true;
  elements.profileStatus.classList.remove("is-error");
  elements.profileStatus.textContent = "Saving nickname…";
  try {
    const body = await profileClient.updateNickname(nickname);
    profileSession = normalizeProfileSession({
      ...profileSession,
      ...body,
      authenticated: true,
      googleClientId: profileSession.googleClientId
    });
    elements.profileStatus.textContent = "Nickname saved.";
    renderProfile();
    if (pendingResult && !pendingResult.submitted) await submitPendingResult();
    await loadProfileContext(profileMode);
  } catch (error) {
    elements.profileStatus.textContent = error.message;
    elements.profileStatus.classList.add("is-error");
  } finally {
    elements.profileSave.disabled = false;
  }
}

async function logoutProfile() {
  invalidatePetShopMutation();
  elements.profileLogout.disabled = true;
  elements.profileStatus.classList.remove("is-error");
  elements.profileStatus.textContent = "Logging out…";
  try {
    const body = await profileClient.logout();
    globalThis.google?.accounts?.id?.disableAutoSelect?.();
    profileSession = normalizeProfileSession(body);
    achievementsPayload = null;
    renderUtilityRank();
    renderProfile();
    renderResultSaveState();
    renderAchievements();
    void loadAchievements({ showLoading: false });
    void renderGoogleButtons();
  } catch (error) {
    elements.profileStatus.textContent = error.message;
    elements.profileStatus.classList.add("is-error");
  } finally {
    elements.profileLogout.disabled = false;
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
    elements.modeLabel.textContent = "Time";
    elements.modeName.textContent = formatDuration(
      snapshot.remainingMs ?? engine.config.zenDurationMs
    );
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
    elements.statusLabel.textContent = "Lives";
    elements.statusValue.classList.add("lives");
    elements.statusValue.textContent = "∞";
    elements.statusValue.setAttribute("aria-label", "Unlimited lives");
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
  renderStreak(snapshot);
}

function render() {
  const snapshot = engine.getSnapshot(now());
  ensureBoard(snapshot.difficulty.gridDimension);
  renderHud(snapshot);

  const tiles = [...elements.board.children];
  for (const [index, tile] of tiles.entries()) {
    const cell = roundPresentationExpired &&
      snapshot.state === GAME_STATES.ACTIVE &&
      index === snapshot.targetIndex
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
  runStartRequestId += 1;
  abandonCurrentRun();
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
elements.mainMenuButton.addEventListener("click", showMainMenu);
elements.petShopToggle.addEventListener("click", openPetShop);
elements.petShopBackButton.addEventListener("click", () => showMenuView(elements.petShopToggle));
for (const button of elements.petShopPreviews) {
  button.addEventListener("click", () => playPetPreview(button));
}
for (const button of elements.petShopActions) {
  button.addEventListener("click", () => void selectPet(button.dataset.petAction));
}
elements.achievementsToggle.addEventListener("click", openAchievements);
elements.achievementsBackButton.addEventListener("click", () => showMenuView(elements.achievementsToggle));
elements.achievementsProfileButton.addEventListener("click", () => openProfile("achievements"));
for (const card of elements.achievementCards) {
  card.addEventListener("click", () => void claimAchievement(card.dataset.achievementId));
}
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
elements.leaderboardToggle.addEventListener("click", () => {
  if (dialogView === "result" && pendingResult) {
    openResultLeaderboard();
    return;
  }
  void loadLeaderboard(leaderboardMode, "menu");
});
elements.leaderboardBackButton.addEventListener("click", returnFromLeaderboard);
elements.leaderboardMenuButton.addEventListener("click", showMainMenu);
for (const tab of elements.leaderboardTabs) {
  tab.addEventListener("click", () => showLeaderboardMode(tab.dataset.leaderboardMode));
}
elements.profileToggle.addEventListener("click", () => openProfile());
elements.profileBackButton.addEventListener("click", returnFromProfile);
elements.profileMenuButton.addEventListener("click", showMainMenu);
elements.profileForm.addEventListener("submit", saveProfileNickname);
elements.profileLogout.addEventListener("click", logoutProfile);
for (const tab of elements.profileModeTabs) {
  tab.addEventListener("click", () => {
    selectProfileMode(tab.dataset.profileMode);
    void loadProfileContext(tab.dataset.profileMode);
  });
}
document.addEventListener("visibilitychange", pauseForVisibilityChange);
document.addEventListener("pointerdown", (event) => {
  if (musicEnabled) void music.unlock();
  const viewport = window.visualViewport;
  pets.handleNonGameTap(event.clientX, {
    left: viewport?.offsetLeft ?? 0,
    width: viewport?.width ?? document.documentElement.clientWidth
  });
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
void refreshProfileSession().then(() => {
  if (!profileSession.authenticated && dialogView === "profile") void renderGoogleButtons();
});
for (const mode of Object.values(GAME_MODES)) {
  void refreshTopScore(mode);
}
