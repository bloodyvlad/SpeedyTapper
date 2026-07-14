import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import test from "node:test";

const [
  indexHtml,
  earlyBootstrapSource,
  mainSource,
  configSource,
  engineSource,
  petCatalogSource,
  petControllerSource,
  soundSource,
  serviceWorkerRegistrationSource,
  workerSource,
  stylesSource,
  mishaClimber,
  mishaSprite,
  fokaFloe,
  fokaSprite,
  keshaPerch,
  keshaSprite,
  tautaBed,
  tautaSprite,
  pancakeSprite
] = await Promise.all([
  readFile(new URL("../index.html", import.meta.url), "utf8"),
  readFile(new URL("../src/early-bootstrap.js", import.meta.url), "utf8"),
  readFile(new URL("../src/main.js", import.meta.url), "utf8"),
  readFile(new URL("../src/config.js", import.meta.url), "utf8"),
  readFile(new URL("../src/game-engine.js", import.meta.url), "utf8"),
  readFile(new URL("../src/pet-catalog.js", import.meta.url), "utf8"),
  readFile(new URL("../src/pet-controller.js", import.meta.url), "utf8"),
  readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8"),
  readFile(new URL("../src/service-worker-registration.js", import.meta.url), "utf8"),
  readFile(new URL("../sw.js", import.meta.url), "utf8"),
  readFile(new URL("../styles.css", import.meta.url), "utf8"),
  readFile(new URL("../assets/pets/misha-climber.png", import.meta.url)),
  readFile(new URL("../assets/pets/misha-sprite.png", import.meta.url)),
  readFile(new URL("../assets/pets/foka-ice-floe.png", import.meta.url)),
  readFile(new URL("../assets/pets/foka-sprite.png", import.meta.url)),
  readFile(new URL("../assets/pets/kesha-perch.png", import.meta.url)),
  readFile(new URL("../assets/pets/kesha-sprite.png", import.meta.url)),
  readFile(new URL("../assets/pets/tauta-bed.png", import.meta.url)),
  readFile(new URL("../assets/pets/tauta-sprite.png", import.meta.url)),
  readFile(new URL("../assets/pets/pancake-sprite.png", import.meta.url))
]);

const [audioFiles, sourceFiles] = await Promise.all([
  readdir(new URL("../assets/audio/", import.meta.url)),
  readdir(new URL("../src/", import.meta.url))
]);

test("the complete browser module graph uses one release version", () => {
  const buildId = workerSource.match(/const BUILD_ID = "([^"]+)";/)?.[1];
  assert.ok(buildId, "The service worker must declare a build ID.");
  assert.equal(buildId, "20260714-5");

  assert.match(indexHtml, new RegExp(`styles\\.css\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`manifest\\.webmanifest\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`early-bootstrap\\.js\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`main\\.js\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`service-worker-registration\\.js\\?v=${buildId}`));
  assert.match(serviceWorkerRegistrationSource, new RegExp(`const buildId = "${buildId}";`));
  assert.match(serviceWorkerRegistrationSource, /sw\.js\?v=\$\{buildId\}/);
  assert.doesNotMatch(indexHtml, /<script(?![^>]*\bsrc=)[^>]*>/i);
  assert.match(earlyBootstrapSource, /speedytapper\.theme\.v1/);
  assert.match(mainSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`game-engine\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`input-timing\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`pet-catalog\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`pet-controller\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`sound-controller\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`profile-client\\.js\\?v=${buildId}`));
  assert.match(engineSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(workerSource, new RegExp(`input-timing\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`pet-catalog\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`pet-controller\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`sound-controller\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`profile-client\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`early-bootstrap\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`service-worker-registration\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, /\.\/assets\/disco-concrete\.png/);
  assert.match(workerSource, /\.\/assets\/disco-tile-overlay\.png/);
  assert.match(workerSource, /\.\/assets\/pets\/misha-climber\.png/);
  assert.match(workerSource, /\.\/assets\/pets\/misha-sprite\.png/);
  for (const path of [
    "foka-ice-floe.png",
    "foka-sprite.png",
    "kesha-perch.png",
    "kesha-sprite.png",
    "tauta-bed.png",
    "tauta-sprite.png",
    "pancake-sprite.png"
  ]) {
    assert.match(workerSource, new RegExp(`\\.\\/assets\\/pets\\/${path.replaceAll(".", "\\.")}`));
  }
  assert.doesNotMatch(workerSource, /pancake-tile|misha-controller/);

  const appShell = workerSource.match(/const APP_SHELL = \[([\s\S]*?)\];/)?.[1] ?? "";
  assert.doesNotMatch(appShell, /assets\/audio|\.(?:mp3|m4a|aac|wav|ogg)/i);
  assert.doesNotMatch(indexHtml, /<audio\b|rel="preload"[^>]+as="audio"/i);
  assert.deepEqual(audioFiles.toSorted(), ["SOURCES.md", "tap-tones.wav"]);
  assert.equal(sourceFiles.includes("music-controller.js"), false);
  assert.doesNotMatch(`${indexHtml}\n${mainSource}\n${workerSource}`, /music-controller|music-toggle|interactive-music/i);
  assert.doesNotMatch(workerSource, /MUSIC_ASSET_PATHS|cacheFirst\(/);
  assert.match(
    workerSource,
    /pathname\.startsWith\("\/assets\/audio\/"\)[\s\S]*fetch\(event\.request, \{ cache: "no-store" \}\)/
  );
  assert.match(workerSource, /fetch\(request, \{ cache: "no-store" \}\)/);
});

