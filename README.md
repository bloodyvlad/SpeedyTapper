# SpeedyTapper mechanics proof of concept

An installable, offline-capable browser prototype for testing the core reaction loop before choosing a visual setting or production engine.

Production: <https://speedytapper.vercel.app>

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

## Deploy to Vercel

The local directory is linked to the `speedytapper` project in Vercel. The shared leaderboard uses a private Vercel Blob store connected to the project; local development uses the ignored `.data/leaderboard.json` file. Deploy the committed version to production with:

```bash
vercel deploy --prod
```

The configuration in [`vercel.json`](./vercel.json) keeps the service worker and manifest revalidated so installed devices discover updates promptly.

## Current rules

- **Normal Mode** has three lives. Wrong taps and expired correct targets each cost one life.
- **1-min Zen** ends after sixty seconds; mistakes are counted but lives are never removed.
- A random quiet interval precedes every colored cell.
- Correct taps award 100–1,000 points based on reaction time.
- The first four successful taps use one full-screen cell, then the board becomes 2×2.
- 0–10 seconds: one fixed player color, no wrong colors, and a 1,000 ms lifetime.
- 10–20 seconds: lone wrong colors appear and must be ignored for 1,000 ms.
- 20–30 seconds: lifetime eases gradually from 1,000 ms to 750 ms.
- 30–40 seconds: a second simultaneous color appears in only about 10% of rounds.
- At 40 seconds the board becomes 4×4 and resets to 1,000 ms with no simultaneous decoys.
- At 50 seconds rare decoys return; lifetime then falls by only 10 ms per correct tap, to a 200 ms floor.
- After that, every fifteen successful taps adds another possible decoy, raises the chance of a mixed round, and gently reduces the quiet interval, up to six decoys.
- Normal has no time limit and can finish only when all three lives are gone. Losing a life adds a 1.5-second recovery pause before the next round.
- Normal survival time is shown live and freezes when the final life is lost.
- Normal and Zen high scores are stored separately in the browser.
- Each completed run can be submitted under a player name to a shared, mode-specific Top 20 leaderboard. Normal entries also show survival time.
- Leaderboard submissions are validated and throttled, but gameplay still runs in the browser; this prototype board is not suitable for competitive play without server-authoritative anti-cheat.
- Moving the app into the background safely stops the current run.

All balancing values are centralized in [`src/config.js`](./src/config.js).

## Verification

```bash
npm run check
```

The game engine is separate from the browser UI and is covered by deterministic tests for board progression, scoring, lone wrong colors, rare adjacent decoys, gradual timing, Normal life loss, and Zen timing. The leaderboard model is tested for validation, deterministic ranking, mode separation, and the 20-entry cap.

## Why a small PWA

The proof of concept does not need 3D rendering or an engine runtime. A small PWA starts instantly, runs directly on iPhone and Android browsers, works offline after installation (except for the shared leaderboard), and keeps the mechanics easy to change. If playtesting validates the loop, the same rules can later be moved into Unity, Godot, native mobile code, or a Steam build.
