import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const [indexHtml, mainSource, engineSource, workerSource] = await Promise.all([
  readFile(new URL("../index.html", import.meta.url), "utf8"),
  readFile(new URL("../src/main.js", import.meta.url), "utf8"),
  readFile(new URL("../src/game-engine.js", import.meta.url), "utf8"),
  readFile(new URL("../sw.js", import.meta.url), "utf8")
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
  assert.match(workerSource, /fetch\(request, \{ cache: "no-store" \}\)/);
});

test("the streamlined dialog contains player, leaderboard, and reaction statistics", () => {
  assert.doesNotMatch(indexHtml, /Mechanics prototype/i);
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
  assert.match(indexHtml, /id="leaderboard-toggle"/);
  assert.doesNotMatch(mainSource, /localStorage|Profile best|My best/);
});
