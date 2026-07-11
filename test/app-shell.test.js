import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const [indexHtml, mainSource, engineSource, workerSource, stylesSource] = await Promise.all([
  readFile(new URL("../index.html", import.meta.url), "utf8"),
  readFile(new URL("../src/main.js", import.meta.url), "utf8"),
  readFile(new URL("../src/game-engine.js", import.meta.url), "utf8"),
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
  assert.match(mainSource, new RegExp(`leaderboard-model\\.js\\?v=${buildId}`));
  assert.match(engineSource, new RegExp(`config\\.js\\?v=${buildId}`));
  assert.match(workerSource, new RegExp(`leaderboard-model\\.js\\?v=\\$\\{BUILD_ID\\}`));
  assert.match(workerSource, /\.\/assets\/disco-concrete\.png/);
  assert.match(workerSource, /\.\/assets\/disco-tile-overlay\.png/);
  for (const audioAsset of ["oops", "fluorescent-hum"]) {
    assert.match(workerSource, new RegExp(`\\.\\/assets\\/audio\\/${audioAsset}\\.mp3`));
  }
  assert.doesNotMatch(workerSource, /relay-off\.mp3/);
  assert.doesNotMatch(mainSource, /relayOff|relay-off\.mp3/);
  assert.match(mainSource, /unlock\(\)\s*{\s*for \(const audio of sounds\)/);
  assert.match(workerSource, /fetch\(request, \{ cache: "no-store" \}\)/);
});

test("the streamlined dialog contains player, leaderboard, and reaction statistics", () => {
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
  assert.match(indexHtml, /id="response-rails"/);
  assert.match(indexHtml, /id="score-form"/);
  assert.match(indexHtml, /id="player-name"/);
  assert.match(indexHtml, /id="themes-toggle"/);
  assert.match(indexHtml, /id="themes-panel"/);
  assert.match(indexHtml, /name="theme" value="classic" checked/);
  assert.match(indexHtml, /name="theme" value="disco"/);
  assert.match(indexHtml, /id="settings-toggle"[^>]+aria-controls="settings-panel"/s);
  assert.match(indexHtml, /id="settings-panel" hidden/);
  assert.match(
    indexHtml,
    /<fieldset class="settings-panel" id="settings-panel" hidden>[\s\S]*id="color-blind-toggle"[^>]+role="switch" checked[\s\S]*<\/fieldset>/
  );
  assert.match(indexHtml, /id="color-blind-toggle"[^>]+role="switch" checked/);
  assert.match(indexHtml, /id="leaderboard-toggle"/);
  assert.ok(
    indexHtml.indexOf('id="themes-toggle"') < indexHtml.indexOf('id="settings-toggle"') &&
      indexHtml.indexOf('id="settings-toggle"') < indexHtml.indexOf('id="leaderboard-toggle"'),
    "Themes and Settings must appear above Leaderboard."
  );
  assert.match(mainSource, /speedytapper\.theme\.v1/);
  assert.match(mainSource, /speedytapper\.colorBlindMode\.v1/);
  assert.match(mainSource, /settingsToggle/);
  assert.match(mainSource, /settingsPanel/);
  assert.match(mainSource, /settingsCurrent/);
  assert.match(mainSource, /glyph\.textContent = colorBlindMode \? color\.glyph : ""/);
  assert.doesNotMatch(mainSource, /responseRails\.style\.setProperty\(\s*"--player-color"/);
  assert.doesNotMatch(stylesSource, /\.response-rails\s*\{[^}]+--player-color/s);
  assert.doesNotMatch(stylesSource, /\.response-rail(?:__fill)?\s*\{[^}]+var\(--player-color\)/s);
  assert.match(
    stylesSource,
    /\.response-rail\s*\{[^}]+background:\s*rgba\(255,\s*255,\s*255,\s*0\.12\)/s
  );
  assert.match(
    stylesSource,
    /\.response-rail__fill\s*\{[^}]+background:\s*rgba\(255,\s*255,\s*255,\s*0\.6\)/s
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
