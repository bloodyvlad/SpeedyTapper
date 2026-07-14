# SpeedyTapper Disco Design QA

- Source visual truth: `/Users/vlad/.codex/generated_images/019f510d-4841-75d2-9191-0fb9e6446b8d/exec-022cf69e-b568-4f4d-ae68-9b1c8136a0b9.png`
- Latest production Settings screenshot: `/tmp/speedytapper-20260711-5-settings.png`
- Latest production gameplay screenshot: `/tmp/speedytapper-20260711-5-timer.png`
- Viewport: 390 × 844
- Production release: `20260712-1`
- Local candidate: `20260712-2` (implemented, not deployed)
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

## Local Candidate 20260712-2 Audio QA

- A validated leaderboard name is remembered only to prefill the next result form. It creates no profile, personal best, or local score history.
- Enabling Sound FX calls `AudioContext.resume()` directly from the trusted Settings change gesture. Every Start and Restart gesture performs the same synchronous unlock path.
- The master output starts closed and fades in after a successful resume. Unmanaged iOS interruptions close both audio gates before resuming, while lifecycle epochs prevent a stale pending resume from reopening audio after suspension.
- The persistent hum now uses smooth target automation rather than linear gain corners. Life-loss cues are capped at one, cleared on suspension, and decay for eight time constants to an exact scheduled zero before a quick restart stops their source.
- `npm run check`: 58 tests passed. `git diff --check` passed.
- Local browser interaction passed: Sound FX opted in without warnings, Normal mode started through the gesture unlock, three timed misses reached Game Over, and immediate Restart produced no runtime warning or error.
- The supplied seven-second iPhone recording came from the earlier media-element release. Its waveform contains broadband transient spikes, but it cannot directly validate the new Web Audio build on physical iPhone hardware.

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

final result: local candidate `20260712-2` passed automated and browser-runtime QA; production remains `20260712-1` pending deployment

## Local Candidate 20260713-14 Misha Easter Egg QA

- This is local candidate evidence only. It does not establish the current production deployment and no deployment was performed.
- A confirmed authenticated nickname matching `misha_boy` after Unicode normalization, trimming, and case-folding reveals the decorative Misha sprite. Renaming to `someone_else` hid Misha immediately; saving `Misha_Boy` revealed him immediately through a local mocked profile session.
- Misha remains below the Profile shortcut on the main menu and at the same dialog-relative position on the Profile screen. During a run, the menu instance hides and the gameplay instance appears above the Speed streak meter without overlapping or intercepting the board.
- Accepted taps on the left and right sides of the board changed the gameplay pose to `left` and `right`. The in-game Restart control reset the pose to `front` for the new run.
- Classic and Disco gameplay were inspected at 390 × 844. Compact layouts passed at 320 × 568 portrait and 568 × 320 landscape with no horizontal overflow, board overlap, or clipped streak meter.
- The eight-frame sprite is a transparent 256 × 32 PNG rendered at 32px or 64px with pixelated sampling. The left-turn frame progression was inspected during the 300ms stepped animation; no wrong-way flick was visible.
- `npm run check`: 125 JavaScript tests passed and the PHP backend suite passed 74 assertions. `git diff --check` passed, and the release graph contains no stale `20260713-13` references in the version-bearing app files.
- Local evidence: `/tmp/speedytapper-20260713-14-misha-menu.jpg`, `/tmp/speedytapper-20260713-14-misha-profile.jpg`, `/tmp/speedytapper-20260713-14-misha-game.jpg`, `/tmp/speedytapper-20260713-14-misha-compact.jpg`, `/tmp/speedytapper-20260713-14-misha-landscape.jpg`, and `/tmp/speedytapper-20260713-14-misha-disco.jpg`.
- Physical iPhone Safari and installed-PWA/offline relaunch testing remain required before this touch-driven presentation change can be described as production-validated.

final result: local candidate `20260713-14` passed automated and desktop browser-runtime QA; it is not deployed and still requires physical iPhone validation

