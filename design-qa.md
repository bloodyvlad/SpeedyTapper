# SpeedyTapper Disco Design QA

- Source visual truth: `/Users/vlad/.codex/generated_images/019f510d-4841-75d2-9191-0fb9e6446b8d/exec-022cf69e-b568-4f4d-ae68-9b1c8136a0b9.png`
- Latest production Settings screenshot: `/tmp/speedytapper-20260711-5-settings.png`
- Latest production gameplay screenshot: `/tmp/speedytapper-20260711-5-timer.png`
- Viewport: 390 × 844
- Production release: `20260711-6`
- Local candidate: `20260712-1` (implemented, not deployed)
- Last visually verified production release: `20260711-5`
- Last visually verified local release: `20260712-1`
- Target state: main menu with Settings expanded, Disco selected, and Sound FX disabled; Normal gameplay with the relocated response timer active

## Release 20260711-5 Implementation Review

- Settings hierarchy: the standalone Themes control is removed. Classic and Disco now live at the top of the single Settings panel, followed by the Color-blind mode and Sound FX switches. Leaderboard remains a separate control below Settings.
- Settings summary: the collapsed row identifies the selected theme and whether sound is on or off without exposing another top-level menu row.
- Colors and visual tokens: Classic remains unchanged. Disco retains the approved pale icy-cyan, butter-yellow, rose-pink, spring-lime, pale-apricot, and lavender palette with dark, high-contrast glyph ink.
- Image quality and asset fidelity: the generated concrete and plastic-wear assets remain in use. Black concrete repeats across the page, board, and menu surface; subtle marks and scratches cover idle and lit Disco tiles.
- Accessibility: Color-blind mode remains enabled by default. Its native checkbox with `role="switch"` removes glyphs from the HUD, live tiles, and theme previews when disabled. Both theme options remain native radios.
- Sound: Sound FX is enabled by default and persisted independently. Audio objects and requests are created lazily only after an enabled player gesture. Turning sound off releases loaded media and makes every sound hook a no-op; MP3 files are not in the service-worker app shell, and audio requests explicitly bypass caches.
- Gameplay feedback: the two full-height side rails are removed. A single neutral 60%-white response bar is nested at the bottom of the **Your color** field and drains horizontally with `scaleX`, keeping motion near the information it explains.

## Verification

- `node --test test/app-shell.test.js test/sound-controller.test.js`: 6 tests passed.
- Static coverage confirms one `20260711-5` module graph, themes nested inside Settings, both switches default checked, no standalone Themes panel, no side rails, the nested neutral response bar, no MP3s in `APP_SHELL`, and uncached audio fetches.
- Sound-controller coverage confirms disabled audio creates, loads, and plays nothing; enabled audio stays lazy until unlock; disabling sound releases existing media before all hooks become no-ops; and a pending Safari-style unlock cannot revive audio after opt-out.
- Production at `https://speedytapper.vercel.app` was verified at 390 × 844. The consolidated Settings panel fits without clipping, its `Disco · Sound off` summary persisted after reload, no side rails exist, and the neutral response timer drains inside the color field without obscuring its swatch or label.
- Fresh evidence is saved at `/tmp/speedytapper-20260711-5-settings.png` and `/tmp/speedytapper-20260711-5-timer.png`.

## Prior Local Candidate 20260711-6

- Added a compact SpeedyTapper identity row above the HUD with accessible 44px Restart and Main menu controls. The utility row stays hidden on menus and result screens.
- Added a full-width Restart button directly below the Game Over name entry; both restart controls preserve the current Normal or Zen mode.
- Removed the 65ms dark-to-color transition so Classic targets display their vivid palette on the first reaction frame. Classic keeps a clear solid-plastic treatment without Disco wear or pale center lighting.
- Disco retains its separate pale palette, adds an explicit bright center, keeps the scratched-plastic overlay, and exposes more of the black concrete texture on the menu, dialog, page, and board frame.
- `npm run check`: 40 tests passed. `git diff --check` passed.
- Browser verification passed at 390 × 844 and 320 × 568. Both restart paths, the in-game menu shortcut, Classic targets, Disco targets, narrow toolbar fit, and concrete visibility were exercised locally.
- Local evidence: `/tmp/speedytapper-local-menu-20260711-6.png`, `/tmp/speedytapper-local-classic-20260711-6.png`, `/tmp/speedytapper-local-disco-20260711-6.png`, and `/tmp/speedytapper-local-compact-20260711-6.png`.
- Vercel production was intentionally left on `20260711-5`.

## Local Candidate 20260712-1 Implementation

