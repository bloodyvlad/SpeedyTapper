# SpeedyTapper

An installable, offline-capable browser proof of concept for validating the core reaction loop before choosing the architecture of the eventual Steam, mobile, Roblox, or console products.

PHP release target: <https://speedytapper.otcsoft.com>

Legacy Vercel rollback: <https://speedytapper.vercel.app>

Start with [`AGENTS.md`](./AGENTS.md) for repository working rules and [`docs/DECISIONS.md`](./docs/DECISIONS.md) for durable product and architecture decisions. Run `git status --short` before making changes: the Local checkout can be shared by separate Codex tasks that do not share transcripts.

## Sources of truth

| Concern | Source |
| --- | --- |
| Code and release contents | Git commit |
| PHP production state | Hostinger deployment of the exact `php-main` commit |
| Legacy rollback state | Immutable Vercel deployment for its commit |
| Setup and committed target behavior | This README at the target commit |
| Durable decisions | [`docs/DECISIONS.md`](./docs/DECISIONS.md) |
| Agent and release rules | [`AGENTS.md`](./AGENTS.md) |
| Audio provenance | [`assets/audio/SOURCES.md`](./assets/audio/SOURCES.md) |
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
| `src/profile-client.js` | Same-origin Google profile and seasonal leaderboard client |
| `server/src/` | PHP configuration, identity, sessions, validation, and MySQL repositories |
| `server/migrations/` | Repeatable MySQL schema migrations |
| `api/index.php` | Extensionless PHP `/api/*` HTTP boundary |
| `lib/leaderboard-model.js`, `api/leaderboard.js` | Retained legacy Vercel rollback backend |
| `sw.js` | PWA build graph and cache lifecycle |
| `test/` | Engine, UI wiring, audio, leaderboard, theme, and release coverage |
| `assets/audio/` | Runtime audio, provenance, and retained rollback masters |

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

The PHP generation targets the isolated Hostinger document root for `speedytapper.otcsoft.com`. It uses same-origin PHP sessions and a dedicated MariaDB/MySQL database. Real credentials belong in `~/.config/speedytapper/config.php` under the hosting account home, never in Git or `public_html`. See [`docs/PHP_BACKEND.md`](./docs/PHP_BACKEND.md) for the API and configuration contract.

The existing Vercel site and Blob board remain a separate previous-generation rollback. Their name-only rows are not imported into the clean Google-profile season.

Production must always correspond to a tested Git commit. Never deploy a dirty shared checkout. The intended workflow is:

1. Review and commit one release on `php-main`, then push that exact commit to the private GitHub repository.
2. In Hostinger hPanel Git, select the private repository, `php-main`, and the isolated subdomain document root.
3. Install production Composer dependencies and deploy that commit.
4. Back up the database when applicable, then run `php server/bin/migrate.php` with the private production configuration available.
5. Smoke-test HTTPS, the build ID, app shell/service worker, `/api/health`, `/api/session`, Google sign-in/logout, nickname editing, and Normal/Zen leaderboard submissions.
6. Keep the previous immutable Vercel deployment available until the Hostinger release and physical-iPhone flow are verified.

Before deployment:

- assign one `YYYYMMDD-N` release ID after intended changes are combined;
- update every versioned HTML/module reference, `sw.js`, and the release-graph test;
- use `rg` to confirm that no stale ID remains;
- run `npm run check` and `git diff --check`;
- confirm the deployment commit checkout is clean;
- confirm the Google Web client authorizes `https://speedytapper.otcsoft.com`.

After deployment, record the commit SHA, build ID, Hostinger document root, migration/season ID, and the immutable Vercel rollback URL. The HTML, stylesheet, and JavaScript module graph share one release version. The service worker bypasses the browser HTTP cache, removes older app caches, and performs a one-time reload when an installed iPhone switches releases.

## Current committed rules

These are accepted product rules for the PHP generation, not a description of every dirty working-tree experiment. Verify the target commit and Hostinger deployment before describing them as production behavior.

