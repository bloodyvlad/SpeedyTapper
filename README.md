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

The HTML, stylesheet, and JavaScript module graph share one release version. The service worker bypasses the browser HTTP cache, removes older app caches, and performs a one-time reload when an installed iPhone switches releases. [`vercel.json`](./vercel.json) also keeps the app shell revalidated.

## Current rules

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
- Sound FX is off by default. While it remains off, the app does not create an audio context or fetch, decode, cache, or play audio files. Turning it on resumes audio directly from that Settings gesture, and every Start or Restart gesture verifies it again before gameplay sounds can run. There is deliberately no delayed tap or switch-off sample in the high-speed reaction loop.
- Beta sound uses the standards-based Web Audio API—not an Apple-only API—with an interactive-latency `AudioContext` and predecoded in-memory buffers. A softly opened master gate avoids an iOS output-route pop, and one persistent hum loop uses smooth target automation instead of hard or linear gain edges. Life-loss cues are limited to one at a time, cleared on suspension, and faded out before a quick restart so they cannot clip or resume in a later run. Audio files are excluded from the offline app shell and fetched without browser or service-worker caching. Offline gameplay therefore remains available even when optional sound cannot be loaded.
- Theme, accessibility, and sound preferences are stored on that device.
- The HUD and result screen show the current global top score for the selected mode; no player profile or local result history is stored.
- Each completed run asks for a name and can be submitted to the shared, mode-specific Top 20 leaderboard. After a valid entry, that name is remembered on the device and prefilled on future result screens as a form convenience only; it does not create a player profile, personal best, or local score history. Entries show survival or play time, taps, dodges, fastest reaction, and average reaction.
- Leaderboard submissions are validated and throttled, but gameplay still runs in the browser; this prototype board is not suitable for competitive play without server-authoritative anti-cheat.
- Moving the app into the background safely stops the current run and suspends enabled Beta audio. Starting or restarting a game from the next explicit gesture resumes its audio context.

All balancing values are centralized in [`src/config.js`](./src/config.js).

## Verification

```bash
npm run check
```

The game engine is separate from the browser UI and is covered by deterministic tests for board progression, scoring, empty-board penalties, dodge rewards, reaction statistics, rare adjacent decoys, gradual timing, Normal life loss, and Zen timing. Static UI tests cover the gameplay shortcuts, same-mode restart flow, distinct Classic/Disco material treatments, the default-off Beta sound setting, Web Audio wiring, audio-cache exclusions, and the unified release graph. The sound controller is tested to ensure disabled audio never creates a context or fetches, decodes, or plays media; enabled cues use decoded buffers; and disabling sound stops sources and releases audio resources. The leaderboard model is tested for validation, legacy-row compatibility, deterministic ranking, mode separation, reaction metrics, and the 20-entry cap.

## Why a small PWA

The proof of concept does not need 3D rendering or an engine runtime. A small PWA starts instantly, runs directly on iPhone and Android browsers, works offline after installation (except for the shared leaderboard), and keeps the mechanics easy to change. If playtesting validates the loop, the same rules can later be moved into Unity, Godot, native mobile code, or a Steam build.