- Sound FX defaults to off. The Settings summary initially reads `Classic · Sound off`, and the native Sound FX switch is unchecked when no preference has been stored.
- The Sound FX row contains a visible **Beta** label so players understand that audio remains experimental.
- Enabling sound remains an explicit opt-in stored on that device. With sound off, the app must not create an audio context or fetch, decode, cache, or play either sound file.
- Sound uses the standards-based, unprefixed Web Audio API through `globalThis.AudioContext`; it must not depend on `webkitAudioContext`, `HTMLAudioElement`, `new Audio()`, or an Apple-only interface.
- Enabled sound uses an interactive-latency context and asynchronously decoded in-memory buffers. One persistent silent hum loop is gated with 10ms gain ramps, and life-loss cues use fresh one-shot sources. A user gesture resumes playback; backgrounding suspends it; disabling closes the context and releases active sources.
- Audio files remain outside `APP_SHELL` and are fetched with `cache: "no-store"`. No `<audio>` element or audio preload hint is present in the document.
- All HTML, CSS, JavaScript imports, the service worker, and the manifest share release ID `20260712-1`.
- Events that occur before decoding or resume finishes are skipped rather than played late. Unsupported, failed, or blocked audio remains silent without affecting gameplay.
- The hum now uses a sample-seamless 48 kHz PCM loop, preventing a second click source at the persistent two-second loop boundary. Failed parallel preparation aborts and settles its sibling before retry is possible.
- `npm run check`: 51 tests passed. `git diff --check` passed.
- Local browser verification passed at 390 × 844 and 320 × 568. The Beta badge fits, Sound FX defaults off, explicit opt-in starts and retains Web Audio without runtime errors, and opt-out persists after reload.
- Local evidence: `/tmp/speedytapper-web-audio-settings-20260712-1.png` and `/tmp/speedytapper-web-audio-compact-20260712-1.png`.
- Vercel production remains on `20260711-6`.

## Comparison History

- Initial evidence: `/tmp/speedytapper-disco-menu.png`. One P3 polish item made the concrete too subdued behind the modal.
- Earlier fix: reduced the Disco overlay opacity and blur so the black painted concrete reads more clearly without competing with controls.
- Release `20260711-4`: strengthened the black-concrete treatment, extended light wear to every Disco tile state, moved Color-blind mode into Settings, neutralized the side timing rails, and removed the delayed switch-off cue. Evidence is captured in `/tmp/speedytapper-disco-20260711-4-settings.png` and `/tmp/speedytapper-disco-20260711-4-board.png`.
- Release `20260711-5`: consolidates theme, accessibility, and sound controls in Settings; adds strict lazy/disabled audio behavior; and replaces the distracting side rails with one response bar inside the color field.

## Previous Browser Interactions Tested

- Expanded and collapsed Settings on production release `20260711-4`.
- Switched between Classic and Disco.
- Disabled and re-enabled Color-blind mode.
- Confirmed preview glyphs become hidden when disabled.
- Confirmed active gameplay tiles and the HUD omit glyphs when disabled.
- Reloaded and confirmed theme and glyph settings persist.

## Release 20260711-5 Browser Checklist

- [x] Expand Settings and confirm both theme previews and both switches fit at 390 × 844 without clipping.
- [x] Confirm the Settings summary updates after changing theme or Sound FX.
- [x] Confirm Color-blind mode remains present inside Settings.
- [x] Disable Sound FX before starting a run; the strict no-audio path is enforced by controller and service-worker tests because this browser harness does not expose network resource entries.
- [x] Start Normal mode and confirm one horizontal timing bar drains inside **Your color** with no side rails.
- [x] Confirm the progress bar does not obscure the color name or swatch at narrow widths.
- [x] Reload and confirm the selected theme and Sound FX setting persist.
- [x] Capture fresh Settings and gameplay screenshots after production deployment.

## Release 20260711-5 Implementation Checklist (Historical)

- [x] One Settings control above Leaderboard.
- [x] Classic and Disco selector nested inside Settings.
- [x] Persisted Color-blind mode switch under Settings, default on.
- [x] Persisted Sound FX switch under Settings, default on.
- [x] No eager audio construction, load, request, or playback while sound is disabled.
- [x] Loaded media released immediately when Sound FX is disabled.
- [x] Audio files excluded from the offline app shell and fetched with `cache: "no-store"`.
- [x] Two full-height side rails removed.
- [x] Single neutral response-time bar nested in the color field.
- [x] Classic default and persisted Disco selection.
- [x] Paler Disco palette, repeating black concrete, and scratched plastic tiles retained.
- [x] Glyph removal from previews, HUD, and gameplay retained.
- [x] Unified `20260711-5` release version and automated static coverage.
- [x] Fresh production browser screenshots and interaction check for `20260711-5`.

## Candidate 20260712-1 Checklist

- [x] Unified `20260712-1` release graph.
- [x] Sound FX switch unchecked and Sound off summary by default.
- [x] Beta label visible inside the Sound FX setting.
- [x] Persisted opt-in only when the stored preference is exactly `on`.
- [x] Standards-based `globalThis.AudioContext` with `latencyHint: "interactive"`.
- [x] Decoded `AudioBuffer` assets, persistent ramped hum, and one-shot life-loss sources.
- [x] No `webkitAudioContext`, `HTMLAudioElement`, `new Audio()`, or audio DOM element.
- [x] No audio files in the service-worker app shell or HTML preload hints.
- [x] No context creation, fetch, decode, or playback while Sound FX is off.
- [x] Suspend on background and close/release on opt-out.
- [x] Automated sound-controller and static release tests passing.
- [x] Fresh 390 × 844 and 320 × 568 Settings/browser verification.

## Follow-up Polish

- No implementation blocker is recorded. The generated PNG textures add about 2.2 MB to the offline cache; a future asset-optimization pass could convert them to WebP if install size becomes important.

final result: local candidate `20260712-1` passed; production remains `20260711-6`
