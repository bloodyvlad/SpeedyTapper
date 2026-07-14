import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  getTheme,
  isThemeId,
  normalizeOwnedThemeIds,
  resolveThemeShopAction,
  THEME_CATALOG
} from "../src/theme-catalog.js";
import { getThemeAudio, normalizeThemeAudioId, THEME_AUDIO } from "../src/theme-audio.js";

test("theme catalog keeps stable free and paid identities", () => {
  assert.deepEqual(THEME_CATALOG, [
    { id: "classic", name: "Default", priceCoins: 0 },
    { id: "disco", name: "Disco", priceCoins: 0 },
    { id: "light", name: "Light", priceCoins: 50 },
    { id: "pixel", name: "Pixel", priceCoins: 100 }
  ]);
  assert.equal(getTheme("classic")?.name, "Default");
  assert.equal(isThemeId("pixel"), true);
  assert.equal(isThemeId("unknown"), false);
});

test("free themes are always owned while paid ownership is normalized", () => {
  assert.deepEqual(
    normalizeOwnedThemeIds(["pixel", "pixel", "unknown"]),
    ["classic", "disco", "pixel"]
  );
  assert.equal(resolveThemeShopAction({ owned: false, selected: false }), "Buy");
  assert.equal(resolveThemeShopAction({ owned: true, selected: false }), "Select");
  assert.equal(resolveThemeShopAction({ owned: true, selected: true }), "Selected");
});

test("every theme has a unique menu, run, and tone suite", () => {
  assert.deepEqual(Object.keys(THEME_AUDIO), THEME_CATALOG.map(({ id }) => id));
  for (const field of ["menuUrl", "runUrl", "toneBankUrl"]) {
    const paths = THEME_CATALOG.map(({ id }) => getThemeAudio(id)[field]);
    assert.equal(new Set(paths).size, THEME_CATALOG.length, `${field} paths must be unique.`);
  }
  assert.equal(normalizeThemeAudioId("light"), "light");
  assert.equal(normalizeThemeAudioId("unknown"), "classic");
  assert.strictEqual(getThemeAudio("unknown"), THEME_AUDIO.classic);
});

test("paid-theme tone banks keep the low-latency fixed-slot contract", async () => {
  const hashes = new Set();
  for (const themeId of ["disco", "light", "pixel"]) {
    const bank = await readFile(
      new URL(`../assets/audio/themes/${themeId}/tap-tones.wav`, import.meta.url)
    );
    hashes.add(createHash("sha256").update(bank).digest("hex"));
    assert.equal(bank.toString("ascii", 0, 4), "RIFF");
    assert.equal(bank.toString("ascii", 8, 12), "WAVE");
    assert.equal(bank.readUInt16LE(22), 1, `${themeId} tones must remain mono.`);
    assert.equal(bank.readUInt32LE(24), 48_000);
    assert.equal(bank.readUInt16LE(34), 16);
    assert.equal(bank.readUInt32LE(40), 16 * 24_000 * 2);

    for (let slot = 0; slot < 16; slot += 1) {
      let sumOfSquares = 0;
      for (let frame = 0; frame < 20_160; frame += 1) {
        const sample = bank.readInt16LE(44 + (slot * 24_000 + frame) * 2) / 32_768;
        sumOfSquares += sample * sample;
      }
      const rms = Math.sqrt(sumOfSquares / 20_160);
      assert.ok(
        Math.abs(rms - 0.204982) < 0.00001,
        `${themeId} slot ${slot + 1} must retain equal nominal energy.`
      );
      const tailStart = 44 + (slot * 24_000 + 20_160) * 2;
      const tailEnd = 44 + (slot + 1) * 24_000 * 2;
      assert.ok(
        bank.subarray(tailStart, tailEnd).every((sampleByte) => sampleByte === 0),
        `${themeId} slot ${slot + 1} must retain its exact 80 ms zero tail.`
      );
    }
  }
  assert.equal(hashes.size, 3, "Each paid-theme tone bank must remain musically distinct.");
});

test("theme music runtimes and rollback masters ship as complete paired assets", async () => {
  const masterNames = {
    disco: "disco-mirror-circuit",
    light: "light-open-sky",
    pixel: "pixel-coin-op-spark"
  };
  for (const [themeId, masterName] of Object.entries(masterNames)) {
    for (const scene of ["background", "menu"]) {
      const runtime = await readFile(
        new URL(`../assets/audio/themes/${themeId}/${scene}.m4a`, import.meta.url)
      );
      assert.ok(runtime.length > 200_000, `${themeId} ${scene} runtime must not be truncated.`);
      assert.equal(runtime.toString("ascii", 4, 8), "ftyp");
    }
    for (const suffix of ["", "-menu"]) {
      const master = await readFile(
        new URL(`../assets/audio/background-masters/${masterName}${suffix}.wav`, import.meta.url)
      );
      assert.equal(master.readUInt16LE(22), 2, `${themeId} masters must remain stereo.`);
      assert.equal(master.readUInt32LE(24), 48_000);
      assert.equal(master.readUInt16LE(34), 16);
      assert.equal(master.readUInt32LE(40), 12 * 48_000 * 2 * 2);
    }
  }
});