## Local Candidate 20260713-15 Arcade and Misha Refinement QA

- This is local candidate evidence only. It does not establish the current production deployment, and no deployment was performed.
- Player-facing mode copy now reads **Arcade** in mode selection, Profile, Leaderboard, and result actions. The engine, persistence, database, leaderboard, and API continue to use `normal` for compatibility.
- The `misha_boy` easter egg shows the white climber and light-blue pouch only on the main menu. Profile, Settings, Leaderboard, and result views retain Misha alone at the upper-right anchor.
- Misha remained awake through 4,999ms of non-game inactivity and switched to the sleeping frame at 5,000ms in deterministic controller coverage. A left- or right-side screen tap woke him, turned him toward that side, and replaced the idle deadline. Repeated profile-session renders preserved his current pose and deadline; gameplay cancelled the timer and stale callbacks could not sleep the hidden cat.
- The gameplay composition was raised until the top 12px of the 64px sprite overlapped the board at 390 × 844, leaving only Misha's ears and upper head inside the playfield. The decorative layers remain pointer-transparent. Compact checks at 375 × 548 and 320 × 568 used the 48px sprite with approximately 7px of board overlap, and the streak meter remained fully visible.
- Classic and Disco main-menu states were inspected awake and asleep. Profile, Settings, Leaderboard, result, gameplay, narrow portrait, and 568 × 320 landscape states were also exercised locally without horizontal overflow or clipped controls.
- `npm run check`: 128 JavaScript tests passed and the PHP backend suite passed 74 assertions. `git diff --check` passed, and the version-bearing release graph contains no stale `20260713-14` references.
- Local evidence: `/tmp/speedytapper-20260713-15-misha-main-awake.jpg`, `/tmp/speedytapper-20260713-15-misha-main-sleep.jpg`, `/tmp/speedytapper-20260713-15-misha-profile.jpg`, `/tmp/speedytapper-20260713-15-misha-game.jpg`, `/tmp/speedytapper-20260713-15-misha-se-menu.jpg`, `/tmp/speedytapper-20260713-15-misha-se-game.jpg`, `/tmp/speedytapper-20260713-15-misha-narrow-menu.jpg`, `/tmp/speedytapper-20260713-15-misha-narrow-game.jpg`, and `/tmp/speedytapper-20260713-15-misha-landscape.jpg`.
- Physical iPhone Safari and installed-PWA/offline relaunch testing remain required before the touch, compact sizing, and overlap behavior can be described as production-validated.

final result: local candidate `20260713-15` passed automated and desktop browser-runtime QA; it is not deployed and still requires physical iPhone validation

## Local Candidate 20260713-16 Pet Shop QA