- **Normal Mode** has three lives. Wrong colors, empty-board taps, inactive cells, and expired correct targets each cost one life.
- **3-min Zen** ends after exactly 180 seconds; mistakes are counted but lives are never removed and the HUD shows an infinity symbol.
- A random quiet interval precedes every colored cell.
- Correct taps award 100–1,000 points based on reaction time.
- Every independently spawned wrong-color decoy lives for 300–500 ms. Letting it expire naturally is a dodge worth 550 points.
- The first four successful taps use one full-screen cell, then the board becomes 2×2.
- 0–10 seconds: one fixed player color, no wrong colors, and a 1,000 ms lifetime.
- 10–20 seconds: one independent wrong-color decoy may appear between or during targets; target lifetime stays at 1,000 ms.
- 20–30 seconds: lifetime eases gradually from 1,000 ms to 750 ms.
- 30–40 seconds: up to two independent decoys may overlap at random positions.
- At 40 seconds the board becomes 4×4, target lifetime resets to 1,000 ms, and decoy pressure eases back to one at a time.
- At 50 seconds the target lifetime falls by 10 ms per correct tap toward a 200 ms floor. Every ten challenge taps can add another simultaneous decoy, up to six, and shortens both target and decoy quiet intervals.
- A decoy never uses the player's current color. Correctly tapping the target, missing, target expiry, restart, or run end clears still-visible decoys without awarding dodges.
- Normal has no time limit and can finish only when all three lives are gone. Losing a life adds a 1.5-second recovery pause before the next round.
- Normal survival time is shown live and freezes when the final life is lost.
- A single neutral-grey progress bar drains along the bottom of the **Your color** field during every active decision. Its 60%-white fill stays close to the information it explains without adding movement at the edges of the screen.
- A utility header gives the menu and Game Over views a compact SpeedyTapper logo, Leaderboard rank shortcut, and Profile shortcut. Active gameplay keeps compact Restart and Main menu controls above the HUD. Game Over offers a full-width Restart button for the same mode.
- **Settings** contains the Classic and Disco theme selector. Classic targets show the vivid palette immediately with no dark color-transition frame. Disco uses paler center-lit colors, clearly visible repeating black concrete, and lightly scratched plastic tile surfaces in both idle and lit states.
- Settings also contains Color-blind mode, the optional **Sound FX (Beta)** switch, the Music master switch, and an opt-in **Interactive Music (Beta)** switch. Color-blind mode is on by default and shows a unique shape on each color; turning it off removes glyphs from the HUD, game tiles, and theme previews.
- Settings and Leaderboard open as dedicated views with explicit navigation. The Game Over screen can open the leaderboard and return to the intact result, while a separate square menu control returns directly to the main menu. Switching leaderboard modes updates the current view without resetting its scroll position or moving focus away from the selected tab.
- Sound FX is off by default. While it remains off, the app does not create an audio context or fetch, decode, cache, or play audio files. Turning it on resumes audio directly from that Settings gesture, and every Start or Restart gesture verifies it again before gameplay sounds can run. There is deliberately no delayed tap or switch-off sample in the high-speed reaction loop.
- Beta sound uses the standards-based Web Audio API—not an Apple-only API—with an interactive-latency `AudioContext` and predecoded in-memory buffers. A softly opened master gate avoids an iOS output-route pop, and one persistent hum loop uses smooth target automation instead of hard or linear gain edges. Life-loss cues are limited to one at a time, cleared on suspension, and faded out before a quick restart so they cannot clip or resume in a later run. Sound FX files are excluded from the offline app shell and fetched without browser or service-worker caching.
- Music has an independent switch, defaults on, and remembers an explicit opt-out on that device. Loading can begin before interaction, but playback starts only after a trusted gesture. The approved soundtrack set rotates in-session through Neon Circuit Refined, Deep Current, and Power Grid: every completed result screen advances to the next track's menu region, and that track continues into the following run.
- Each soundtrack uses 100 BPM for the menu and 1×1 opening, 120 BPM from 2×2 through early 4×4, 140 BPM from 90 seconds of elapsed play, and 168 BPM from two minutes. The 90- and 120-second changes use the engine's authoritative elapsed snapshot, which currently includes life-loss recovery time.
- Adaptive music decodes three AAC assets into memory and loops sample-aligned, post-master-faded regions. Stage and track changes plus shutdown use short fades rather than abrupt non-zero stops. Music remains outside the install-time app shell; after the current service worker controls the page, each soundtrack is cached on its first runtime request for later launches and offline reuse. Sound FX assets are never added to that runtime cache.
- Interactive Music is a default-off alternative under the Music master switch; turning it off returns to the approved adaptive soundtrack above. Its backing contains no time-driven lead. Every correct target tap immediately plays the next note in that track's fixed 16-note motif; misses, wrong colors, dodges, inactive taps, and unready buffers neither play nor delay a note. The hit number selects the motif position, so the sequence resets with each run and never depends on player color or reaction speed.
- Interactive backing uses twelve pre-recorded four-bar states: a 100 BPM opening, richer 104–112 BPM 2×2 development, a substantially richer 112 BPM 4×4 reset, and 120–168 BPM challenge states. Late-game selection derives from the engine snapshot's mean quiet delay plus the response window capped at 400 ms, then chooses the nearest authored pressure state. Adjacent changes wait no longer than the next beat to enter a dedicated bridge before the destination loop; the tap note itself is never quantized. Reverse bridges are retained so a future gameplay mechanic can select a slower backing state without pitch-shifting audio.
- Interactive mode lazy-decodes only the selected track's backing AAC sprite and small PCM note bank. Switching variants replaces the audio context so the legacy and Interactive mixes cannot play together. Both variants share track rotation, trusted-gesture unlock, fade/suspend/close behavior, runtime music caching, and the Music opt-out. Existing music and all rollback masters remain retained.
- Theme, accessibility, Sound FX, Music, and Interactive Music preferences are stored on that device.
- Each correct tap is classified from the same rounded reaction milliseconds displayed to the player: Godlike under 200 ms, Perfect under 300 ms, Great under 400 ms, otherwise Good. Brief side overlays appear during play and a proportional four-color distribution bar appears on Game Over and leaderboard rows.
- The HUD and result screen show the current seasonal top score for the selected mode when the PHP service is available.
- Google sign-in creates one internal profile UUID, then requires the player to confirm a public nickname before score submission. Google display names are never persisted or published. The server stores only a one-way digest of the verified Google subject—not the raw subject, token, email, or a password. The Profile view shows rank, top percentage, and nearby places for both modes.
- A signed-in completed run submits automatically. The clean seasonal leaderboard keeps one best row per profile and mode, returns the top five, and adds the current player with up to two neighboring ranks on each side. Entries include duration, taps, dodges, fastest/average reaction, and all four speed-rating counts. A lower later result never replaces that profile's best row.
- Leaderboard submissions are validated and throttled, but gameplay still runs in the browser; this prototype board is not suitable for competitive play without server-authoritative anti-cheat.
- Moving the app into the background safely stops the current run and fades/suspends enabled audio. The next explicit gesture resumes opted-in audio.
- Reaction timing starts on the browser presentation frame that reveals a tile and ends at the original pointer-contact timestamp when the browser provides a compatible monotonic value. Expiry is anchored to the same absolute deadline, and queued input already covered by an expiry cannot remove a second life. Browser timestamps still approximate physical screen and touch hardware; high-speed external measurement is required for true photon-to-contact calibration.

