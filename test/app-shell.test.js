import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const [indexHtml, mainSource, configSource, engineSource, musicSource, soundSource, workerSource, stylesSource, vercelIgnoreSource] = await Promise.all([
  readFile(new URL("../index.html", import.meta.url), "utf8"),
  readFile(new URL("../src/main.js", import.meta.url), "utf8"),
  readFile(new URL("../src/config.js", import.meta.url), "utf8"),
  readFile(new URL("../src/game-engine.js", import.meta.url), "utf8"),
  readFile(new URL("../src/music-controller.js", import.meta.url), "utf8"),
  readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8"),
  readFile(new URL("../sw.js", import.meta.url), "utf8"),
  readFile(new URL("../styles.css", import.meta.url), "utf8"),
  readFile(new URL("../.vercelignore", import.meta.url), "utf8")
]);

test("the complete browser module graph uses one release version", () => {
  const buildId = workerSource.match(/const BUILD_ID = "([^"]+)";/)?.[1];
  assert.ok(buildId, "The service worker must declare a build ID.");
  assert.equal(buildId, "20260713-8");

  assert.match(indexHtml, new RegExp(`styles\\.css\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`manifest\\.webmanifest\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`main\\.js\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`sw\\.js\\?v=\\$\\{buildId\\}`));
  assert.match(indexHtml, new RegExp(`const buildId = "${buildId}";`));
  assert.match(mainSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`game-engine\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`input-timing\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`music-controller\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`sound-controller\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`profile-client\\.js\\?v=${buildId}`));
  assert.match(engineSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(workerSource, new RegExp(`input-timing\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`sound-controller\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`music-controller\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`profile-client\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, /\.\/assets\/disco-concrete\.png/);
  assert.match(workerSource, /\.\/assets\/disco-tile-overlay\.png/);

  const appShell = workerSource.match(/const APP_SHELL = \[([\s\S]*?)\];/)?.[1] ?? "";
  assert.doesNotMatch(appShell, /assets\/audio|\.(?:mp3|m4a|aac|wav|ogg)/i);
  assert.doesNotMatch(indexHtml, /<audio\b|rel="preload"[^>]+as="audio"/i);
  assert.match(vercelIgnoreSource, /assets\/audio\/interactive-music-masters/);
  assert.match(
    workerSource,
    /MUSIC_ASSET_PATHS\.has\(requestUrl\.pathname\)\)[\s\S]*cacheFirst\(event\.request\)/
  );
  assert.match(
    workerSource,
    /pathname\.startsWith\("\/assets\/audio\/"\)[\s\S]*fetch\(event\.request, \{ cache: "no-store" \}\)/
  );
  assert.match(workerSource, /fetch\(request, \{ cache: "no-store" \}\)/);
});

test("Sound FX defaults on, preserves opt-out, and uses standards-based Web Audio", () => {
  const settingsPanel = indexHtml.match(
    /<fieldset class="settings-panel" id="settings-panel">[\s\S]*?<\/fieldset>/
  )?.[0] ?? "";
  const soundSetting = settingsPanel.match(
    /<label class="setting-row" for="sound-fx-toggle">[\s\S]*?<\/label>/
  )?.[0] ?? "";
  const soundToggle = soundSetting.match(/<input[^>]+id="sound-fx-toggle"[^>]*>/)?.[0] ?? "";

  assert.match(soundSetting, /Sound FX/);
  assert.match(soundSetting, />\s*Beta\s*</i);
  assert.match(soundToggle, /role="switch"/);
  assert.match(soundToggle, /\bchecked\b/);
  assert.match(indexHtml, /id="settings-current">Classic · FX on · Music on</);
  assert.match(mainSource, /speedytapper\.soundFx\.v1/);
  assert.match(mainSource, /let soundFxEnabled = true;/);
  assert.match(mainSource, /soundFxEnabled = storedSoundFx !== "off";/);
  assert.match(mainSource, /soundFxToggle/);
  assert.match(mainSource, /sound\.setEnabled\(soundFxEnabled\)/);
  assert.match(
    mainSource,
    /function startGame\(mode\)\s*\{\s*clearTimers\(\);\s*(?:void\s+)?sound\.startRun\(\);/,
    "Run cleanup must finish before the gesture-based audio unlock starts."
  );
  assert.match(
    mainSource,
    /soundFxToggle\.addEventListener\("change",[\s\S]*?applySoundFx[\s\S]*?sound\.unlock\(\)/,
    "Opting into sound must also resume Web Audio from that trusted gesture."
  );

  assert.match(soundSource, /globalThis\.AudioContext/);
  assert.match(soundSource, /latencyHint:\s*"interactive"/);
  assert.match(soundSource, /decodeAudioData\s*\(/);
  assert.match(soundSource, /createBufferSource\s*\(/);
  assert.match(soundSource, /\.resume\s*\(/);
  assert.match(soundSource, /\.suspend\s*\(/);
  assert.match(soundSource, /\.close\s*\(/);
  assert.match(soundSource, /cache:\s*"no-store"/);
  assert.match(soundSource, /let enabled = false;/);
  assert.match(soundSource, /resumeFromGesture\(\)/);
  assert.match(soundSource, /startRun\(\)/);
  assert.match(soundSource, /setTargetAtTime\s*\(/);
  assert.doesNotMatch(soundSource, /cancelAndHoldAtTime\s*\(/);
  assert.match(mainSource, /sound\.suspend\(\)/);
  assert.match(mainSource, /addEventListener\("pagehide"/);
  assert.doesNotMatch(soundSource, /webkitAudioContext|HTMLAudioElement|AudioClass|globalThis\.Audio(?!Context)/);
  assert.doesNotMatch(`${mainSource}\n${soundSource}`, /new Audio\s*\(|document\.createElement\(["']audio["']\)/);
});

test("the streamlined dialog contains settings, leaderboard, and reaction statistics", () => {
  assert.doesNotMatch(indexHtml, /Mechanics prototype/i);
  assert.match(indexHtml, /<html lang="en" data-theme="classic" data-glyphs="on">/);
  assert.match(indexHtml, /<div id="app" inert>/);
  assert.doesNotMatch(indexHtml, /id="phase"|id="hint"|id="rules"/);
  assert.match(indexHtml, /id="profile-view" hidden/);
  assert.match(indexHtml, /id="profile-toggle"/);
  assert.match(indexHtml, /id="result-stats"/);
  assert.match(indexHtml, /id="result-score-value"/);
  assert.match(indexHtml, /id="result-content"/);
  assert.match(indexHtml, /id="result-fastest-value"/);
  assert.match(indexHtml, /id="result-average-value"/);
  assert.match(indexHtml, /id="main-menu-content"/);
  assert.match(indexHtml, /id="main-menu-button"/);
  assert.doesNotMatch(indexHtml, /id="response-rails"|response-rail__/);
  assert.match(indexHtml, /id="response-progress"[^>]+hidden/);
  assert.match(
    indexHtml,
    /<div class="color-hero" id="color-hero">[\s\S]*id="response-progress"[\s\S]*?<\/div>\s*<div class="hud-side">/
  );
  assert.match(indexHtml, /id="result-save-panel"/);
  assert.match(indexHtml, /id="speed-summary-bar"/);
  assert.match(indexHtml, /data-speed-segment="godlike"/);
  assert.doesNotMatch(indexHtml, /id="themes-toggle"|id="themes-panel"/);
  assert.match(indexHtml, /id="settings-toggle"[^>]+aria-controls="settings-view"/s);
  assert.match(indexHtml, /id="settings-view" hidden/);
  assert.match(indexHtml, /id="settings-back-button"[^>]*>← Back</);
  assert.match(indexHtml, /id="leaderboard-view" hidden/);
  assert.match(indexHtml, /id="leaderboard-back-button"[\s\S]*aria-label="Go back"/);
  assert.match(indexHtml, /id="leaderboard-menu-button"[\s\S]*aria-label="Return to main menu"/);

  const settingsPanel = indexHtml.match(
    /<fieldset class="settings-panel" id="settings-panel">[\s\S]*?<\/fieldset>/
  )?.[0];
  assert.ok(settingsPanel, "Settings panel must be present.");
  assert.match(settingsPanel, /name="theme" value="classic" checked/);
  assert.match(settingsPanel, /name="theme" value="disco"/);
  assert.match(settingsPanel, /id="color-blind-toggle"[^>]+role="switch" checked/);
  assert.match(indexHtml, /id="leaderboard-toggle"/);
  assert.match(
    indexHtml,
    /id="leaderboard-toggle"[\s\S]*aria-label="Open leaderboard"[\s\S]*title="Leaderboard"/
  );
  assert.doesNotMatch(indexHtml, /<span>Leaderboard<\/span>/);
  assert.match(
    stylesSource,
    /\.leaderboard-shortcut\s*\{[^}]+width:\s*44px;[^}]+min-width:\s*44px;[^}]+height:\s*44px;[^}]+padding:\s*0;/s
  );
  assert.ok(
    indexHtml.indexOf('id="leaderboard-toggle"') < indexHtml.indexOf('id="settings-toggle"'),
    "The leaderboard shortcut must sit in the utility header above the menu controls."
  );
  assert.match(indexHtml, /id="leaderboard-rank" hidden/);
  assert.match(indexHtml, /id="coin-balance"[^>]+aria-label="0 coins"/);
  assert.ok(
    indexHtml.indexOf('id="coin-balance"') < indexHtml.indexOf('id="leaderboard-toggle"'),
    "The coin balance must sit immediately left of the leaderboard shortcut."
  );

  assert.match(mainSource, /speedytapper\.theme\.v1/);
  assert.match(mainSource, /speedytapper\.colorBlindMode\.v1/);
  assert.match(mainSource, /settingsToggle/);
  assert.match(mainSource, /settingsPanel/);
  assert.match(mainSource, /settingsCurrent/);
  assert.match(mainSource, /function showMenuView\(/);
  assert.match(mainSource, /function setOverlayVisible\(visible\)[\s\S]*elements\.app\.inert = visible/);
  assert.match(mainSource, /function startGame\(mode\)[\s\S]*setOverlayVisible\(false\)/);
  assert.match(mainSource, /function finishGame\(snapshot, currentSession\)[\s\S]*setOverlayVisible\(true\)/);
  assert.match(mainSource, /settingsBackButton\.addEventListener\("click"/);
  assert.match(mainSource, /leaderboardBackButton\.addEventListener\("click"/);
  assert.match(mainSource, /leaderboardMenuButton\.addEventListener\("click", showMainMenu\)/);
  assert.match(settingsPanel, /role="radiogroup" aria-labelledby="theme-setting-label"/);
  assert.match(settingsPanel, /id="theme-setting-label">Theme</);
  assert.match(mainSource, /glyph\.textContent = colorBlindMode \? color\.glyph : ""/);
  assert.doesNotMatch(mainSource, /responseRails/);
  assert.doesNotMatch(stylesSource, /\.response-rails|\.response-rail(?:__fill)?/);
  assert.match(mainSource, /responseProgressFill\.style\.transform = `scaleX\(\$\{progress\}\)`/);
  assert.match(
    stylesSource,
    /\.response-progress\s*\{[^}]+background:\s*rgba\(255,\s*255,\s*255,\s*0\.12\)/s
  );
  assert.match(
    stylesSource,
    /\.response-progress__fill\s*\{[^}]+background:\s*rgba\(255,\s*255,\s*255,\s*0\.6\)[^}]+transform-origin:\s*left/s
  );

  assert.match(stylesSource, /assets\/disco-concrete\.png/);
  assert.match(stylesSource, /assets\/disco-tile-overlay\.png/);
  assert.ok(
    (stylesSource.match(/assets\/disco-concrete\.png/g) ?? []).length >= 3,
    "Disco concrete should remain visible across the page, board, and menu surface."
  );
  assert.match(
    stylesSource,
    /:root\[data-theme="disco"\] \.tile::before\s*\{[^}]+disco-tile-overlay\.png/s
  );
  assert.match(
    stylesSource,
    /:root\[data-theme="disco"\] \.tile--lit::before\s*\{[^}]+opacity:/s
  );
  assert.match(stylesSource, /data-glyphs="off"[^}]+theme-preview__glyph/s);
  assert.doesNotMatch(indexHtml, /type="password"|type="email"|TikTok|Instagram|Facebook/i);
  assert.match(mainSource, /loginWithGoogleCredential/);
  assert.match(mainSource, /submitPendingResult/);
  assert.match(mainSource, /theme: "outline"/);
  assert.match(stylesSource, /\.google-signin\s*\{[^}]+background:\s*transparent !important/s);
});

test("Music is an adaptive Web Audio soundtrack with an independent setting", () => {
  const musicSetting = indexHtml.match(
    /<label class="setting-row" for="music-toggle">[\s\S]*?<\/label>/
  )?.[0] ?? "";
  const interactiveMusicSetting = indexHtml.match(
    /<label class="setting-row" for="interactive-music-toggle">[\s\S]*?<\/label>/
  )?.[0] ?? "";
  assert.match(musicSetting, />Music</);
  assert.match(musicSetting, /Adaptive soundtrack/);
  assert.match(musicSetting, /id="music-toggle"[^>]+role="switch"/);
  assert.match(musicSetting, /\bchecked\b/);
  assert.match(interactiveMusicSetting, />Interactive Music</);
  assert.match(interactiveMusicSetting, />\s*Beta\s*</i);
  assert.match(interactiveMusicSetting, /Correct taps play the melody/);
  assert.match(interactiveMusicSetting, /id="interactive-music-toggle"[^>]+role="switch"/);
  assert.match(interactiveMusicSetting, /\bchecked\b/);
  assert.match(mainSource, /speedytapper\.music\.v1/);
  assert.match(mainSource, /speedytapper\.interactiveMusic\.v1/);
  assert.match(mainSource, /let musicEnabled = true;/);
  assert.match(mainSource, /let interactiveMusicEnabled = true;/);
  assert.match(mainSource, /musicEnabled = storedMusic !== "off";/);
  assert.match(mainSource, /interactiveMusicEnabled = storedInteractiveMusic !== "off";/);
  assert.match(mainSource, /music\.setInteractive\(interactiveMusicEnabled\)/);
  assert.match(mainSource, /musicStageFor\(snapshot\)/);
  assert.match(musicSource, /MUSIC_STAGES\.GRID_2/);
  assert.match(musicSource, /MUSIC_STAGES\.GRID_4/);
  assert.match(musicSource, /MUSIC_STAGES\.CHALLENGE/);
  assert.match(mainSource, /finishGame[\s\S]*music\.advanceTrack\(MUSIC_STAGES\.MENU\)/);
  assert.match(mainSource, /completedSessionId === currentSession/);
  assert.match(mainSource, /setInterval\([\s\S]*updateMusicForSnapshot\(snapshot\)/);
  assert.match(configSource, /fourByFourPressure:\s*90_000/);
  assert.match(configSource, /endurance:\s*120_000/);
  for (const filename of [
    "neon-circuit-refined.m4a",
    "deep-current.m4a",
    "power-grid.m4a"
  ]) {
    assert.match(musicSource, new RegExp(filename.replace(".", "\\.")));
    assert.match(workerSource, new RegExp(`/assets/audio/${filename.replace(".", "\\.")}`));
  }
  for (const filename of [
    "interactive-neon-circuit-refined.m4a",
    "interactive-deep-current.m4a",
    "interactive-power-grid.m4a",
    "interactive-notes-neon-circuit-refined.wav",
    "interactive-notes-deep-current.wav",
    "interactive-notes-power-grid.wav"
  ]) {
    assert.match(musicSource, new RegExp(filename.replace(".", "\\.")));
    assert.match(workerSource, new RegExp(`/assets/audio/${filename.replace(".", "\\.")}`));
  }
  assert.match(
    mainSource,
    /if \(result\.type === "hit"\) \{\s*music\.playCorrectTap\(result\.snapshot\.hits\)/,
    "Only a confirmed correct tap may trigger the interactive melody."
  );
  assert.equal(
    (mainSource.match(/music\.playCorrectTap\(/g) ?? []).length,
    1,
    "Misses, dodges, and unready cues must never trigger or replay a melody note."
  );
  assert.match(mainSource, /resolveInteractiveMusicSection\(snapshot\)/);
  assert.match(musicSource, /MAX_NOTE_VOICES = 4/);
  assert.match(musicSource, /latencyHint: interactive \? "interactive" : "playback"/);
  assert.match(musicSource, /prepareInteractiveTrack\(desiredTrackIndex/);
  assert.match(musicSource, /createBufferSource\(\)/);
  assert.match(musicSource, /loopStart/);
  assert.match(musicSource, /loopEnd/);
  assert.match(musicSource, /linearRampToValueAtTime/);
  assert.match(workerSource, /function cacheFirst\(request\)/);
  assert.match(indexHtml, /speedyTapperWorkerReady/);
  assert.match(mainSource, /await globalThis\.speedyTapperWorkerReady/);
  assert.match(workerSource, /const MUSIC_ASSET_PATHS = new Set\(\[/);
  assert.match(
    mainSource,
    /if \(elements\.leaderboardView\.hidden\) openLeaderboard\(returnView\);/,
    "Switching leaderboard tabs must not reset focus or scroll by reopening the view."
  );
});

test("reaction timing is anchored to presentation and original pointer contact", () => {
  assert.match(mainSource, /requestAnimationFrame\(\(visibleAt\) => \{/);
  assert.match(mainSource, /engine\.activateRound\(visibleAt\)/);
  assert.match(mainSource, /resolveInputTimestamp\(event\.timeStamp, handledAt\)/);
  assert.match(mainSource, /reactionDeadline\([\s\S]*visibleAt/);
  assert.match(mainSource, /remainingUntilDeadline\(deadlineAt, now\(\)\)/);
  assert.match(mainSource, /wasCoveredByDeadlineResolution/);
  assert.match(mainSource, /deadlineCommit = scheduleAfterPaint\(presentationScheduler/);
  assert.match(mainSource, /roundId !== activeRoundId/);
  assert.match(mainSource, /reachedDeadline\(inputAt, runDeadlineAt\)[\s\S]*finishZenRun\(sessionId, inputAt\)/);
  assert.match(mainSource, /scheduleZenEnd\(currentSession, runDeadlineAt\)/);
  assert.match(mainSource, /runEndCommit = scheduleAfterPaint\(presentationScheduler/);
  assert.match(mainSource, /window\.addEventListener\("pagehide",[\s\S]*stopRunForPageExit\(\)/);
  assert.match(
    mainSource,
    /function stopRunForPageExit\(\)[\s\S]*runStartFrame === null[\s\S]*clearTimers\(\)/,
    "Backgrounding before the first presentation frame must cancel the pending run safely."
  );
});

test("Google-only profiles replace local names and submit completed runs automatically", () => {
  assert.doesNotMatch(mainSource, /REMEMBERED_NAME_STORAGE_KEY|leaderboardName|playerName/);
  assert.match(indexHtml, /id="profile-google-signin"/);
  assert.match(indexHtml, /id="result-google-signin"/);
  assert.match(indexHtml, /id="profile-nickname"/);
  assert.match(indexHtml, /id="profile-logout"/);
  assert.match(mainSource, /https:\/\/accounts\.google\.com\/gsi\/client/);
  assert.match(mainSource, /profileClient\.loginWithGoogleCredential\(credential\)/);
  assert.match(mainSource, /profileClient\.updateNickname\(nickname\)/);
  assert.match(mainSource, /profileClient\.logout\(\)/);
  assert.match(mainSource, /profileClient\.submitResult\(\{/);
  assert.match(
    mainSource,
    /function finishGame\(snapshot, currentSession\)[\s\S]*void submitPendingResult\(\)/
  );
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /type="email"|type="password"|TikTok|Instagram|Facebook/i);
});

test("result leaderboard navigation preserves result context and renders compact absolute ranks", () => {
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /Top 20|of 20 places/i);
  assert.match(indexHtml, /class="dialog-utility" id="dialog-utility"/);
  assert.match(indexHtml, /id="leaderboard-toggle"[\s\S]*id="leaderboard-rank" hidden/);
  assert.match(indexHtml, /class="leaderboard-utility"/);
  assert.match(indexHtml, /class="leaderboard-tabs" role="group" aria-label="Leaderboard mode"/);
  assert.match(
    indexHtml,
    /class="leaderboard-tabs"[^>]*>[\s\S]*?<\/div>\s*<p class="leaderboard-player-position" id="leaderboard-player-position"/
  );
  assert.doesNotMatch(indexHtml, /role="tab"|role="tablist"|aria-selected/);
  assert.match(mainSource, /let leaderboardReturnView = "menu";/);
  assert.match(mainSource, /function openResultLeaderboard\(\)/);
  assert.match(mainSource, /openLeaderboard\("result"\)/);
  assert.match(mainSource, /function returnFromLeaderboard\(\)[\s\S]*showResultView\(elements\.leaderboardToggle\)/);
  assert.match(mainSource, /dialogView === "result" && pendingResult\?\.mode === mode/);
  assert.match(mainSource, /const entryRank = Number\.isInteger\(entry\.rank\) \? entry\.rank : index \+ 1/);
  assert.match(mainSource, /entryRank > previousRank \+ 1/);
  assert.match(mainSource, /currentBadge\.textContent = "You"/);
  assert.match(mainSource, /function renderLeaderboardPlayerPosition\(/);
  assert.match(mainSource, /`Your position: #\$\{safeRank\.toLocaleString\(\)\} · Top \$\{safePercent\}%`/);
  assert.match(mainSource, /if \(!profileSession\.authenticated\) \{[\s\S]*leaderboardPlayerPosition\.hidden = true/);
  assert.match(mainSource, /submittedResult\.topPercent = body\.topPercent \?\? null/);
  assert.match(stylesSource, /\.leaderboard-entry\.is-current/);
  assert.match(stylesSource, /\.leaderboard-gap/);
  assert.match(
    stylesSource,
    /\.leaderboard-entry\s*\{[^}]+grid-template-columns:\s*38px\s+minmax\(0,\s*1fr\)\s+auto/s
  );
});

test("the settings shortcut uses simple music state copy and the app carries the OTC Software footer", () => {
  assert.match(indexHtml, /id="settings-current">Classic · FX on · Music on</);
  assert.match(mainSource, /const musicStatus = musicEnabled \? "on" : "off";/);
  assert.doesNotMatch(mainSource, /Music \$\{musicStatus\}[\s\S]*musicStatus[^\n]+interactive/);
  assert.match(indexHtml, /<footer class="copyright-footer">Copyright © 2026 OTC Software<\/footer>/);
  assert.match(stylesSource, /\.copyright-footer/);
});

test("player-facing profile and leaderboard copy uses personal bests without season jargon", () => {
  assert.match(indexHtml, /<h2>Personal best<\/h2>/);
  assert.match(indexHtml, /<span>Best runs<\/span>/);
  assert.match(mainSource, /Your personal best is unchanged\./);
  assert.match(mainSource, /Your leaderboard position is #/);
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /Season result|Current season|seasonal/i);
});

test("in-game and result controls provide restart and menu shortcuts", () => {
  assert.match(indexHtml, /class="game-header"/);
  assert.match(indexHtml, /class="game-utility" id="game-utility" hidden/);
  assert.match(
    indexHtml,
    /id="game-utility"[\s\S]*brand-logo[\s\S]*id="game-restart-button"[\s\S]*id="game-menu-button"[\s\S]*<header class="hud"/
  );
  assert.match(indexHtml, /id="game-restart-button"[\s\S]*aria-label="Restart current game"/);
  assert.match(indexHtml, /id="game-menu-button"[\s\S]*aria-label="Return to main menu"/);
  assert.match(
    indexHtml,
    /id="result-content"[^>]*hidden[\s\S]*id="result-restart-button"[^>]*type="button"[\s\S]*id="main-menu-button"/
  );
  assert.match(indexHtml, /id="dialog-utility"[\s\S]*id="leaderboard-toggle"[\s\S]*id="profile-toggle"/);
  assert.match(indexHtml, /id="coin-balance"[\s\S]*<ellipse cx="12" cy="6" rx="6\.5" ry="2\.5"/);

  assert.match(mainSource, /gameRestartButton:\s*document\.querySelector\("#game-restart-button"\)/);
  assert.match(mainSource, /gameMenuButton:\s*document\.querySelector\("#game-menu-button"\)/);
  assert.match(mainSource, /resultRestartButton:\s*document\.querySelector\("#result-restart-button"\)/);
  const restartBody = mainSource.match(/function restartCurrentMode\(\)\s*\{([^}]*)\}/s)?.[1] ?? "";
  assert.match(restartBody, /pendingResult\?\.mode\s*\?\?\s*engine\.mode/);
  assert.match(restartBody, /startGame\([^)]+\);/);
  assert.match(mainSource, /function startGame\(mode\)[\s\S]*elements\.gameUtility\.hidden = false;/);
  assert.match(mainSource, /function resetResultUi\(\)[\s\S]*elements\.gameUtility\.hidden = true;/);

  function assertClickHandler(elementName, handlerName) {
    assert.match(
      mainSource,
      new RegExp(
        `elements\\.${elementName}\\.addEventListener\\("click",\\s*(?:${handlerName}|\\(\\) => ${handlerName}\\(\\))\\);`
      )
    );
  }

  assertClickHandler("gameRestartButton", "restartCurrentMode");
  assertClickHandler("resultRestartButton", "restartCurrentMode");
  assertClickHandler("gameMenuButton", "showMainMenu");
  assertClickHandler("mainMenuButton", "showMainMenu");
  assert.match(mainSource, /function showMainMenu\(\)[\s\S]*elements\.normalButton\.focus\(\{ preventScroll: true \}\)/);
  assert.match(stylesSource, /\.dialog\s*\{[^}]*width:\s*min\(100%,\s*460px\)/s);
  assert.match(
    stylesSource,
    /\.dialog-utility \.brand-logo\s*\{[^}]*font-size:\s*clamp\(1\.05rem,\s*4\.7vw,\s*1\.45rem\)[^}]*line-height:\s*1\.08/s
  );
  assert.match(
    stylesSource,
    /\.overlay\s*\{[^}]*max\(10px,\s*env\(safe-area-inset-right\)\)[^}]*max\(10px,\s*env\(safe-area-inset-left\)\)/s
  );
});