- This is local candidate evidence only. It does not establish the current production deployment, and no deployment was performed.
- Pet Shop appears immediately above Settings with Foka/10, Kesha/20, Tauta/50, Misha/100, and Pancake/500. A local same-origin profile mock verified that buying Pancake reduced 1,000 coins to 500 and equipped him immediately; changing back to already-owned Misha kept the balance at 500. The equipped card used a non-button state, and every other action remained exactly Buy or Change.
- Five ten-cell 320×32 transparent sprite sheets and four 64×48 two-layer habitat sheets were inspected. Foka stays prone, Kesha's endpoint hangs upside-down, Tauta and Misha include the intermediate rising/crouched frame, and full/half direction bands are deterministic. Main-menu homes disappear on other screens and in gameplay; the shop icon viewport now contains the tall Misha climber without overlapping the following Pancake card.
- Pancake uses the exact upright and down poses extracted from the supplied recording, with only a CSS horizontal mirror for left-facing taps. Its platform is a two-pixel horizontal glow line in the menu, shop, and game—not a floor tile. Browser interaction showed the upright body, black limbs, thin line, and `stopped` controller state after five seconds without a tap.
- Misha began the intermediate settling transition at the five-second idle deadline, reached the sleeping endpoint after the 450ms stepped transition, and woke with `facing: left` from a left-side screen tap. A second tap selected the persistent `half-left` direction band. The full white climber and light-blue pouch remained main-menu-only.
- Classic and Disco were inspected at 390×844. Short-phone checks passed at 375×548 and 320×568 with the full 64px companion retained; the earlier 48px compact regression is removed. Gameplay at 375×548 and 568×320 kept only the pet's top edge inside the non-interactive board frame while leaving the full streak bar and controls available. The Pet Shop scrolled cleanly at every portrait size.
- Leaderboard browser evidence showed the equipped Pancake as a 32px profile picture beside the current player's ranked row. Changing selection updated the mocked public entry without changing historical run data. Browser console inspection returned no warnings or errors.
- `npm run check`: 132 JavaScript tests passed and the PHP backend suite passed 85 assertions. Composer used its local advisory cache after a transient Packagist connection reset and reported no known vulnerabilities. The release graph uses `20260713-16` and includes every pet sprite and habitat while excluding audio and any Pancake tile asset.
- Local evidence: `/tmp/speedytapper-20260713-16-se-menu.png`, `/tmp/speedytapper-20260713-16-se-shop.png`, `/tmp/speedytapper-20260713-16-misha-game.png`, `/tmp/speedytapper-20260713-16-pancake-game.png`, `/tmp/speedytapper-20260713-16-leaderboard-pet.png`, `/tmp/speedytapper-20260713-16-disco-shop.png`, `/tmp/speedytapper-20260713-16-misha-sleep.png`, and `/tmp/speedytapper-20260713-16-landscape-game.png`.
- Physical iPhone Safari and installed-PWA/offline relaunch testing remain required. Pancake's recording was supplied by the user, but independent redistribution clearance for its online source has not been established; confirm it before a public production release.

final result: local candidate `20260713-16` passed automated and desktop browser-runtime QA; it is not deployed and still requires physical iPhone and Pancake-rights validation

## Integrated Candidate 20260714-1 Automated QA

- This entry records automated integration evidence only; it is not proof of the active production deployment or physical-iPhone behavior.
- Anti-cheat run proofs, uniform Interactive Music cues, persistent adaptive three-minute Zen, six achievements, and the five-pet shop are combined in one release graph.
- The **Buy a pet** achievement is unlocked by the committed server purchase transaction after debit and ownership creation, never by the browser button click. Failed and repeated owned-pet selections do not qualify.
- Economy reconciliation retains pet debits and achievement rewards and represents spent revoked earnings as debt, preventing moderation from restoring purchased coins.
- `npm run check` passed on the unified `20260714-1` graph with 151 JavaScript tests and 116 PHP assertions. Fresh MariaDB 11.4 checks also passed for migrations `001`–`009`, idempotent reruns, native-prepared atomic purchase/unlock, duplicate-safe achievement claims, legacy wallet preservation, and moderation debt reconciliation. A clean-commit check and Hostinger smoke test are still required.
- Local browser smoke passed for the main menu, all six signed-out achievement cards, all five Pet Shop cards, compact 320×568 horizontal fit, Arcade start, timed life loss, Game Over, and navigation. The static development server intentionally had no profile API; authenticated Google and purchase UI remain production-smoke items.
- Physical iPhone Safari/PWA touch, audio balance, Google sign-in, pet layout, and upgrade-cache validation remain required before this release is described as device-validated.

## Local Candidate 20260714-2 Pet Position QA

