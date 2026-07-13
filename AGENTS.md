# SpeedyTapper repository instructions

These rules apply to every task in this repository. User instructions for a specific task take precedence.

## Start every task safely

1. Run `git status --short` before editing.
2. Read the relevant sections of `README.md` and `docs/DECISIONS.md`.
3. For visual work, also inspect `design-qa.md`; treat it as historical QA evidence, not release truth.
4. For audio work, read `assets/audio/SOURCES.md` and retain provenance and rollback masters.

Codex tasks share the filesystem but not their transcripts. Assume an unexplained dirty file belongs to the user or another task.

- Never reset, discard, overwrite, stash, stage, or commit unrelated changes.
- If intended edits overlap existing uncommitted work, stop and ask the user to resolve ownership.
- Use only one editing task in the shared Local checkout at a time.
- Run parallel implementation in separate worktrees and `codex/<task>` branches.
- `index.html` and `src/main.js` are frequent conflict hotspots; avoid assigning them to parallel tasks.

## Sources of truth

| Concern | Source of truth |
| --- | --- |
| Code and release contents | Git commit |
| PHP production runtime | Hostinger MCP artifact built from the recorded `php-main` commit |
| Legacy rollback runtime | Immutable Vercel deployment attached to its commit |
| Current setup and committed target behavior | `README.md` at the target commit |
| Durable product and architecture decisions | `docs/DECISIONS.md` |
| Task status and backlog | One issue tracker, preferably GitHub Issues once a remote exists |
| Visual review evidence | `design-qa.md` and referenced screenshots |
| Audio provenance and masters | `assets/audio/SOURCES.md` and retained source assets |

Do not use README files, QA notes, or conversation history as proof of what is deployed. Verify the Hostinger alias and exact Git commit; retain the immutable Vercel deployment as the legacy rollback generation.

Proposed decisions and uncommitted experiments must not appear under README committed/current rules or be described as released. Keep them in a clearly labelled unreleased section until the decision is accepted and the implementation is committed.

## Setup and required checks

- Node.js 20 or newer and PHP 8.2 or newer are required.
- Install exact dependencies with `npm ci` when setup is needed.
- Install PHP dependencies with `composer install` when backend setup is needed.
- Start the local app with `npm run dev`; it listens on port 4173 by default.
- Run `npm run check` before every implementation handoff.
- Run `git diff --check` before staging or committing.
- Add or update tests whenever behavior changes.
- When adding a shipped JavaScript module, update the `npm run check` command to syntax-check it explicitly and add deterministic test/import coverage.
- Audio and touch changes require physical-iPhone Safari or installed-PWA testing before being described as production-validated.

## Architecture boundaries

- `src/config.js`: balancing constants, modes, colors, and theme palettes.
- `src/game-engine.js`: deterministic gameplay rules and state; keep it independent of the DOM and browser UI.
- `src/main.js`: DOM rendering, input wiring, navigation, persistence, and controller coordination.
- `src/sound-controller.js`: optional low-latency Sound FX lifecycle.
- `src/music-controller.js`: adaptive music lifecycle when present in an accepted release.
- `src/profile-client.js`: same-origin Google profile and leaderboard client.
- `server/src/`: PHP identity, session, validation, and MySQL repository rules.
- `api/index.php`: PHP HTTP boundary for extensionless `/api/*` routes.
- `server/migrations/`: reviewed, repeatable MySQL schema migrations.
- `lib/leaderboard-model.js` and `api/leaderboard.js`: retained legacy Vercel rollback generation.
- `sw.js`: PWA release graph and cache behavior.
- `test/`: deterministic coverage mirroring behavior and release wiring.

Keep balancing in configuration, rules in the engine, and platform effects in controllers or the UI layer.

## Gameplay and data invariants

