# PimPoPom

PimPoPom is an installable, offline-capable browser proof of concept for validating the core reaction loop before choosing the architecture of the eventual Steam, mobile, Roblox, or console products. The repository, domain, PHP namespace, storage keys, and compatibility API retain the internal SpeedyTapper name.

PHP release target: <https://speedytapper.otcsoft.com>

Legacy Vercel rollback: <https://speedytapper.vercel.app>

Start with [`AGENTS.md`](./AGENTS.md) for repository working rules and [`docs/DECISIONS.md`](./docs/DECISIONS.md) for durable product and architecture decisions. Run `git status --short` before making changes: the Local checkout can be shared by separate Codex tasks that do not share transcripts.

## Sources of truth

| Concern | Source |
| --- | --- |
| Code and release contents | Git commit |
| PHP production state | Hostinger MCP artifact built from the recorded `main` commit |
| Legacy rollback state | Immutable Vercel deployment for its commit |
| Setup and committed target behavior | This README at the target commit |
| Durable decisions | [`docs/DECISIONS.md`](./docs/DECISIONS.md) |
| Agent and release rules | [`AGENTS.md`](./AGENTS.md) |
| Audio provenance | [`assets/audio/SOURCES.md`](./assets/audio/SOURCES.md) |
| Font provenance | [`assets/fonts/SOURCES.md`](./assets/fonts/SOURCES.md) |
| Visual QA history | [`design-qa.md`](./design-qa.md) |

`design-qa.md` is historical evidence and may lag production. Verify release state through Git plus the active Hostinger deployment; use the immutable Vercel deployment only as the previous-generation rollback. Uncommitted experiments must be labelled separately and are never evidence of production behavior.

## Play locally

Requirements: Node.js 20 or newer. PHP API work additionally requires PHP 8.2+, Composer, and MySQL 8 or a current MariaDB release.

```bash
npm run dev
```

Open <http://localhost:4173> on this Mac.

`npm run dev` is the quickest gameplay/UI server and deliberately leaves Google profiles unavailable. For the same-origin PHP API, install Composer dependencies, copy the ignored local configuration example, migrate a local database, and use the PHP router:

```bash
composer install
cp server/config.local.example.php server/config.local.php
# Fill in the ignored local file, then:
php server/bin/migrate.php
npm run dev:php
```

To test on an iPhone on the same Wi-Fi network:

1. Find the Mac's local address with `ipconfig getifaddr en0`.
2. Keep `npm run dev` running.
3. In iPhone Safari, open `http://MAC_IP:4173`.

For a fully installable/offline iPhone version, open the HTTPS production URL in Safari, use **Share**, and select **Add to Home Screen**. The app includes a web manifest, icons, safe-area support, standalone mode, and a service worker.

## Repository map

| Path | Responsibility |
| --- | --- |
| `src/config.js` | Balancing, modes, colors, and theme palettes |
| `src/game-engine.js` | Deterministic gameplay state and rules |
| `src/main.js` | DOM rendering, input, navigation, persistence, and controller wiring |
| `src/*-controller.js` | Browser audio and other platform-effect lifecycles |
| `src/pet-catalog.js`, `src/pet-controller.js` | Stable companion catalog plus menu/game pose, direction, and idle behavior |
| `src/theme-catalog.js`, `src/theme-audio.js` | Stable theme prices/actions plus selected-theme audio manifests |
| `src/profile-client.js` | Same-origin Google profile, cosmetic-shop, and leaderboard client |
| `server/src/` | PHP identity, CSRF/session handling, server-issued runs, proof replay, achievements, debt-aware coin accounting, pets, paid themes, moderation, and MySQL repositories |
| `server/migrations/` | Repeatable MySQL schema migrations |
| `api/index.php` | Extensionless PHP `/api/*` HTTP boundary |
| `lib/leaderboard-model.js`, `api/leaderboard.js` | Retained legacy Vercel rollback backend |
| `sw.js` | PWA build graph and cache lifecycle |
| `test/` | Engine, UI wiring, audio, leaderboard, theme, and release coverage |
| `assets/audio/` | Runtime audio, provenance, and retained background masters |
| `assets/pets/` | Runtime companion sprites, habitats, and asset provenance |