All balancing values are centralized in [`src/config.js`](./src/config.js).

## Verification

```bash
npm run check
```

The game engine is separate from the browser UI and has deterministic coverage for board progression, scoring, empty-board penalties, independent overlapping decoys, natural-expiry dodge rewards, decoy clearing, rounded speed ratings, gradual pressure, Normal life loss, and exact three-minute Zen timing. Input-timing tests cover pointer timestamp normalization, presentation-anchored deadlines, pre-presentation input, and expiry/input race classification. Static UI tests cover the utility header, Google-only profile flow, dedicated Settings/Leaderboard navigation, Game Over round trips, gameplay shortcuts, result speed distribution, distinct Classic/Disco materials, audio settings, runtime-music caching boundaries, and the unified release graph. PHP tests cover request guards, identity-safe payloads, score consistency, one-best seasonal ranking, top-five-plus-context windows, configuration isolation, and schema constraints. The retained Vercel leaderboard model remains covered for rollback compatibility.

Automated verification does not replace physical-device validation. Touch timing, Google sign-in/cookies, music, Sound FX, and installed-PWA upgrade behavior must still be checked on iPhone Safari and the installed PWA before this release is described as physically validated.

## Why a small PWA

The proof of concept does not need 3D rendering or an engine runtime. A small PWA starts instantly, runs directly on iPhone and Android browsers, works offline after installation (except for the shared leaderboard), and keeps the mechanics easy to change. If playtesting validates the loop, the same rules can later be moved into Unity, Godot, native mobile code, or a Steam build.