- Normal mode is endless and ends only after all three lives are lost.
- Zen mode lasts exactly 180 seconds and never removes lives.
- Wrong colors, inactive cells, empty-board taps, and expired correct targets remain mistakes in Normal mode.
- Independent decoys never use the player's color, live for 450–750 ms, and may overlap. A target cannot reuse a cell that displayed a decoy immediately before that activation frame. Natural expiry awards a dodge; a correct target tap or any failed/ended round clears live decoys without awarding a dodge.
- Speed ratings use the same rounded milliseconds shown to the player: under 250 Godlike, under 350 Perfect, under 450 Great, otherwise Good.
- Hostinger leaderboards are mode- and season-specific and store every accepted run as an immutable result. Public reads show the top five; authenticated profile reads add that player's best result with two neighboring ranks, while a successful submission adds that exact result and its neighbors. Retrying the same run UUID remains idempotent.
- Godlike taps add two steps and Perfect taps add one to a five-step boost meter; overflow carries into the next tier. Great and Good preserve the meter without advancing it. Every five steps unlocks the next multiplier for subsequent taps, up to 5×; every mistake resets the boost to 1×; dodges are neutral and unmultiplied. Apply a multiplier only to the current tap award—never retroactively to the accumulated run score and never to time-based coins.
- Completed signed-in runs use a client-generated UUID for idempotent score and coin submission. Award one coin per cumulative completed minute, carry sub-minute remainder between accepted runs, and never award the same run twice.
- Google ID tokens are verified server-side. Require an explicitly confirmed public nickname before score submission; never import or publish the Google display name. Store only the internal UUID, public nickname, confirmation flag, and one-way Google-subject digest—never raw Google subjects, tokens, email addresses, or passwords.
- PHP production data uses Hostinger MariaDB/MySQL. The Vercel Blob board is a separate read-only rollback generation and is not imported into the clean season.
- The browser-authoritative prototype is not anti-cheat secure; do not describe it as competitive integrity.

## Audio invariants

- Sound FX and Interactive Music default on while remembering explicit opt-outs. While Sound FX is disabled it must not create an `AudioContext` or fetch, decode, cache, or play Sound FX assets.
- Resume audible Web Audio only from a trusted user gesture.
- Skip cues that are not ready; never play them late.
- Suspend or close audio safely on backgrounding and opt-out.
- Avoid `HTMLAudioElement`, user-agent sniffing, uncapped overlapping cues, and abrupt non-zero source stops in reaction-critical audio.
- Retain approved lossless masters or equivalent rollback assets when optimizing runtime formats.
- Record original or third-party source and licence details in `assets/audio/SOURCES.md`.
- Do not replace or delete an approved production audio master without explicit authorization and a recoverable prior version.

## Release IDs and PWA caching

Release IDs use `YYYYMMDD-N`. The release integrator assigns one ID after intended changes are combined; parallel tasks must not independently publish competing IDs.

For every release, update the complete versioned graph:

- asset and entry references plus the inline build ID in `index.html`;
- versioned imports in `src/main.js` and any other version-bearing module;
- `BUILD_ID` and `APP_SHELL` in `sw.js`;
- the expected ID and graph assertions in `test/app-shell.test.js`.

Use `rg` to find stale IDs, then run `npm run check`. Optional runtime audio remains outside the install-time app shell unless a documented decision changes that policy.

## Git and parallel work

- Keep one concrete outcome per branch and task.
- Prefer a pull request into `main` once a GitHub remote is configured.
- Until then, local branches, reviewed commits, and worktrees are authoritative; never claim a push or PR occurred when no remote exists.
- Commit only intentional files. List unrelated dirty files separately in the handoff.
- Do not merge or deploy another task's uncommitted work implicitly.
- `main` should represent a tested, deployable commit—not an integration scratchpad.

## Production deployment

Production deployment requires explicit user authorization.

1. Finish and review the intended change.
2. Run `npm run check` and `git diff --check`.
3. Commit the exact release contents.
4. Confirm the source tree for deployment is clean.
5. Prefer merged `main`; for a manual release, deploy from an isolated worktree checked out at the exact commit.
6. Build a root-flat, allowlisted runtime artifact from `git archive` of that commit in temporary staging. Never package the checkout, tests, docs, package files, or non-runtime audio masters.
7. Install production Composer dependencies in staging and inject the ignored runtime config only into staging. Prefer `~/.config/speedytapper/config.php`; for MCP-only deployment, protected `server/config.local.php` is permitted only when it remains untracked and direct `/server` probes are denied after deployment.
8. Deploy through Hostinger MCP `hosting_deployStaticWebsite` to the exact independent addon website `speedytapper.otcsoft.com`, never to `otcsoft.com` and never to a nested parent-site directory.
9. Let the shared migration runner apply pending idempotent migrations under its advisory lock, then purge only the SpeedyTapper website cache.
10. Smoke-test HTTPS, build ID, service worker, required assets, denied config/internal paths, PHP health/session routes, Google sign-in, and both leaderboard modes.
11. Record the commit SHA, build ID, artifact SHA-256, Hostinger target/path, migration/season ID, and previous immutable Vercel rollback deployment.
12. Retain the previous immutable Vercel deployment until the Hostinger release is verified.

Never deploy a dirty shared checkout, even when unrelated files appear harmless.

## Handoff checklist

Report:

- outcome and files intentionally changed;
- tests and device/browser checks performed;
- remaining limitations or required physical-device validation;
- branch and commit SHA, when created;
- deployment ID and rollback target, when deployed;
- unrelated dirty files that were preserved.

Do not mark work complete merely because code was written; verification and a clear handoff are part of completion.
