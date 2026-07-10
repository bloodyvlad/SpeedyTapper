# SpeedyTapper mechanics proof of concept

An installable, offline-capable browser prototype for testing the core reaction loop before choosing a visual setting or production engine.

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

For a fully installable/offline iPhone version, serve the repository over HTTPS (for example with GitHub Pages), open it in Safari, use **Share**, and select **Add to Home Screen**. The app includes a web manifest, icons, safe-area support, standalone mode, and a service worker.

## Current rules

- Three lives; wrong taps and expired targets each cost one life.
- A random quiet interval precedes every target.
- Correct taps award 100–1,000 points based on reaction time.
- The first four successful taps use one full-screen cell.
- Hits 4–11 use a 2×2 grid.
- Hit 12 onward uses a 4×4 grid.
- For the first ten seconds, the player color remains fixed and no decoys appear.
- After ten seconds, the player color changes after every correct tap and adjacent decoys appear.
- Response windows progress through 1,000, 500, 300, 250, 200, 150, and 100 ms.
- High score is kept locally in the browser.
- Moving the app into the background safely stops the current run.

All balancing values are centralized in [`src/config.js`](./src/config.js).

## Verification

```bash
npm run check
```

The game engine is separate from the browser UI and is covered by deterministic tests for board progression, scoring, color changes, adjacent decoys, life loss, game over, and the minimum response window.

## Why a small PWA

The proof of concept does not need 3D rendering or an engine runtime. A dependency-free PWA starts instantly, runs directly on iPhone and Android browsers, works offline after installation, and keeps the mechanics easy to change. If playtesting validates the loop, the same rules can later be moved into Unity, Godot, native mobile code, or a Steam build.