- This is local candidate evidence only. It does not establish the active Hostinger deployment, and no deployment was performed.
- Pet Shop sprites are raised 8 px inside their existing clipped preview buttons; menu sprites are raised 4 px. Both selectors are context-specific, so `.pet-scene--game` keeps its approved placement.
- Direction is measured from the visible pet sprite rather than viewport or board bands. Centered taps keep front, left/right angles through 30 degrees keep the half-turn frame after animation completes, and wider angles select the full turn. Pancake remains binary left/right.
- Local browser inspection passed at the 2022 iPhone SE CSS viewport of 375×667 and the compact 320×568 regression viewport. Foka remained on its ice floe, Kesha on the perch, Tauta inside the bed, Misha against the climber, and Pancake above the glow line without card overlap or horizontal overflow.
- `npm run check` passed on the unified `20260714-2` graph with 151 JavaScript tests and 116 PHP assertions; `git diff --check` also passed.
- Local evidence: `/tmp/speedytapper-20260714-2-shop-375x667.png`, `/tmp/speedytapper-20260714-2-shop-320x568.png`, and `/tmp/speedytapper-20260714-2-shop-misha-320x568.png`.
- Physical iPhone Safari and installed-PWA confirmation remains required, especially for the menu baseline and real touch-angle feel.

## Local Candidate 20260714-3 Pet Shop Controls QA

- This is local candidate evidence only. It does not establish the active Hostinger deployment, and no deployment was performed.
- The utility header shows the full localized coin label and remains on one line at 320×568. Pet Shop repeats the balance in its top-right heading, removes habitat names, lets the longer Kesha kind wrap, enlarges prices, and uses compact Buy/Select/Hide/Show actions.
- Deterministic action coverage confirms the four requested states. Server state keeps ownership and the selected pet while persisting visibility separately; a hidden pet is omitted from menu/game rendering and leaderboard portraits, while selecting or buying a pet shows it.
- Browser inspection passed at 320×568 and the 2022 iPhone SE CSS viewport of 375×667 without horizontal overflow. Foka and Kesha use the requested additional shop offsets, Misha's computed sprite z-index is above the climber, and Pancake's two 4px dark eyes remain readable at the shipped 64px rendering. Pancake's stepped sprite and glow cycles now last 1,440ms instead of 720ms.
- `npm run check` passed on the unified `20260714-3` graph with 152 JavaScript tests and 118 PHP assertions; Composer validation/audit, every PHP syntax check, and `git diff --check` also passed. Browser console inspection returned no errors.
- Local evidence: `/tmp/speedytapper-20260714-3-main-320x568.png`, `/tmp/speedytapper-20260714-3-shop-320x568.png`, `/tmp/speedytapper-20260714-3-shop-375x667.png`, and `/tmp/speedytapper-20260714-3-pancake-320x568.png`.
- Physical iPhone Safari and installed-PWA confirmation remains required for touch feel, live pet switching, animation pacing, migration upgrade behavior, and cache refresh before this candidate is described as device-validated.

## Local Candidate 20260714-4 Auth Gating and Leaderboard Administration QA

- This is local candidate evidence only. It does not establish the active Hostinger deployment, and no deployment was performed.
- Signed-out utility coins, Achievements, and Pet Shop are visibly gated. Attempting any gated control presents the same Google-login benefits notice, and the signed-out Profile repeats that explanation. Achievement cards no longer show an **In progress** label and display rewards with a gold coin icon.
- Non-game pet portraits include their habitat layers in menu surfaces and leaderboard rows; gameplay continues to render the pet alone. Pet Shop labels its balance with **Your balance:** above the yellow localized coin count.
- Leaderboard administration is exposed only when the authenticated server profile carries the database-backed admin role. The responsive review UI separates all results from scan findings, supports mode/status filters and pagination, requires evidence review and a reason before quarantine, and requires a second explicit confirmation before deleting a quarantined result and resetting that account's pets and coin economy.
- The one-time admin migration is bound to the exact known Arcade and Zen result UUIDs and score evidence, not nickname, email, Google data, or client state. Live MariaDB deployment must confirm that this evidence grants exactly one role before administration is used.
- Browser inspection passed the signed-out main menu, benefits notice, Profile, and live Arcade layout at 320×568 with no horizontal overflow or console errors. Authenticated Profile, Pet Shop, leaderboard habitat portraits, and administration require a live authenticated production smoke because the static development server intentionally has no PHP/MySQL session.
- `npm run check` passed on the unified `20260714-4` graph with 157 JavaScript tests and 133 PHP assertions; Composer validation/audit and every PHP syntax check passed. `git diff --check` also passed.
- Physical iPhone Safari and installed-PWA confirmation remains required, along with a live MariaDB migration/authentication/moderation smoke, before this candidate is described as production-validated.