## Development lifecycle

1. Define one concrete outcome and inspect `git status --short`.
2. Use the shared checkout only for one active editing task. Put parallel work on `codex/<task>` branches in separate worktrees.
3. Preserve unrelated dirty files; never stash, reset, stage, or commit another task's work.
4. Keep rules in the engine/configuration and browser effects in UI/controllers.
5. Add tests and run `npm run check` plus `git diff --check`.
6. Review and commit only the intended files.
7. Prefer a pull request into `main` after a GitHub remote is configured.

Check `git remote -v` before choosing the integration workflow. If no remote is configured, use reviewed local branches and commits; do not claim that work was pushed or merged through a PR.

Use one backlog system. GitHub Issues is the simplest default after a remote is added; choose Linear instead only if a broader product roadmap is needed. Do not duplicate active status across Issues, Linear, Obsidian, and Markdown.

## PHP release and Hostinger deployment

The PHP generation targets the independent Hostinger addon website and document root for `speedytapper.otcsoft.com`. It uses same-origin PHP sessions and a dedicated MariaDB/MySQL database. Real credentials never belong in Git. A private home-directory config remains preferred; the MCP-only release path may inject the ignored `server/config.local.php` into the release artifact because every `/server` route is denied and the file is never part of the commit. See [`docs/PHP_BACKEND.md`](./docs/PHP_BACKEND.md) for the API and configuration contract.

The existing Vercel site and Blob board remain a separate previous-generation rollback. Their name-only rows are not imported into the clean Google-profile season.

Production must always correspond to a tested Git commit plus a recorded artifact hash. Never deploy a dirty shared checkout or package an entire checkout. The intended workflow is:

1. Review and merge one release into `main`, then deploy that exact clean commit from the private GitHub repository.
2. Create a temporary staging tree from `git archive <commit>`, not from the working checkout. Keep only browser runtime files, `api/`, `server/`, `.htaccess`, and production Composer `vendor/`; exclude tests, docs, package files, source/rollback audio masters, `.git`, and every `.env` file.
3. Install production Composer dependencies in staging. The Google-supported cleanup hook retains only the OAuth2 service wrapper used by this app instead of shipping tens of thousands of unrelated API wrappers. Inject the untracked production configuration only into the staging tree, set it to mode `0600`, verify the archive is root-flat, and record its SHA-256 digest.
4. Deploy the prebuilt archive to the exact independent addon domain with Hostinger MCP `hosting_deployStaticWebsite`. Despite its static-oriented name, this endpoint transports and extracts prebuilt PHP files without a build step; PHP execution was validated on the isolated target before this workflow was accepted.
5. The first API request applies pending migrations under a database advisory lock. Migration `004_clear_leaderboard_for_multiplier_scoring.sql` historically removed pre-multiplier rows once; migration `005_allow_multiple_leaderboard_results.sql` preserves existing results; migrations `006`–`010` add verified run proofs, pets, achievements, the debt-aware economy ledger, and persistent pet visibility without clearing the board; migration `011` adds database-backed leaderboard administration and generation-safe reward resets; migration `012` adds paid-theme ownership/selection, theme purchase ledger events, and theme-aware reset audit fields without clearing the leaderboard. The reviewed `php server/bin/migrate.php` remains available for an explicit maintenance run.
6. Purge only the SpeedyTapper website cache, then smoke-test HTTPS, build ID, app shell/service worker, `/api/health`, `/api/session`, denied configuration paths, Google sign-in/logout, nickname editing, Arcade leaderboard submission, and server rejection of ranked Zen attempts. Arcade retains the `normal` API value for compatibility. Verify that a new Arcade run is added as its own result and that retrying the same run UUID remains idempotent.
7. Keep the previous immutable Vercel deployment available until the Hostinger release and physical-iPhone flow are verified.

