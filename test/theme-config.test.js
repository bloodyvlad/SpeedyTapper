import assert from "node:assert/strict";
import test from "node:test";

import { COLORS, THEMES, THEME_PALETTES } from "../src/config.js";

function rgb(hex) {
  const value = Number.parseInt(hex.slice(1), 16);
  return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

function relativeLuminance(hex) {
  const channels = rgb(hex).map((channel) => {
    const normalized = channel / 255;
    return normalized <= 0.04045
      ? normalized / 12.92
      : ((normalized + 0.055) / 1.055) ** 2.4;
  });
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}

function contrastRatio(first, second) {
  const lighter = Math.max(relativeLuminance(first), relativeLuminance(second));
  const darker = Math.min(relativeLuminance(first), relativeLuminance(second));
  return (lighter + 0.05) / (darker + 0.05);
}

test("all four palettes preserve color semantics", () => {
  const classic = THEME_PALETTES[THEMES.CLASSIC];

  assert.strictEqual(classic, COLORS);
  for (const theme of [THEMES.DISCO, THEMES.LIGHT, THEMES.PIXEL]) {
    const palette = THEME_PALETTES[theme];
    assert.equal(palette.length, classic.length);
    assert.ok(Object.isFrozen(palette));
    for (const [index, themedColor] of palette.entries()) {
      const classicColor = classic[index];
      assert.equal(themedColor.id, classicColor.id);
      assert.equal(themedColor.name, classicColor.name);
      assert.equal(themedColor.glyph, classicColor.glyph);
      assert.equal(themedColor.ink, classicColor.ink);
      assert.notEqual(themedColor.value, classicColor.value);
    }
  }
});

test("Disco colors are paler and retain strong glyph contrast", () => {
  const classic = THEME_PALETTES[THEMES.CLASSIC];
  const disco = THEME_PALETTES[THEMES.DISCO];

  for (const [index, discoColor] of disco.entries()) {
    const classicAverage = rgb(classic[index].value).reduce((sum, channel) => sum + channel, 0) / 3;
    const discoAverage = rgb(discoColor.value).reduce((sum, channel) => sum + channel, 0) / 3;
    assert.ok(discoAverage > classicAverage, `${discoColor.name} should be paler than Classic.`);
    assert.ok(
      contrastRatio(discoColor.value, discoColor.ink) >= 4.5,
      `${discoColor.name} must keep readable glyph contrast.`
    );
  }
});

test("Light and Pixel colors remain distinguishable", () => {
  for (const theme of [THEMES.LIGHT, THEMES.PIXEL]) {
    const palette = THEME_PALETTES[theme];
    assert.equal(new Set(palette.map(({ value }) => value)).size, COLORS.length);
  }

  for (const color of THEME_PALETTES[THEMES.PIXEL]) {
    assert.ok(
      contrastRatio(color.value, color.ink) >= 4.5,
      `Pixel ${color.name} must keep readable glyph contrast.`
    );
  }
});