## Local Candidate 20260714-5 Endless Zen and Moderation Refinement QA

- This is local candidate evidence only. It does not establish the active Hostinger deployment, and no deployment was performed.
- Zen is an endless local practice mode with unlimited lives, no decoys, no server run proof, no leaderboard submission, and no coin award. Its menu button is labelled **Zen** with the explicit note **No coins awarded**; Restart starts a new practice run and Main menu discards the current run.
- Leaderboard administrators may now moderate results belonging to themselves or another administrator. The role check, quarantine-before-delete workflow, exact confirmation, recent-auth requirement, audit record, and account-economy reset remain in force. Default administration queries exclude deleted results; deleted rows appear only through the explicit **Deleted** filter.
- The utility coin control is restored to a compact 44×44 square with one crisp pixel-art gold coin and a numeric badge at its bottom-right corner. The existing signed-out gating and benefits notice remain unchanged.
- `npm run check` passed with 155 JavaScript tests and 137 PHP assertions, including release-graph, endless-Zen, no-decoy, no-reward, moderation, and explicit-deleted-filter coverage. Composer validation/audit and every PHP syntax check passed.
- Local browser inspection passed at 375×667 and 320×568. The compact utility header and two-line Zen button have no horizontal overflow; the narrow main menu scrolls to Settings and the copyright footer; live Zen displays elapsed time, one lit target, and ∞ lives. Static coverage confirms that the historical ranked Zen top score is omitted from the practice HUD. Browser console inspection returned no warnings or errors.
- Local evidence: `/tmp/speedytapper-20260714-5-menu-375.png`, `/tmp/speedytapper-20260714-5-zen-375.png`, and `/tmp/speedytapper-20260714-5-menu-320.png`.
- Physical iPhone Safari and installed-PWA confirmation remains required, along with live authenticated moderation and MariaDB filtering smoke tests, before this candidate is described as production-validated.

## Local Candidate 20260714-11 Theme Shop and Theme Audio QA

- This is local candidate evidence only. It does not establish the active Hostinger deployment, and no deployment was performed.
- Theme Shop renders Default, Disco, Light/50 coins, and Pixel/100 coins in two columns. Cards keep only names, previews, prices, and Buy/Select/Selected actions; owned prices remain available for greyed presentation.
- Browser inspection passed the shop at 375×667 and 320×568 with no visible horizontal overflow. Free Default/Disco switching worked, and a signed-out paid action routed to Profile rather than applying the paid theme. The Light preview uses white glyphs on all six target colors.
- Default retains its existing music and tone suite. Disco, Light, and Pixel ship distinct twelve-second menu/gameplay pairs and sixteen-cue fixed-slot tone banks with lossless rollback masters, post-encode seam checks, recorded hashes, and provenance. Enabled theme changes retain the already-unlocked Web Audio contexts while replacing only selected-theme assets.
- Pixel self-hosts the unmodified OFL-licensed Pixelify Sans variable font and includes it in the offline app shell. Light uses near-white panels, two thin CSS clouds, white board gaps/borders, and white gameplay glyphs; Pixel uses hard edges, stepped shadows, and an arcade grid.
- `npm run check` passed with 157 JavaScript tests and 148 PHP assertions; Composer validation/audit, PHP syntax checks, and `git diff --check` also passed.
- Physical iPhone Safari/PWA validation remains required for paid-theme purchases, font/readability at all views, per-theme audio latency and balance, the Pixel tone-bank timbre, and cache upgrades before this candidate is described as device- or production-validated.