test("the Pet Shop ships five animated companions with separate menu and gameplay placements", () => {
  assert.match(indexHtml, /id="menu-pet-scene"[\s\S]*data-pet="none"[\s\S]*data-habitat="true"[\s\S]*hidden/);
  assert.match(indexHtml, /id="game-pet-scene"[\s\S]*data-habitat="false"[\s\S]*hidden/);
  assert.match(indexHtml, /id="dialog-utility"[\s\S]*id="menu-pet-scene"[\s\S]*id="dialog-title"/);
  assert.match(indexHtml, /id="streak-meter"[\s\S]*id="game-pet-scene"[\s\S]*streak-meter__track/);
  assert.match(indexHtml, /id="pet-shop-toggle"[\s\S]*id="settings-toggle"/);

  for (const [id, name, price] of [
    ["foka", "Foka", 10],
    ["kesha", "Kesha", 20],
    ["tauta", "Tauta", 50],
    ["misha", "Misha", 100],
    ["pancake", "Pancake", 500]
  ]) {
    assert.match(indexHtml, new RegExp(`data-pet-card="${id}"[\\s\\S]*?<strong>${name}<\\/strong>[\\s\\S]*?aria-label="${price} coins"[\\s\\S]*?data-pet-action="${id}">Buy<`));
  }
  assert.match(indexHtml, /<strong>Pancake<\/strong><small>Dancing meme<\/small>/);
  assert.doesNotMatch(indexHtml, /data-pet-equipped|>Equipped</);
  assert.match(mainSource, /resolvePetShopAction\(\{ owned, selected, visible: petVisible \}\)/);
  assert.match(mainSource, /profileClient\.setPetVisibility\(petId, nextVisibility\)/);
  assert.match(mainSource, /profileClient\.selectPet\(petId\)/);
  assert.match(
    mainSource,
    /body\.pet\?\.purchased === true[\s\S]*achievementsPayload = null;[\s\S]*loadAchievements\(\{ showLoading: false \}\)/,
    "A committed first purchase refreshes the achievement state."
  );
  assert.match(mainSource, /pets\.handleGameplayTap\(event\.clientX, event\.clientY\)/);
  assert.match(mainSource, /pets\.handleNonGameTap\(event\.clientX, event\.clientY\)/);
  assert.match(stylesSource, /\.pet-scene--menu > \.pet-sprite \{[\s\S]*?top: -4px;/);
  assert.match(stylesSource, /\.pet-scene--menu\[data-pet="foka"\][\s\S]*?top: -6px;/);
  assert.match(stylesSource, /\.pet-preview-scene > \.pet-sprite \{[\s\S]*?top: -8px;/);
  assert.match(stylesSource, /\.pet-preview-scene\[data-pet="foka"\][\s\S]*?top: -13px;/);
  assert.match(stylesSource, /\[data-pet="misha"\] > \.pet-sprite \{[\s\S]*?z-index: 4;/);
  assert.match(stylesSource, /\[data-pet="pancake"\] > \.pet-sprite::before \{[\s\S]*?box-shadow: 10px 0 #140905;/);
  assert.match(stylesSource, /\.leaderboard-entry__avatar\[data-pet="pancake"\] > \.pet-sprite::before \{[\s\S]*?top: 14px;[\s\S]*?box-shadow: 6px 0 #140905;/);
  assert.match(stylesSource, /pancake-dance 1440ms/);
  assert.match(stylesSource, /pancake-line-glow 1440ms/);
  assert.match(petControllerSource, /LEGACY_MISHA_NICKNAME = "misha_boy"/);
  assert.match(petControllerSource, /PET_IDLE_DELAY_MS = 5_000/);
  assert.match(petControllerSource, /resolvePancakeFacing/);
  assert.match(stylesSource, /@keyframes pet-turn-half-left/);
  assert.match(stylesSource, /@keyframes pet-turn-half-right/);
  assert.match(stylesSource, /prefers-reduced-motion[\s\S]*\.pet-sprite/);

  for (const sprite of [mishaSprite, fokaSprite, keshaSprite, tautaSprite, pancakeSprite]) {
    assert.equal(sprite.subarray(1, 4).toString("ascii"), "PNG");
    assert.equal(sprite.readUInt32BE(16), 320);
    assert.equal(sprite.readUInt32BE(20), 32);
  }
  for (const habitat of [mishaClimber, fokaFloe, keshaPerch, tautaBed]) {
    assert.equal(habitat.subarray(1, 4).toString("ascii"), "PNG");
    assert.equal(habitat.readUInt32BE(16), 64);
    assert.equal(habitat.readUInt32BE(20), 48);
  }
  assert.match(petCatalogSource, /priceCoins: 500/);
});

test("Arcade is the player-facing name for the compatible normal mode", () => {
  assert.match(indexHtml, /id="mode-name">Arcade</);
  assert.match(indexHtml, /id="normal-button"[^>]*>\s*<span>Arcade<\/span>/);
  assert.match(indexHtml, /data-profile-mode="normal"[^>]*>Arcade</);
  assert.match(indexHtml, /data-leaderboard-mode="normal"[^>]*>Arcade</);
  assert.match(indexHtml, /id="result-restart-button"[\s\S]*aria-label="Restart Arcade mode"/);
  assert.match(mainSource, /`Restart \$\{isZenResult \? "Zen" : "Arcade"\} mode`/);
  assert.match(mainSource, /The best score for Arcade mode is/);
  assert.match(configSource, /NORMAL: "normal"/);
  assert.doesNotMatch(configSource, /NORMAL: "arcade"/);
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
  assert.match(soundSetting, /Low-latency tap tones/);
  assert.match(soundToggle, /role="switch"/);
  assert.match(soundToggle, /\bchecked\b/);
  assert.match(indexHtml, /id="settings-current">Classic · FX on</);
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
  assert.match(soundSource, /TONE_BANK_URL = "\.\/assets\/audio\/tap-tones\.wav"/);
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
  assert.doesNotMatch(soundSource, /hum|oops|lifeLost|tileOn|tileOff/i);
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
  assert.match(indexHtml, /id="result-menu-button"/);
  assert.doesNotMatch(indexHtml, /id="main-menu-button"/);
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
  assert.match(indexHtml, /id="coin-balance"[^>]+aria-label="Coins unavailable while signed out"/s);
  assert.match(indexHtml, /id="coin-balance"[^>]+aria-disabled="true"/s);
  assert.match(indexHtml, /id="coin-count">0<\/strong>/);
  assert.match(indexHtml, /class="pixel-coin"[^>]+shape-rendering="crispEdges"/);
  assert.match(
    stylesSource,
    /\.coin-balance\s*\{[^}]+width:\s*44px;[^}]+min-width:\s*44px;[^}]+height:\s*44px;[^}]+padding:\s*0;/s
  );
  assert.match(
    stylesSource,
    /\.coin-balance strong\s*\{[^}]+position:\s*absolute;[^}]+right:\s*-5px;[^}]+bottom:\s*-6px;/s
  );
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

test("Sound FX owns the sole fixed tap-tone sequence without music or ambience", () => {
  const settingsPanel = indexHtml.match(
    /<fieldset class="settings-panel" id="settings-panel">[\s\S]*?<\/fieldset>/
  )?.[0] ?? "";

  assert.match(settingsPanel, /id="sound-fx-toggle"[^>]+role="switch"[^>]+checked/);
  assert.doesNotMatch(settingsPanel, /Music|music-toggle|interactive-music/i);
  assert.doesNotMatch(mainSource, /speedytapper\.music|musicEnabled|interactiveMusic|music\./i);
  assert.doesNotMatch(soundSource, /backing|soundtrack|ambient|hum|oops|life[- ]?loss/i);
  assert.match(
    mainSource,
    /if \(result\.type === "hit"\) \{\s*sound\.playCorrectTap\(result\.snapshot\.hits\)/,
    "Only a confirmed correct tap should advance the Sound FX tone sequence."
  );
  assert.equal(
    (mainSource.match(/sound\.playCorrectTap\(/g) ?? []).length,
    1,
    "Misses, dodges, and unready cues must never trigger or replay a tap tone."
  );
  assert.match(soundSource, /TONE_SLOT_COUNT = 16/);
  assert.match(soundSource, /TONE_SLOT_SECONDS = 0\.5/);
  assert.match(soundSource, /MAX_TONE_VOICES = 2/);
  assert.match(soundSource, /const slotIndex = \(safeHitNumber - 1\) % TONE_SLOT_COUNT/);
  assert.match(
    soundSource,
    /source\.start\(\s*context\.currentTime,\s*slotIndex \* TONE_SLOT_SECONDS,\s*TONE_SLOT_SECONDS\s*\)/
  );
  assert.doesNotMatch(soundSource, /loopStart|loopEnd|playbackRate/);
  assert.match(serviceWorkerRegistrationSource, /speedyTapperWorkerReady/);
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
  assert.doesNotMatch(mainSource, /runDeadlineAt|finishZenRun|scheduleZenEnd|runEndCommit/);
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
  assert.match(
    mainSource,
    /if \(mode === GAME_MODES\.NORMAL && hasConfirmedProfile\(\)\) \{[\s\S]*profileClient\.startRun\(mode, APP_BUILD_ID\)/
  );
  assert.match(mainSource, /profileClient\.submitResult\(\{/);
  assert.match(mainSource, /\.\.\.submittedResult\.proof/);
  assert.doesNotMatch(mainSource, /profileClient\.submitResult\(\{[\s\S]{0,500}score:/);
  assert.match(
    mainSource,
    /function presentCompletedRun\(snapshot, currentSession,[\s\S]*if \(!isZenResult\) void submitPendingResult\(\)/
  );
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /type="email"|type="password"|TikTok|Instagram|Facebook/i);
});

test("signed-out economy features explain login benefits and administration stays server-authorized", () => {
  const benefitsCopy = "Login with your Google account to earn coins, access achievements and Pet Shop.";
  assert.ok(
    (indexHtml.match(new RegExp(benefitsCopy.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "g")) ?? []).length >= 2,
    "Login benefits must be explained both inline and on the signed-out Profile screen."
  );
  assert.match(indexHtml, /id="auth-gate-info"[^>]+hidden/);
  assert.match(indexHtml, /id="auth-gate-profile-button"/);
  assert.match(indexHtml, /class="[^"]*is-auth-gated[^"]*"[^>]+id="achievements-toggle"[^>]+aria-disabled="true"/s);
  assert.match(indexHtml, /class="[^"]*is-auth-gated[^"]*"[^>]+id="pet-shop-toggle"[^>]+aria-disabled="true"/s);
  assert.match(mainSource, /function requireLoggedInFeature\(\)[\s\S]*profileSession\.authenticated[\s\S]*showLoginBenefits\(\)/);
  assert.match(mainSource, /function openAchievements\(\)\s*\{\s*if \(!requireLoggedInFeature\(\)\) return;/);
  assert.match(mainSource, /function openPetShop\(\)\s*\{\s*if \(!requireLoggedInFeature\(\)\) return;/);
  assert.match(mainSource, /coinBalance\.addEventListener\("click",[\s\S]*requireLoggedInFeature\(\)/);
  assert.match(mainSource, /setResultSaveStatus\(LOGIN_BENEFITS_COPY\)/);
  assert.match(stylesSource, /\.is-auth-gated/);

  assert.match(indexHtml, /id="leaderboard-admin-toggle"[^>]+hidden/s);
  assert.match(indexHtml, /id="leaderboard-admin-view" hidden/);
  assert.match(indexHtml, /data-admin-view="all"/);
  assert.match(indexHtml, /data-admin-view="scan"/);
  assert.match(indexHtml, /id="leaderboard-admin-status-filter">[\s\S]*?<option value="all">All except deleted<\/option>/);
  assert.match(indexHtml, /id="leaderboard-admin-delete-reset"[^>]*>Delete result &amp; reset rewards</);
  assert.match(indexHtml, /removes every pet and all current coins from that account/);
  assert.match(mainSource, /isAdmin: value\.isAdmin === true/);
  assert.match(mainSource, /leaderboardAdminToggle\.hidden = profileSession\.profile\?\.isAdmin !== true/);
  assert.match(mainSource, /profileClient\.getAdminLeaderboard\(/);
  assert.match(mainSource, /profileClient\.getAdminLeaderboardEntry\(/);
  assert.match(mainSource, /profileClient\.quarantineLeaderboardEntry\(/);
  assert.match(mainSource, /profileClient\.deleteLeaderboardEntryAndReset\([\s\S]*confirmPlayerId: adminEntryPlayerId\(entry\)/);
  assert.match(mainSource, /profileSession\.profile\?\.isAdmin !== true/);
  assert.match(stylesSource, /\.leaderboard-admin-panel/);
});

test("pet habitats follow all non-game views while gameplay stays unobstructed", () => {
  assert.match(petControllerSource, /syncScene\(menuScene, showMenu, showMenu\)/);
  assert.match(petControllerSource, /syncScene\(gameplayScene, showGameplay, false\)/);
  assert.match(mainSource, /avatar\.dataset\.habitat = "true"/);
  assert.match(mainSource, /avatar\.append\(habitatBack, sprite, habitatFront\)/);
  assert.match(stylesSource, /\.leaderboard-entry__avatar > \.pet-habitat/);
});

test("Pet Shop balance and achievement rewards use explicit coin presentation", () => {
  assert.match(indexHtml, /<span>Your balance:<\/span>[\s\S]*id="pet-shop-balance">0 coins<\/strong>/);
  assert.match(indexHtml, /class="achievement-reward-coin"/);
  assert.match(mainSource, /function renderAchievementReward\(/);
  assert.match(mainSource, /value\.textContent = `\+\$\{rewardCoins\}`/);
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /In progress/);
  assert.match(stylesSource, /\.achievement-reward-coin\s*\{/);
});

test("held and cloned proofs never claim a ranked result in Game Over copy", () => {
  assert.match(mainSource, /verificationStatus === "review"[\s\S]*has not been ranked and no coins were awarded/);
  assert.match(mainSource, /verificationStatus === "quarantined"[\s\S]*not ranked and no coins were awarded/);
});

test("pre-verification leaderboard rows are visibly labelled legacy", () => {
  assert.match(mainSource, /entry\.verification === "legacy"/);
  assert.match(mainSource, /legacyBadge\.textContent = "Legacy"/);
  assert.match(stylesSource, /\.leaderboard-entry__verification/);
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
  assert.match(mainSource, /`\$\{label\}: #\$\{safeRank\.toLocaleString\(\)\} · Top \$\{safePercent\}% of results`/);
  assert.match(mainSource, /\["Top results",/);
  assert.match(mainSource, /if \(!profileSession\.authenticated\) \{[\s\S]*leaderboardPlayerPosition\.hidden = true/);
  assert.match(mainSource, /submittedResult\.hasExactLeaderboardContext[\s\S]*body\.submittedRank \?\? body\.contextRank/);
  assert.match(mainSource, /submittedResult\.hasExactLeaderboardContext[\s\S]*body\.contextTopPercent \?\? body\.topPercent \?\? null/);
  assert.match(mainSource, /body\.submittedEntryId === submittedResult\.runId/);
  assert.match(mainSource, /exact historical leaderboard position is unavailable/);
  assert.match(mainSource, /entry\.isContextResult === true/);
  assert.match(mainSource, /entry\.isCurrentPlayer === true/);
  assert.match(stylesSource, /\.leaderboard-entry\.is-current/);
  assert.match(stylesSource, /\.leaderboard-gap/);
  assert.match(
    stylesSource,
    /\.leaderboard-entry\s*\{[^}]+grid-template-columns:\s*28px\s+44px\s+minmax\(0,\s*1fr\)\s+auto/s
  );
});

test("the settings shortcut reports only Sound FX state and the app carries the OTC Software footer", () => {
  assert.match(indexHtml, /id="settings-current">Classic · FX on</);
  assert.match(mainSource, /elements\.settingsCurrent\.textContent = `\$\{themeName\} · FX \$\{soundFxEnabled \? "on" : "off"\}`/);
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /Music on|Music off|musicStatus|interactiveMusic/i);
  assert.match(indexHtml, /<footer class="copyright-footer">Copyright © 2026 OTC Software<\/footer>/);
  assert.match(stylesSource, /\.copyright-footer/);
});

test("player-facing copy explains that every result is saved without season jargon", () => {
  assert.match(indexHtml, /<h2>Leaderboard result<\/h2>/);
  assert.match(indexHtml, /<span>All results<\/span>/);
  assert.match(indexHtml, /save every result and keep your leaderboard history/);
  assert.match(mainSource, /Result saved\.[\s\S]*Your personal best is unchanged\./);
  assert.match(mainSource, /Result saved as a new personal best\./);
  assert.match(mainSource, /This result is #/);
  assert.match(mainSource, /ranked \$\{safeTotal === 1 \? "result" : "results"\}/);
  assert.doesNotMatch(`${indexHtml}\n${mainSource}`, /Season result|Current season|seasonal/i);
});

test("Arcade and Zen expose mode-specific gameplay controls and shared top result shortcuts", () => {
  assert.match(indexHtml, /class="game-header"/);
  assert.match(indexHtml, /class="game-utility" id="game-utility" hidden/);
  assert.match(
    indexHtml,
    /id="game-utility"[\s\S]*brand-logo[\s\S]*id="game-restart-button"[\s\S]*id="game-menu-button"[\s\S]*id="game-end-run-button"[\s\S]*<header class="hud"/
  );
  assert.match(indexHtml, /id="game-restart-button"[\s\S]*aria-label="Restart current game"/);
  assert.match(indexHtml, /id="game-menu-button"[\s\S]*aria-label="Return to main menu"/);
  assert.match(
    indexHtml,
    /id="game-end-run-button"[\s\S]*aria-label="End Zen run and view results"[\s\S]*>\s*End run\s*<\/button>/
  );
  assert.match(
    indexHtml,
    /<header class="dialog-utility"[\s\S]*?<\/header>\s*<nav class="result-navigation" id="result-navigation"[\s\S]*?id="result-restart-button"[\s\S]*?id="result-menu-button"[\s\S]*?<\/nav>/
  );
  assert.ok(
    indexHtml.indexOf('id="result-navigation"') < indexHtml.indexOf('id="result-content"'),
    "The shared square result controls must be above the result body."
  );
  const resultContentMarkup = indexHtml.slice(
    indexHtml.indexOf('id="result-content"'),
    indexHtml.indexOf('id="main-menu-content"')
  );
  assert.doesNotMatch(resultContentMarkup, /result-restart-button|result-menu-button|main-menu-button/);
  assert.doesNotMatch(indexHtml, /id="main-menu-button"/);
  assert.match(indexHtml, /id="dialog-utility"[\s\S]*id="leaderboard-toggle"[\s\S]*id="profile-toggle"/);
  assert.match(indexHtml, /id="coin-balance"[\s\S]*class="pixel-coin"[\s\S]*id="coin-count">0</);

  assert.match(mainSource, /gameRestartButton:\s*document\.querySelector\("#game-restart-button"\)/);
  assert.match(mainSource, /gameMenuButton:\s*document\.querySelector\("#game-menu-button"\)/);
  assert.match(mainSource, /gameEndRunButton:\s*document\.querySelector\("#game-end-run-button"\)/);
  assert.match(mainSource, /resultRestartButton:\s*document\.querySelector\("#result-restart-button"\)/);
  assert.match(mainSource, /resultMenuButton:\s*document\.querySelector\("#result-menu-button"\)/);
  const restartBody = mainSource.match(/function restartCurrentMode\(\)\s*\{([^}]*)\}/s)?.[1] ?? "";
  assert.match(restartBody, /pendingResult\?\.mode\s*\?\?\s*engine\.mode/);
  assert.match(restartBody, /startGame\([^)]+\);/);
  assert.match(mainSource, /function startGame\(mode\)[\s\S]*elements\.gameUtility\.hidden = false;/);
  assert.match(
    mainSource,
    /const isZen = mode === GAME_MODES\.ZEN;\s*elements\.gameRestartButton\.hidden = isZen;\s*elements\.gameMenuButton\.hidden = isZen;\s*elements\.gameEndRunButton\.hidden = !isZen;/
  );
  assert.match(mainSource, /if \(mode === GAME_MODES\.ZEN\) elements\.gameEndRunButton\.disabled = false;/);
  assert.match(mainSource, /function setDialogView\(view\)[\s\S]*elements\.resultNavigation\.hidden = view !== "result"/);
  assert.match(mainSource, /function resetResultUi\(\)[\s\S]*elements\.gameUtility\.hidden = true;/);
  assert.match(
    mainSource,
    /function endZenRun\(\)[\s\S]*engine\.endZenRun\(now\(\)\)[\s\S]*presentCompletedRun\(result\.snapshot, currentSession, \{ localPractice: true \}\)/
  );
  assert.match(
    mainSource,
    /const isZenResult = localPractice && snapshot\.mode === GAME_MODES\.ZEN;\s*elements\.dialogTitle\.textContent = isZenResult \? "Results" : "Game Over";/
  );
  assert.match(mainSource, /elements\.resultDurationLabel\.textContent = isZenResult \? "Played" : "Survived"/);
  assert.match(mainSource, /function renderResultSaveState\(\)[\s\S]*if \(pendingResult\.localPractice\) \{[\s\S]*elements\.resultSavePanel\.hidden = true/);
  assert.match(mainSource, /if \(!isZenResult\) void submitPendingResult\(\)/);
  assert.match(mainSource, /function submitPendingResult\(\)[\s\S]*pendingResult\.localPractice \|\|/);

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
  assertClickHandler("gameEndRunButton", "endZenRun");
  assertClickHandler("resultMenuButton", "showMainMenu");
  assert.match(mainSource, /function showMainMenu\(\)[\s\S]*elements\.normalButton\.focus\(\{ preventScroll: true \}\)/);
  assert.match(stylesSource, /\.game-utility__button\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/s);
  assert.match(stylesSource, /\.result-navigation\s*\{[^}]*display:\s*flex;/s);
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

test("endless unranked Zen has no decoys, deadline, proof submission, or coins", () => {
  assert.match(configSource, /durationMs:\s*null/);
  assert.match(configSource, /decoysEnabled:\s*false/);
  assert.match(configSource, /ranked:\s*false/);
  assert.match(configSource, /awardsCoins:\s*false/);
  assert.match(configSource, /maximumLifetimeMs:\s*750/);
  assert.match(configSource, /lifetimeRangeMs:\s*Object\.freeze\(\[450, 750\]\)/);
  assert.match(configSource, /rareDecoys:\s*Object\.freeze\(\[600, 3_400\]\)/);
  assert.match(engineSource, /recentlyExpiredDecoyIndexes/);
  assert.match(indexHtml, /id="zen-button"[^>]+aria-label="Zen mode\. No coins awarded\."[^>]*>[\s\S]*?<span>Zen<\/span>[\s\S]*?<small>No coins awarded<\/small>/);
  assert.match(mainSource, /elements\.statusValue\.textContent = "∞"/);
  assert.match(mainSource, /elements\.modeName\.textContent = formatDuration\(snapshot\.elapsedMs\)/);
  assert.match(mainSource, /snapshot\.mode === GAME_MODES\.ZEN \? null : topScores\[snapshot\.mode\]/);
  assert.match(mainSource, /mode === GAME_MODES\.NORMAL && hasConfirmedProfile\(\)/);
  assert.match(mainSource, /currentRunId = mode === GAME_MODES\.NORMAL/);
  assert.match(mainSource, /if \(mode === GAME_MODES\.NORMAL\) scheduleDecoySpawn\(currentSession\)/);
  assert.match(mainSource, /function scheduleDecoySpawn[\s\S]*engine\.mode === GAME_MODES\.ZEN \|\|/);
  assert.match(mainSource, /function scheduleDecoyExpiry[\s\S]*engine\.mode === GAME_MODES\.ZEN \|\|/);
  assert.doesNotMatch(mainSource, /finishZenRun|scheduleZenEnd|runDeadlineAt|zenDurationMs/);
  assert.match(engineSource, /getRemainingMs\(\) \{\s*return null;/);
  assert.match(engineSource, /finishTimedRun\(now\) \{\s*return Object\.freeze\(\{ type: "ignored", reason: "not-timed"/);
  assert.match(
    engineSource,
    /endZenRun\(now\)[\s\S]*this\.mode !== GAME_MODES\.ZEN[\s\S]*this\.state = GAME_STATES\.GAME_OVER;[\s\S]*this\.endReason = "manual";[\s\S]*type: "zen-ended"/
  );
  assert.match(mainSource, /You ended your Zen run with[\s\S]*Zen results are not saved and do not award coins/);
  assert.match(mainSource, /function finishGame\(snapshot, currentSession\) \{\s*if \(snapshot\.mode !== GAME_MODES\.NORMAL\) return;/);
  assert.match(engineSource, /this\.#runProofEnabled = mode === GAME_MODES\.NORMAL/);
  assert.match(engineSource, /getNextDecoyDelayMs\(now\)[\s\S]*this\.mode === GAME_MODES\.ZEN[\s\S]*return null/);
  assert.match(engineSource, /activateDecoy\(now\)[\s\S]*this\.mode === GAME_MODES\.ZEN[\s\S]*reason: "decoys-disabled"/);
  assert.match(mainSource, /engine\.getNextDecoyDelayMs\(now\(\)\)/);
  assert.match(mainSource, /engine\.activateDecoy\(visibleAt\)/);
  assert.match(mainSource, /engine\.expireDecoys\(expiredAt\)/);
  assert.match(mainSource, /function cancelDecoyCadence\(\)[\s\S]*decoyCadenceId \+= 1/);
  assert.match(
    mainSource,
    /function handleMiss\(result, currentSession, scheduleNextTarget = true\)[\s\S]*if \(result\.lifeLost\) cancelDecoyCadence\(\)[\s\S]*if \(result\.lifeLost\) scheduleDecoySpawn\(currentSession\)/
  );
  assert.match(configSource, /initialTargetDelayMs:\s*1_000/);
  assert.match(configSource, /cadenceAdaptation:\s*0\.5/);
  assert.match(engineSource, /this\.zenTargetDelayMs \+= adaptation \* \(reactionMs - this\.zenTargetDelayMs\)/);
  assert.match(engineSource, /reason:\s*"target-does-not-expire"/);
  assert.match(engineSource, /targetRetained:\s*true/);
  assert.match(engineSource, /this\.mode !== GAME_MODES\.ZEN &&[\s\S]*reactionProgress/);
  assert.match(
    mainSource,
    /if \(engine\.mode !== GAME_MODES\.ZEN\) \{[\s\S]*scheduleDeadline\(currentSession, roundId, deadlineAt\)[\s\S]*if \(engine\.mode !== GAME_MODES\.ZEN\) \{[\s\S]*startResponseProgress/
  );
  assert.match(mainSource, /result\.targetRetained === true \|\| pendingZenTarget/);
  assert.match(mainSource, /spawnTimer !== null \|\| roundActivationFrame !== null/);
  assert.match(mainSource, /pointsAwarded > 0 \? `\$\{label\} \+\$\{pointsAwarded\.toLocaleString\(\)\}` : label/);
  assert.match(mainSource, /cadenceId !== decoyCadenceId/);
  assert.match(mainSource, /decoySpawnTimer !== spawnTimerId/);
  assert.match(mainSource, /decoyExpiryTimer !== expiryTimerId/);
  assert.match(mainSource, /decoyActivationFrame !== activationFrameId/);
  assert.match(mainSource, /engine\.tap\(cellIndex, inputAt, handledAt\)/);
  assert.match(mainSource, /result\.displayedReactionMs/);
  assert.match(mainSource, /showSpeedRating\(result\.speedRating\)/);
  assert.match(indexHtml, /id="speed-rating-overlay" aria-hidden="true"/);
  assert.match(indexHtml, /id="speed-summary-bar"/);
  assert.match(indexHtml, /id="streak-meter" data-multiplier="1"/);
  assert.match(indexHtml, /class="streak-meter__multiplier" id="score-multiplier">x1</);
  assert.doesNotMatch(indexHtml, /streak-meter-count/);
  assert.match(configSource, /stepsPerMultiplier:\s*5/);
  assert.match(configSource, /godlike:\s*2/);
  assert.match(configSource, /perfect:\s*1/);
  assert.match(configSource, /great:\s*0/);
  assert.match(configSource, /good:\s*0/);
  assert.match(configSource, /maximumMultiplier:\s*5/);
  assert.match(mainSource, /function renderStreak\(snapshot\)/);
  assert.match(mainSource, /classList\.toggle\("streak-meter--full", maximumReached\)/);
  assert.match(mainSource, /streakMeter\.dataset\.multiplier = String\(snapshot\.multiplier\)/);
  assert.match(mainSource, /textContent = `x\$\{snapshot\.multiplier\}`/);
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
  assert.match(stylesSource, /@keyframes streak-fill-sheen/);
  assert.match(stylesSource, /\.streak-meter\[data-multiplier="2"\][\s\S]*--streak-track-background: rgba\(114, 233, 149, 0\.5\)/);
  assert.match(stylesSource, /\.streak-meter\[data-multiplier="3"\][\s\S]*--streak-track-background: rgba\(103, 173, 255, 0\.5\)/);
  assert.match(stylesSource, /\.streak-meter\[data-multiplier="4"\][\s\S]*--streak-track-background: rgba\(198, 140, 255, 0\.5\)/);
  assert.match(stylesSource, /\.streak-meter\[data-multiplier="5"\][\s\S]*--streak-track-background: rgba\(255, 216, 77, 0\.5\)/);
  assert.match(stylesSource, /\.streak-meter__track[\s\S]*background: var\(--streak-track-background\)/);
  assert.match(stylesSource, /\.streak-meter--full \.streak-meter__track/);
  assert.match(mainSource, /function hasConfirmedProfile\(\)/);
  assert.match(mainSource, /profile\?\.nicknameConfirmed === true/);
  assert.match(mainSource, /submittedResult\.improved = body\.improved === true/);
  assert.match(mainSource, /document\.querySelector\('script\[data-google-identity="true"\]'\)\?\.remove\(\)/);
  assert.match(mainSource, /if \(!globalThis\.google\?\.accounts\?\.id\) \{[\s\S]*script\.remove\(\);[\s\S]*reject\(/);
  assert.match(mainSource, /function showResultView\([\s\S]*renderResultSaveState\(\);[\s\S]*renderGoogleButtons\(\)/);
});

test("five durable ranked achievements expose claimable green checks and claimed grey states", () => {
  const achievementIds = [...indexHtml.matchAll(/data-achievement-id="([^"]+)"/g)].map(
    ([, id]) => id
  );
  assert.deepEqual(achievementIds, [
    "complete_arcade",
    "godlike_speed",
    "collect_5_coins",
    "score_over_100k",
    "buy_a_pet"
  ]);
  assert.match(indexHtml, /id="achievements-toggle"[^>]+aria-controls="achievements-view"/s);
  assert.match(indexHtml, /id="achievements-view" hidden/);
  assert.match(indexHtml, /id="achievements-back-button"[^>]*>← Back</);
  assert.match(indexHtml, /id="achievements-progress">0 of 5 claimed</);
  assert.equal((indexHtml.match(/achievement-card--locked/g) ?? []).length, 5);
  assert.doesNotMatch(indexHtml, /Complete Zen mode|complete_zen|three-minute Zen/);
  assert.match(indexHtml, /Complete Arcade mode/);
  assert.match(indexHtml, /Show Godlike speed/);
  assert.match(indexHtml, /Collect 5 coins/);
  assert.match(indexHtml, /Score more than 100K/);
  assert.match(indexHtml, /Buy a pet/);
  assert.match(indexHtml, /<strong><span>\+10<\/span><span class="achievement-reward-coin"/);
  assert.doesNotMatch(indexHtml, /In progress/);

  assert.match(mainSource, /function openAchievements\(\)/);
  assert.match(mainSource, /profileClient\.getAchievements\(\)/);
  assert.match(mainSource, /profileClient\.claimAchievement\(achievementId\)/);
  assert.match(
    mainSource,
    /async function claimAchievement\(achievementId\)[\s\S]*achievementsRequestId \+= 1;[\s\S]*profileClient\.claimAchievement\(achievementId\)/
  );
  assert.match(mainSource, /card\.classList\.add\(`achievement-card--\$\{state\}`\)/);
  assert.match(mainSource, /card\.disabled = state !== "claimable" \|\| achievementClaimId !== null/);
  assert.match(mainSource, /coinBalance:\s*body\.coinBalance/);
  assert.match(mainSource, /body\.duplicate === true/);
  assert.doesNotMatch(mainSource, /localStorage[^\n]*achievement/i);

  assert.match(stylesSource, /\.achievement-card\s*\{[^}]+min-height:\s*76px/s);
  assert.match(stylesSource, /\.achievement-card--claimable\s*\{[^}]+border-color:/s);
  assert.match(
    stylesSource,
    /\.achievement-card--claimable \.achievement-card__check\s*\{[^}]+background:\s*#72e995/s
  );
  assert.match(stylesSource, /\.achievement-card--claimed\s*\{[^}]+opacity:\s*0\.76/s);
  assert.match(stylesSource, /\.achievement-card:focus-visible\s*\{[^}]+outline:\s*3px solid white/s);
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