Before deployment:

- assign one `YYYYMMDD-N` release ID after intended changes are combined;
- update every versioned HTML/module reference, `sw.js`, and the release-graph test;
- use `rg` to confirm that no stale ID remains;
- run `npm run check` and `git diff --check`;
- confirm the deployment commit checkout is clean and the archive manifest contains no development-only or private source assets;
- confirm the Google Web client authorizes `https://speedytapper.otcsoft.com`.

After deployment, record the commit SHA, build ID, artifact SHA-256, Hostinger addon document root, migration/season ID, and the immutable Vercel rollback URL. The HTML, stylesheet, and JavaScript module graph share one release version. The service worker bypasses the browser HTTP cache, removes older app caches, and performs a one-time reload when an installed iPhone switches releases.

## Current committed rules

These are accepted product rules for the PHP generation, not a description of every dirty working-tree experiment. Verify the target commit and Hostinger deployment before describing them as production behavior.

- **Arcade Mode** has three lives. Wrong colors, empty-board taps, inactive cells, and expired correct targets each cost one life. Its internal storage, API, and engine identifier remains `normal` for compatibility.
- **Zen** is endless, unranked practice with no decoys, deadline, leaderboard submission, achievements, or coins. The HUD shows elapsed time and an infinity symbol for lives while omitting the historical ranked top score. A correct target remains present through misses and has no response deadline; its next quiet interval starts at 1,000 ms and moves halfway toward the previous reaction time after every correct tap. Its single in-game **End run** control freezes the local score and reaction statistics and opens a **Results** screen; nothing is submitted or rewarded.
- A random quiet interval precedes each Arcade target; Zen uses its reaction-adaptive quiet interval.
- Correct taps award 100–1,000 points based on reaction time.
- Every independently spawned Arcade wrong-color decoy lives for 450–750 ms. Letting it expire naturally records a dodge worth 550 points. Decoy opportunities use wide randomized intervals and are approximately half as frequent as the preceding balance; even at maximum pressure the next opportunity waits at least 600 ms. Zen never schedules or activates a decoy.
- The first four successful taps use one full-screen cell, then the board becomes 2×2.
- 0–10 seconds: one fixed player color, no wrong colors, and a 1,000 ms lifetime.
- 10–20 seconds: one independent wrong-color decoy may appear between or during targets; target lifetime stays at 1,000 ms.
- 20–30 seconds: lifetime eases gradually from 1,000 ms to 750 ms.
- 30–40 seconds: up to two independent decoys may overlap at random positions.
- At 40 seconds the board becomes 4×4, target lifetime resets to 1,000 ms, and decoy pressure eases back to one at a time.
- At 50 seconds the target lifetime falls by 10 ms per correct tap toward a 200 ms floor. Every ten challenge taps can add another simultaneous decoy, up to six, and shortens both target and decoy quiet intervals without reducing the decoy-opportunity gap below 600 ms.
- A decoy never uses the player's current color. A target activation also reserves every cell that displayed a decoy immediately before that frame, so an expiring decoy cannot turn directly into the correct target. Correctly tapping the target, missing, target expiry, restart, or run end clears still-visible decoys without awarding dodges.
- Arcade has no time limit and can finish only when all three lives are gone. Losing a life adds a 1.5-second recovery pause before the next round.
- Arcade survival time is shown live and freezes when the final life is lost.
- A single neutral-grey progress bar drains along the bottom of the **Your color** field during every active decision. Its 60%-white fill stays close to the information it explains without adding movement at the edges of the screen.
- A utility header gives the menu, Results, and Game Over views a compact three-gradient **PimPoPom** wordmark, icon-only Leaderboard rank shortcut, and Profile shortcut. Arcade gameplay keeps compact icon-plus-caption **Restart** and **Menu** controls above the HUD; Zen replaces both with one **End run** control. Both result views place those labelled Restart and Menu controls at the top and omit bottom navigation. `Copyright © 2026 OTC Software` anchors the dialog footer.
- The main menu gives Arcade a bright pink-red glow and Zen a light summer-leaf green glow, uses larger mode names, and places the Zen **No coins awarded** note below its name. Achievements, Pet Shop, and Themes use theme-aware colored outlines. Pet Shop and Themes share one equal two-column row with paw and palette icons, compact touch height, and a tight caption-to-current-selection gap.
- **Themes** is a dedicated two-column Theme Shop. The internal `classic` theme is presented as **Default**; Default and Disco are free, Light costs 50 coins, and Pixel costs 100. Cards show only the theme name, price, preview, and action. Owned themes show **Select**, the current theme shows a disabled **Selected** status, and owned prices remain visible but greyed. Paid ownership, authoritative prices, coin debit, ledger event, and selection are atomic in MySQL; signed-out players may still switch between the two free themes. Default retains the vivid palette. Disco uses paler center-lit colors, visible reflected-light black concrete, and lightly scratched plastic tiles. Light uses near-white panels, a pale-blue sky with two thin clouds, white board gaps/borders, dark readable UI text, distinct bright targets, and white color-blind glyphs. Pixel self-hosts the OFL-licensed Pixelify Sans variable font and uses hard square borders, stepped shadows, and an arcade grid.
- **Settings** contains Color-blind mode, **Sound FX (Beta)**, **Music**, and separate persistent 0–100% volume sliders for both audio categories. Color-blind mode is on by default and shows a unique shape on each color; turning it off removes glyphs from the HUD, game tiles, and theme previews. Interactive Music remains removed.
- Settings and Leaderboard open as dedicated views with explicit navigation. Result screens can open the leaderboard and return to the intact result. Switching leaderboard modes updates the current view without resetting its scroll position or moving focus away from the selected tab. For a signed-in player, the selected mode's absolute position and Top percentage appear directly below the mode buttons.
- Sound FX defaults on and remembers an explicit opt-out. It owns the correct-tap tones and the life-loss cue only. While switched off, the app does not create its audio context or fetch, decode, cache, or play either asset. Turning it back on resumes directly from that Settings gesture, and every Start or Restart gesture verifies it again before cues can run.
- Beta sound uses standards-based Web Audio with an interactive-latency `AudioContext`. Every correct tap immediately plays the next half-second cue from the selected theme's fixed sixteen-note motif at native speed and a `0.375` base gain; Default uses Power Grid, while Disco, Light, and Pixel use their own coordinated sequences. Misses, wrong colors, dodges, inactive taps, and unready buffers neither play nor delay a cue. Losing a life plays the shared separately predecoded failure cue at the same `0.375` base gain, only while Sound FX is enabled, with at most one failure voice and two tap voices active. Retiring voices receive a short release. The Sound FX slider scales their shared output. There is no hum, `HTMLAudioElement`, or pace/reaction pitch shift.
- Music has an independent switch, defaults on, and remembers an explicit opt-out. Each theme owns one twelve-second melodic menu loop and a matching clean gameplay backing: Default uses 80 BPM **Daylight Circuit**, Disco 120 BPM **Mirror Circuit**, Light 100 BPM **Open Sky**, and Pixel 160 BPM **Coin-Op Spark**. Starting Arcade or Zen selects the clean variant; correct-tap tones then come only from Sound FX. Results and Game Over are silent, while returning to the menu restores the melodic variant. All use the same `0.42` base gain and independent Music slider. An already-unlocked Web Audio context survives a theme change, so an awaited purchase response does not require a second iPhone user gesture. There is no adaptive stage, tempo change, track rotation, or Interactive Music behavior.
- Runtime audio is excluded from the offline app shell and always fetched without browser or service-worker caching. Only the selected theme's enabled audio category is fetched and decoded. Tap banks and the failure cue remain lossless; every encoded background/menu pair retains lossless masters, deterministic generation, decoded-seam checks, hashes, and provenance in `assets/audio/SOURCES.md`.
- Accessibility, Sound FX, Music, both volume preferences, and the last free theme selection are stored on that device. An authenticated profile's server-owned selected theme is restored after session refresh and takes precedence over an unavailable paid local preference.
- Disco uses a separate reflected-light black-concrete background on the page, menus, dialogs, and board surround. Gameplay tiles, pet cards, and the streak meter retain the plain concrete material so the ambient cyan, violet, and warm reflections cannot look like target colors.
- Each correct tap is classified from the same rounded reaction milliseconds displayed to the player: Godlike under 250 ms, Perfect under 350 ms, Great under 450 ms, otherwise Good. Brief bold, left/right-tilted side overlays appear during play and a proportional four-color distribution bar appears on Results, Game Over, and leaderboard rows.
- A five-step Speed streak meter sits below the board. Its large animated gradient fill shows progress, an explicit `x1`–`x5` label stays at the right, and the completed meter glows. The x1 track stays dark; x2 through x5 use green, blue, violet, and gold tier colors at 50% background opacity while the matching multiplier label remains solid, with the animated progress gradient layered above that base. Godlike taps add two steps and Perfect taps add one; overflow carries into the next tier. Great and Good preserve the current meter and multiplier without advancing it. Every five steps unlocks the next score multiplier for subsequent taps, from 2× through a 5× cap. Only a mistake resets both before the next score, while decoy dodges remain neutral and are never multiplied. A multiplier applies only to the tap currently being awarded: it never rescales points accumulated earlier in the run and never changes coin accrual.
- The result score is presented as the primary result above played/survival and reaction statistics. Leaderboard entries retain accessible rating counts and show the same proportional speed-distribution bar.
- The Arcade HUD and Game Over screen show the current leaderboard top score when the PHP service is available. Player-facing copy explains that every authenticated Arcade result is saved; the Profile and utility rank show that player's best position, while a submitted-result view shows the exact new run and its nearby ranks. Retained Zen leaderboard rows remain visible as historical results; internal data-generation seasons are not exposed as a game concept.
- Google sign-in creates one internal profile UUID, then requires the player to confirm a public nickname before Arcade score submission. Google display names are never persisted or published. The server stores only a one-way digest of the verified Google subject—not the raw subject, token, email, or a password. The Profile view shows current Arcade rank plus retained historical Zen context. Signed-out players may practice, but coin balance, Pet Shop, and Achievements controls are visually gated and explain that Google login is required; anonymous and Zen runs never mint coins or progression.
- Pet Shop offers five durable cosmetic companions: Foka for 10 coins, Kesha for 20, Tauta for 50, Misha for 100, and Pancake for 500. A first purchase atomically debits the exact server-side price, records ownership, selects and shows the pet, and cannot charge twice; selecting an owned pet is free. The selected pet can be hidden without losing ownership or forgetting the choice, then shown again; another owned pet displays **Select**, while the hidden current pet displays **Show**. Existing confirmed `misha_boy` profiles receive the one-time migration entitlement, but later nickname changes do not grant a free pet. Separately, an authenticated confirmed nickname saved exactly as lowercase Cyrillic `кокос` activates the server-authorized **Mitsuri** red-rabbit easter egg. Mitsuri temporarily replaces the displayed companion without changing, charging, or exposing the durable Pet Shop selection; another nickname removes her and restores that selection. A shown pet and its bedding, climber, perch, floe, cushion, or glow surface appear across every non-game screen and in leaderboard portraits. Gameplay keeps only the pet above the Speed streak meter so the habitat never obscures the reaction area; a hidden shop pet appears nowhere unless Mitsuri is active. Shop and menu sprites use separate small upward offsets so each animal sits correctly on its habitat without changing the approved gameplay placement, with Foka and Kesha raised an additional 5 px in the shop and 2 px in menus. Mitsuri's unusually bottom-weighted two-layer cushion uses dedicated shared non-game-scene and leaderboard offsets so it overlaps beneath the rabbit instead of inheriting the taller generic habitat gap. Misha renders in front of both climber layers. For the directional animals, taps are resolved from the visible pet's center: a centered tap keeps the front pose, an angle up to 30 degrees selects the persistent half-left/right pose, and a wider angle selects the full turn. Right-turn keyframes skip the duplicated front frame so Foka and the other ten-frame pets move symmetrically. Pancake remains intentionally binary left/right, uses a slower 1.44-second dance, and has a pair of high-contrast eyes layered over its supplied sprite.
- Achievements contains five durable server-side goals. Protocol-verified Arcade runs unlock their gameplay goals; rewards are claimed separately and idempotently. An illuminated `*` overlays the main-menu Achievements control whenever at least one authenticated reward is ready to claim. The former three-minute Zen achievement is retired from the active catalog. **Buy a pet** unlocks inside the same database transaction as the first successful pet debit and ownership insert, so clicking Buy, failing for insufficient funds, retrying an owned pet, or changing the equipped pet cannot unlock it. Locked cards omit the redundant **In progress** line; rewards use a numeric `+N` beside the same pixelated gold coin SVG as the utility header and Pet Shop.
- Ranked Arcade play requires a signed-in profile with a confirmed nickname. It begins with a server-generated run ID bound to both that player and browser session, and starting another run closes the player's previous issued attempt. At Game Over the browser submits only a compact chronological proof of target, input, miss, decoy opportunity/activation/expiry, and finish transitions; it does not submit authoritative score, duration, ratings, or coins. PHP replays that proof, derives the result, checks both server-clock coverage and independent timer cadence, detects cloned traces, and consumes the run exactly once. The server refuses ranked Zen ticket creation and Zen result submission. Migration `006` distinguishes legacy, verified, review, quarantined, and logically deleted records without silently removing history.
- Leaderboard administration is authorized by a server-side `leaderboard_admin` database role and exposed to the browser only as `profile.isAdmin`. The first role is granted only if two exact known production result UUIDs still resolve to one player; runtime authorization never relies on nickname, rank, score, email, or browser state. The Profile-only Admin view pages through the full leaderboard or conservative scan flags, opens exact-result evidence, and exposes only quarantine plus the separately confirmed destructive reset. Default **All** and scan views omit deleted rows; the explicit **Deleted** filter reveals them. Mutations require same-origin CSRF, a Google sign-in no more than 15 minutes old, exact result UUID, explicit confirmation, expected current status, and an audit reason. An administrator may moderate any exact result, including their own or another administrator's, while the two-stage quarantine and audit safeguards remain mandatory.
- An administrator may quarantine one reviewed result, then logically delete that exact quarantined result while resetting the affected account's pets, paid themes, and reward economy. The transaction revokes the linked run, removes pet/theme ownership and selection, zeroes coins, debt, remainder, collected coins, and credited play, records removed cosmetic IDs, and advances an integer economy generation. Achievements and immutable proof/run/ledger/moderation history remain. Future runs and purchases use the new generation, so old rewards cannot be recomputed into the wallet and removed cosmetics can be repurchased without event-key collisions; retrying the same reset is idempotent.
- The utility header shows one pixelated gold coin with the signed-in numeric balance in a compact lower-right badge immediately left of Leaderboard. Pet Shop and Theme Shop repeat that balance and use the same coin for prices; owned prices are greyed. Every cumulative protocol-verified Arcade minute earns one coin; Zen earns none. Sub-minute Arcade time carries across eligible runs, and immutable completed-run plus coin ledgers prevent retries from awarding twice. Pet and theme purchases create negative ledger events and claimed achievement rewards create positive events. Moderation recomputes net entitlement from eligible play plus those economy events; if revoked earnings were already spent, a nonnegative coin debt absorbs future credits before spendable coins increase. High-risk runs receive neither ranking nor coins until reviewed. This prototype currency still has no real-money value.
- Protocol verification blocks direct aggregate editing, fabricated seven-day coin requests, impossible state transitions, omitted decoy cadence, exact trace replay, and casual API tampering. Implausible reaction distributions or sustained timer manipulation are withheld for review. Proof/body caps, a pre-parse authenticated-session finish limit, persisted per-player start/submission limits, redacted rejected-proof storage, and bounded retention protect the shared database from invalid-proof growth. It does not prove a human played: automation can imitate plausible human timing, and computer vision or a sufficiently modified real-time client remain possible. The product and documentation must say **protocol verified**, not bot-proof or human verified.
- Moving the app into the background safely stops the current run and silences/suspends both opted-in audio controllers. The next explicit gameplay gesture resumes them.
- Reaction timing starts on the browser presentation frame that reveals a tile and ends at the original pointer-contact timestamp when the browser provides a compatible monotonic value. Expiry is anchored to the same absolute deadline, and queued input already covered by an expiry cannot remove a second life. Browser timestamps still approximate physical screen and touch hardware; high-speed external measurement is required for true photon-to-contact calibration.

