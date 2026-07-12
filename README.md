# SpeedyTapper

An installable, offline-capable browser proof of concept for validating the core reaction loop before choosing the architecture of the eventual Steam, mobile, Roblox, or console products.

Production: <https://speedytapper.vercel.app>

Start with [`AGENTS.md`](./AGENTS.md) for repository working rules and [`docs/DECISIONS.md`](./docs/DECISIONS.md) for durable product and architecture decisions. Run `git status --short` before making changes: the Local checkout can be shared by separate Codex tasks that do not share transcripts.

## Sources of truth

| Concern | Source |
| --- | --- |
| Code and release contents | Git commit |
| Production state | Vercel deployment for that commit |
| Setup and committed target behavior | This README at the target commit |
| Durable decisions | [`docs/DECISIONS.md`](./docs/DECISIONS.md) |
| Agent and release rules | [`AGENTS.md`](./AGENTS.md) |
| Audio provenance | [`assets/audio/SOURCES.md`](./assets/audio/SOURCES.md) |
| Visual QA history | [`design-qa.md`](./design-qa.md) |

`design-qa.md` is historical evidence and may lag production. Verify release state through Git and Vercel rather than a status note in documentation. Uncommitted experiments must be labelled separately and are never evidence of production behavior.

## Play locally

Requirements: Node.js 20 or newer.

```bash
npm run dev
```

Open <http://localhost:4173> on this Mac.

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
| `lib/leaderboard-model.js` | Shared leaderboard validation and ranking |
| `api/leaderboard.js` | Vercel/local leaderboard storage boundary |
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

## Release and Vercel deployment

The Local directory is linked to the `speedytapper` Vercel project. The shared leaderboard uses a private Vercel Blob store connected to the project; local development uses ignored `.data/leaderboard.json`.

Production must always correspond to a tested Git commit. Never run a production deploy from a dirty shared checkout.

Preferred workflow after a GitHub remote exists:

1. Merge a reviewed feature branch into `main`.
2. Let Vercel build that exact `main` commit.
3. Smoke-test the production alias and retain the previous immutable deployment for rollback.

For an authorized manual release, create an isolated worktree at the intended commit and deploy from there:

```bash
git worktree add --detach /tmp/speedytapper-release <commit-sha>
cd /tmp/speedytapper-release
test "$(git rev-parse HEAD)" = "<commit-sha>"
test -z "$(git status --porcelain)"
vercel link --yes --project speedytapper
vercel deploy --prod --yes
```

After verification, return to the main repository and remove only this dedicated temporary checkout with `git worktree remove /tmp/speedytapper-release`. If Git refuses, inspect `git -C /tmp/speedytapper-release status --short --untracked-files=all --ignored` and resolve only known generated metadata before retrying; never force-delete unexplained work. Keep Vercel credentials and `.env` files out of Git.

Before deployment:

- assign one `YYYYMMDD-N` release ID after intended changes are combined;
- update every versioned HTML/module reference, `sw.js`, and the release-graph test;
- use `rg` to confirm that no stale ID remains;
- run `npm run check` and `git diff --check`;
- confirm the deployment checkout is clean.

After deployment, verify the production build ID, service worker, required assets, and leaderboard API. Record the commit SHA, build ID, Vercel deployment ID, immutable URL, and previous rollback deployment in the task/issue or release record.

The HTML, stylesheet, and JavaScript module graph share one release version. The service worker bypasses the browser HTTP cache, removes older app caches, and performs a one-time reload when an installed iPhone switches releases. [`vercel.json`](./vercel.json) keeps the app shell revalidated.

## Current committed rules

These are accepted product rules, not a description of every dirty working-tree experiment. Verify the target commit and Vercel deployment before describing them as production behavior.

