export const THEME_CATALOG = Object.freeze([
  Object.freeze({ id: "classic", name: "Default", priceCoins: 0 }),
  Object.freeze({ id: "disco", name: "Disco", priceCoins: 0 }),
  Object.freeze({ id: "light", name: "Light", priceCoins: 50 }),
  Object.freeze({ id: "pixel", name: "Pixel", priceCoins: 100 })
]);

const THEMES_BY_ID = new Map(THEME_CATALOG.map((theme) => [theme.id, theme]));

export function getTheme(themeId) {
  return THEMES_BY_ID.get(themeId) ?? null;
}

export function isThemeId(themeId) {
  return getTheme(themeId) !== null;
}

export function normalizeOwnedThemeIds(themeIds) {
  const owned = new Set(["classic", "disco"]);
  if (Array.isArray(themeIds)) {
    for (const themeId of themeIds) {
      if (isThemeId(themeId)) owned.add(themeId);
    }
  }
  return THEME_CATALOG.map(({ id }) => id).filter((themeId) => owned.has(themeId));
}

export function resolveThemeShopAction({ owned, selected }) {
  if (selected) return "Selected";
  return owned ? "Select" : "Buy";
}
