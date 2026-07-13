(() => {
  let theme = "classic";
  let glyphs = "on";
  try {
    const storedTheme = window.localStorage.getItem("speedytapper.theme.v1");
    if (storedTheme === "classic" || storedTheme === "disco") theme = storedTheme;
    if (window.localStorage.getItem("speedytapper.colorBlindMode.v1") === "off") {
      glyphs = "off";
    }
  } catch {
    // Default display settings remain available when storage is restricted.
  }
  document.documentElement.dataset.theme = theme;
  document.documentElement.dataset.glyphs = glyphs;
  document.querySelector('meta[name="theme-color"]').content =
    theme === "disco" ? "#050606" : "#0b0d18";
})();