test("three-minute Zen, independent decoys, and speed feedback are wired into the shell", () => {
  assert.match(configSource, /zenDurationMs:\s*180_000/);
  assert.match(indexHtml, /id="zen-button"[^>]*>3-min Zen</);
  assert.match(mainSource, /elements\.statusValue\.textContent = "∞"/);
  assert.match(mainSource, /engine\.getNextDecoyDelayMs\(now\(\)\)/);
  assert.match(mainSource, /engine\.activateDecoy\(visibleAt\)/);
  assert.match(mainSource, /engine\.expireDecoys\(expiredAt\)/);
  assert.match(mainSource, /reachedDeadline\(expiredAt, runDeadlineAt\)/);
  assert.match(mainSource, /reachedDeadline\(visibleAt, runDeadlineAt\)/);
  assert.match(mainSource, /scheduleDecoySpawn\(currentSession\)/);
  assert.match(mainSource, /scheduleDecoyExpiry\(currentSession\)/);
  assert.match(mainSource, /function cancelDecoyCadence\(\)[\s\S]*decoyCadenceId \+= 1/);
  assert.match(
    mainSource,
    /function handleMiss\(result, currentSession\)[\s\S]*if \(result\.lifeLost\) cancelDecoyCadence\(\)[\s\S]*if \(result\.lifeLost\) scheduleDecoySpawn\(currentSession\)/
  );
  assert.match(mainSource, /cadenceId !== decoyCadenceId/);
  assert.match(mainSource, /decoySpawnTimer !== spawnTimerId/);
  assert.match(mainSource, /decoyExpiryTimer !== expiryTimerId/);
  assert.match(mainSource, /decoyActivationFrame !== activationFrameId/);
  assert.match(mainSource, /engine\.tap\(cellIndex, inputAt, handledAt\)/);
  assert.match(mainSource, /result\.displayedReactionMs/);
  assert.match(mainSource, /showSpeedRating\(result\.speedRating\)/);
  assert.match(indexHtml, /id="speed-rating-overlay" aria-hidden="true"/);
  assert.match(indexHtml, /id="speed-summary-bar"/);
  assert.match(indexHtml, /id="streak-meter"/);
  assert.match(indexHtml, /id="score-multiplier">1×</);
  assert.match(configSource, /tapsPerMultiplier:\s*5/);
  assert.match(configSource, /maximumMultiplier:\s*5/);
  assert.match(mainSource, /function renderStreak\(snapshot\)/);
  assert.match(mainSource, /multiplierBasePoints/);
  assert.match(mainSource, /runId:\s*submittedResult\.runId/);
  assert.match(mainSource, /createLeaderboardSpeedBar\(ratings\)/);
  assert.match(stylesSource, /\.leaderboard-entry__speed-bar/);
  assert.match(stylesSource, /\.speed-rating-overlay--godlike/);
  assert.match(stylesSource, /\.speed-rating-overlay--left\s*\{[^}]+--speed-rating-tilt:\s*-6deg/s);
  assert.match(stylesSource, /\.speed-rating-overlay--right\s*\{[^}]+--speed-rating-tilt:\s*6deg/s);
  assert.ok(
    (stylesSource.match(/rotate\(var\(--speed-rating-tilt\)\)/g) ?? []).length >= 4,
    "Speed-rating tilt must remain applied throughout animation and reduced-motion display."
  );
  assert.match(
    mainSource,
    /elements\.speedRatingOverlay\.className = "speed-rating-overlay";\s*void elements\.speedRatingOverlay\.offsetWidth;/
  );
  assert.match(stylesSource, /\.speed-summary__segment--perfect/);
  assert.match(mainSource, /function hasConfirmedProfile\(\)/);
  assert.match(mainSource, /profile\?\.nicknameConfirmed === true/);
  assert.match(mainSource, /submittedResult\.improved = body\.improved === true/);
  assert.match(mainSource, /document\.querySelector\('script\[data-google-identity="true"\]'\)\?\.remove\(\)/);
  assert.match(mainSource, /if \(!globalThis\.google\?\.accounts\?\.id\) \{[\s\S]*script\.remove\(\);[\s\S]*reject\(/);
  assert.match(mainSource, /function showResultView\([\s\S]*renderResultSaveState\(\);[\s\S]*renderGoogleButtons\(\)/);
});

test("Classic and Disco gameplay tiles keep distinct material treatments", () => {
  function ruleFor(selector) {
    const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    return stylesSource.match(new RegExp(`(?:^|\\n)${escapedSelector}\\s*\\{([^}]*)\\}`, "s"))?.[1] ?? "";
  }

  const classicIdle = ruleFor(".tile");
  const classicLit = ruleFor(".tile--lit");
  const discoIdle = ruleFor(':root[data-theme="disco"] .tile');
  const discoWear = ruleFor(':root[data-theme="disco"] .tile::before');
  const discoLit = ruleFor(':root[data-theme="disco"] .tile--lit');
  const discoLitWear = ruleFor(':root[data-theme="disco"] .tile--lit::before');
  const discoConcreteRules = [
    ruleFor(':root[data-theme="disco"] body'),
    ruleFor(':root[data-theme="disco"] .board-shell'),
    ruleFor(':root[data-theme="disco"] .overlay'),
    ruleFor(':root[data-theme="disco"] .dialog')
  ];

  for (const [name, rule] of Object.entries({
    classicIdle,
    classicLit,
    discoIdle,
    discoWear,
    discoLit,
    discoLitWear
  })) {
    assert.ok(rule, `${name} must have an explicit CSS rule.`);
  }

  assert.match(classicIdle, /background:\s*[\s\S]*linear-gradient/);
  assert.doesNotMatch(classicIdle, /disco-tile-overlay|mix-blend-mode/);
  assert.match(classicLit, /background:\s*var\(--tile-color\);/);
  assert.doesNotMatch(classicLit, /radial-gradient|linear-gradient|filter:/);
  assert.match(classicLit, /animation:\s*cell-on\b/);
  const classicAnimationStart = stylesSource.indexOf("@keyframes cell-on");
  const discoAnimationStart = stylesSource.indexOf("@keyframes disco-cell-on");
  assert.ok(classicAnimationStart >= 0 && discoAnimationStart > classicAnimationStart);
  assert.doesNotMatch(
    stylesSource.slice(classicAnimationStart, discoAnimationStart),
    /filter:/,
    "Classic's activation animation must not bleach its palette with brightness filtering."
  );

  assert.match(discoIdle, /isolation:\s*isolate/);
  assert.notEqual(discoIdle.match(/background:\s*([\s\S]*?);/)?.[1], classicIdle.match(/background:\s*([\s\S]*?);/)?.[1]);
  assert.match(discoWear, /disco-tile-overlay\.png/);
  assert.match(discoWear, /mix-blend-mode:\s*screen/);
  assert.match(discoLit, /radial-gradient/);
  assert.match(discoLit, /linear-gradient/);
  assert.match(discoLit, /animation-name:\s*disco-cell-on/);
  assert.match(discoLitWear, /opacity:/);
  assert.doesNotMatch(classicLit, /disco-cell-on|disco-tile-overlay/);
  assert.ok(
    discoConcreteRules.every((rule) => /disco-concrete\.png/.test(rule)),
    "Disco concrete must remain on the page, board, overlay, and dialog surfaces."
  );
});