All balancing values are centralized in [`src/config.js`](./src/config.js).

## Verification

```bash
npm run check
```

The game engine is separate from the browser UI and has deterministic coverage for board progression, proof-event capture, multiplier scoring and resets, empty-board penalties, independent overlapping Arcade decoys, natural-expiry dodge rewards, decoy clearing, rounded speed ratings, gradual pressure, Arcade life loss, endless adaptive no-decoy Zen practice, and manually frozen Zen results. Input-timing tests cover pointer timestamp normalization, presentation-anchored deadlines, pre-presentation input, expiry/input race classification, and play beyond Zen's former deadline. Static UI tests cover the utility header, Google-only profile flow, achievements, Pet Shop and Theme Shop coin state, dedicated Settings/Leaderboard navigation, Results/Game Over round trips, streak and result presentation, leaderboard speed distribution, all four material systems, independent theme-aware Sound FX/Music wiring, and the unified release graph. Controller tests cover disabled-audio isolation, trusted-gesture start, in-place theme switching, stale-load rejection, fixed looping, cue concurrency, fades, opt-out, and background suspension. Audio asset tests cover distinct fixed-slot tone banks, exact zero tails, paired runtimes, and retained masters. PHP tests cover CSRF/request guards, server-issued Arcade run contracts, chronological proof replay and tamper rejection, ranked Zen rejection, server-clock coin bounds, achievement and cosmetic purchase wiring, theme accounting/reset behavior, debt-aware reconciliation, exact moderation including administrator-owned results, default deleted-row filtering, idempotency, ranking windows, configuration isolation, and schema constraints. The retained Vercel leaderboard model remains covered for rollback compatibility.

Automated verification does not replace physical-device validation. Touch timing, Google sign-in/cookies, paid-theme purchase/select behavior, every theme's tap/life-loss latency and balance, background/menu mix and loop transitions, Light/Pixel readability, and installed-PWA upgrade behavior must still be checked on iPhone Safari and the installed PWA before this release is described as physically validated.

## Why a small PWA

The proof of concept does not need 3D rendering or an engine runtime. A small PWA starts instantly, runs directly on iPhone and Android browsers, works offline after installation (except for the shared leaderboard), and keeps the mechanics easy to change. If playtesting validates the loop, the same rules can later be moved into Unity, Godot, native mobile code, or a Steam build.
