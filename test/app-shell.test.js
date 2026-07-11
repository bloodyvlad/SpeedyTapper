import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const [indexHtml, mainSource, engineSource, soundSource, workerSource, stylesSource] = await Promise.all([
  readFile(new URL("../index.html", import.meta.url), "utf8"),
  readFile(new URL("../src/main.js", import.meta.url), "utf8"),
  readFile(new URL("../src/game-engine.js", import.meta.url), "utf8"),
  readFile(new URL("../src/sound-controller.js", import.meta.url), "utf8"),
  readFile(new URL("../sw.js", import.meta.url), "utf8"),
  readFile(new URL("../styles.css", import.meta.url), "utf8")
]);

test("the complete browser module graph uses one release version", () => {
  const buildId = workerSource.match(/const BUILD_ID = "([^"]+)";/)?.[1];
  assert.ok(buildId, "The service worker must declare a build ID.");

  assert.match(indexHtml, new RegExp(`styles\\.css\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`main\\.js\\?v=${buildId}`));
  assert.match(indexHtml, new RegExp(`sw\\.js\\?v=\\$\\{buildId\\}`));
  assert.match(mainSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`game-engine\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`sound-controller\\.js\\?v=${buildId}`));
  assert.match(mainSource, new RegExp(`leaderboard-model\\.js\\?v=${buildId}`));
  assert.match(engineSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(workerSource, new RegExp(`sound-controller\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, new RegExp(`leaderboard-model\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, /\.\/assets\/disco-concrete\.png/);
  assert.match(workerSource, /\.\/assets\/disco-tile-overlay\.png/);

  const appShell = workerSource.match(/const APP_SHELL = \[([\s\S]*?)\];/)?.[1] ?? "";
  assert.doesNotMatch(appShell, /assets\/audio|\.mp3/);
  assert.match(
    workerSource,
    /pathname\.startsWith\("\/assets\/audio\/"\)[\s\S]*fetch\(event\.request, \{ cache: "no-store" \}\)/
  );
  assert.match(soundSource, /function ensureAudio\(\)\s*\{\s*if \(!enabled\) return \[\]/);
  assert.match(soundSource, /unlock\(\)\s*\{\s*if \(!enabled\) return;\s*const sounds = ensureAudio\(\)/);
  assert.doesNotMatch(mainSource, /new Audio\s*\(/);
  assert.match(workerSource, /fetch\(request, \{ cache: "no-store" \}\)/);
});

test("the streamlined dialog contains settings, leaderboard, and reaction statistics", () => {
  assert.doesNotMatch(indexHtml, /Mechanics prototype/i);
  assert.match(indexHtml, /<html lang="en" data-theme="classic" data-glyphs="on">/);
  assert.doesNotMatch(indexHtml, /id="phase"|id="hint"|id="rules"/);
  assert.doesNotMatch(indexHtml, /player-profile|player profile|for this device/i);
  assert.match(indexHtml, /id="result-stats"/);
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
  assert.match(indexHtml, /id="score-form"/);
  assert.match(indexHtml, /id="player-name"/);
  assert.doesNotMatch(indexHtml, /id="themes-toggle"|id="themes-panel"/);
  assert.match(indexHtml, /id="settings-toggle"[^>]+aria-controls="settings-panel"/s);
  assert.match(indexHtml, /id="settings-panel" hidden/);

  const settingsPanel = indexHtml.match(
    /<fieldset class="settings-panel" id="settings-panel" hidden>[\s\S]*?<\/fieldset>/
  )?.[0];
  assert.ok(settingsPanel, "Settings panel must be present.");
  assert.match(settingsPanel, /name="theme" value="classic" checked/);
  assert.match(settingsPanel, /name="theme" value="disco"/);
  assert.match(settingsPanel, /id="color-blind-toggle"[^>]+role="switch" checked/);
  assert.match(settingsPanel, /id="sound-fx-toggle"[^>]+role="switch" checked/);
  assert.match(indexHtml, /id="leaderboard-toggle"/);
  assert.ok(
    indexHtml.indexOf('id="settings-toggle"') < indexHtml.indexOf('id="leaderboard-toggle"'),
    "Settings must appear above Leaderboard."
  );

  assert.match(mainSource, /speedytapper\.theme\.v1/);
  assert.match(mainSource, /speedytapper\.colorBlindMode\.v1/);
  assert.match(mainSource, /speedytapper\.soundFx\.v1/);
  assert.match(mainSource, /settingsToggle/);
  assert.match(mainSource, /settingsPanel/);
  assert.match(mainSource, /settingsCurrent/);
  assert.match(mainSource, /soundFxToggle/);
  assert.match(mainSource, /sound\.setEnabled\(soundFxEnabled\)/);
  assert.match(
    mainSource,
    /function startGame\(mode\)\s*\{\s*clearTimers\(\);\s*sound\.unlock\(\);/,
    "Run cleanup must finish before the gesture-based audio unlock starts."
  );
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
  assert.doesNotMatch(mainSource, /PLAYER_NAME_KEY|PROFILE_SCORE_PREFIX|Profile best|My best/);
});