- **Normal Mode** has three lives. Wrong colors, empty-board taps, inactive cells, and expired correct targets each cost one life.
- **1-min Zen** ends after sixty seconds; mistakes are counted but lives are never removed.
- A random quiet interval precedes every colored cell.
- Correct taps award 100–1,000 points based on reaction time.
- Successfully leaving a lone wrong color untouched is a dodge worth 550 points.
- The first four successful taps use one full-screen cell, then the board becomes 2×2.
- 0–10 seconds: one fixed player color, no wrong colors, and a 1,000 ms lifetime.
- 10–20 seconds: lone wrong colors appear and must be ignored for 1,000 ms.
- 20–30 seconds: lifetime eases gradually from 1,000 ms to 750 ms.
- 30–40 seconds: a second simultaneous color appears in only about 10% of rounds.
- At 40 seconds the board becomes 4×4 and resets to 1,000 ms with no simultaneous decoys.
- At 50 seconds decoys return on 20% of rounds; lifetime then falls by only 10 ms per correct tap, to a 200 ms floor.
- Mixed-round pressure rises by 1.5 percentage points per successful tap. Every ten successful taps adds another possible decoy and gently reduces the quiet interval, up to six decoys and an 80% mixed-round ceiling.
- Normal has no time limit and can finish only when all three lives are gone. Losing a life adds a 1.5-second recovery pause before the next round.
- Normal survival time is shown live and freezes when the final life is lost.
- A single neutral-grey progress bar drains along the bottom of the **Your color** field during every active decision. Its 60%-white fill stays close to the information it explains without adding movement at the edges of the screen.
- Active gameplay has a compact SpeedyTapper logo plus 44px Restart and Main menu shortcuts above the HUD. The Game Over name form also offers a full-width Restart button that immediately starts the same mode again.
- **Settings** contains the Classic and Disco theme selector. Classic targets show the vivid palette immediately with no dark color-transition frame. Disco uses paler center-lit colors, clearly visible repeating black concrete, and lightly scratched plastic tile surfaces in both idle and lit states.
- Settings also contains Color-blind mode and the optional **Sound FX (Beta)** switch. Color-blind mode is on by default and shows a unique shape on each color; turning it off removes glyphs from the HUD, game tiles, and theme previews.
- Settings and Leaderboard open as dedicated menu views with explicit Back navigation. Switching leaderboard modes updates the current view without resetting its scroll position or moving focus away from the selected tab.
- Sound FX is off by default. While it remains off, the app does not create an audio context or fetch, decode, cache, or play audio files. Turning it on resumes audio directly from that Settings gesture, and every Start or Restart gesture verifies it again before gameplay sounds can run. There is deliberately no delayed tap or switch-off sample in the high-speed reaction loop.
- Beta sound uses the standards-based Web Audio API—not an Apple-only API—with an interactive-latency `AudioContext` and predecoded in-memory buffers. A softly opened master gate avoids an iOS output-route pop, and one persistent hum loop uses smooth target automation instead of hard or linear gain edges. Life-loss cues are limited to one at a time, cleared on suspension, and faded out before a quick restart so they cannot clip or resume in a later run. Audio files are excluded from the offline app shell and fetched without browser or service-worker caching.
- Music has an independent switch, defaults on, and remembers an explicit opt-out on that device. Loading can begin before interaction, but playback starts only after a trusted gesture. The menu arrangement continues through the 1×1 warm-up, then the engine snapshot selects progressively richer 2×2, 4×4-reset, and challenge regions before returning to the menu region at Game Over.
- Adaptive music decodes one AAC asset into memory and loops sample-aligned, post-master-faded regions. Stage changes and shutdown use short fades rather than abrupt non-zero stops. The music asset remains outside the install-time app shell; after the current service worker controls the page, its first runtime request is cached for later launches and offline reuse. Sound FX assets are never added to that runtime cache.
- Theme, accessibility, Sound FX, and Music preferences are stored on that device.
- The HUD and result screen show the current global top score for the selected mode; no player profile or local result history is stored.
- Each completed run asks for a name and can be submitted to the shared, mode-specific Top 20 leaderboard. After a valid entry, that name is remembered on the device and prefilled on future result screens as a form convenience only; it does not create a player profile, personal best, or local score history. Entries show survival or play time, taps, dodges, fastest reaction, and average reaction.
- Leaderboard submissions are validated and throttled, but gameplay still runs in the browser; this prototype board is not suitable for competitive play without server-authoritative anti-cheat.
- Moving the app into the background safely stops the current run and fades/suspends enabled audio. The next explicit gesture resumes opted-in audio.

All balancing values are centralized in [`src/config.js`](./src/config.js).

## Verification

```bash
npm run check
```

The game engine is separate from the browser UI and is covered by deterministic tests for board progression, scoring, empty-board penalties, dodge rewards, reaction statistics, rare adjacent decoys, gradual timing, Normal life loss, and Zen timing. Static UI tests cover dedicated Settings/Leaderboard navigation, gameplay shortcuts, same-mode restart flow, distinct Classic/Disco material treatments, the default-off Beta sound setting, runtime-music caching boundaries, and the unified release graph. The audio controllers are tested for disabled behavior, decoded-buffer playback, click-safe PCM boundaries, adaptive loop regions, faded shutdown, stale suspend/resume races, and resource cleanup. The leaderboard model is tested for validation, legacy-row compatibility, deterministic ranking, mode separation, reaction metrics, and the 20-entry cap.

Automated verification does not replace physical-device listening. Music and Sound FX must still be checked on iPhone Safari and the installed PWA before this release is described as physically validated.

## Why a small PWA

The proof of concept does not need 3D rendering or an engine runtime. A small PWA starts instantly, runs directly on iPhone and Android browsers, works offline after installation (except for the shared leaderboard), and keeps the mechanics easy to change. If playtesting validates the loop, the same rules can later be moved into Unity, Godot, native mobile code, or a Steam build.
