# SpeedyTapper decision log

This file records durable product, architecture, privacy, and release decisions. It is not a backlog, task-status document, or release log. Git commits and Vercel deployments determine release state.

## How to use this log

- Add a record when a choice should guide future work across tasks.
- Use `Proposed`, `Accepted`, `Superseded`, or `Rejected` status.
- Do not silently rewrite an accepted decision. Add a new record that explicitly supersedes the old one.
- Keep experiments and implementation details out unless they create a durable constraint.
- Update the relevant decision in the same reviewed change that alters the product or architecture.

Each new record should include: ID, date, status, context, decision, consequences, and a revisit trigger.

## D-001 — Validate mechanics with a browser PWA first

- Date: 2026-07-12
- Status: Accepted

Context: The primary risk is whether the reaction loop is understandable and replayable, not whether a production engine can render it.

Decision: Maintain a small installable browser PWA as the mechanics proof of concept. Do not choose the eventual Steam, mobile, Roblox, or console implementation solely from this prototype.

Consequences: Iteration and iPhone testing remain fast. Platform-specific production architecture is deferred until playtesting validates the loop.

Revisit when: The mechanic and progression are validated well enough to scope a commercial product.

## D-002 — Preserve the direct see–decide–act loop

- Date: 2026-07-12
- Status: Accepted

Context: Character locomotion, jumping, aiming, and physics add execution delay and change a reaction game into a movement game.

Decision: The proof of concept uses direct tile selection. Themes may add identity, but must not obscure the rule: act on the player's color quickly and avoid other colors.

Consequences: New presentation and monetization ideas must remain separable from the core mechanic. Character-based or 3D variants require separate validation rather than being assumed equivalent.

Revisit when: A separate product deliberately chooses movement or aiming as part of its skill test.

## D-003 — Keep rules deterministic and separate from browser effects

- Date: 2026-07-12
- Status: Accepted

Context: Gameplay balancing changes frequently, while DOM, storage, networking, and audio have platform-specific failure modes.

Decision: Keep balancing and palettes in `src/config.js`, pure state transitions in `src/game-engine.js`, browser wiring in `src/main.js`, platform audio in controllers, shared leaderboard rules in `lib/leaderboard-model.js`, and persistence behind `api/leaderboard.js`.

Consequences: Game-engine behavior must remain deterministic and testable without a browser. UI and controller changes must not duplicate game rules.

Revisit when: The project adopts a production engine or a server-authoritative gameplay architecture.

## D-004 — Maintain two distinct game modes

- Date: 2026-07-12
- Status: Accepted

Context: A fail-state mode and a relaxed score-run mode test different motivations without requiring separate mechanics.

Decision: Normal mode is endless and ends only after all three lives are lost. Zen mode lasts exactly 60 seconds and never removes lives. Both use the same scoring and progression vocabulary unless a later decision explicitly separates them.

Consequences: Normal releases must not add a hidden run timer. Zen mistakes may affect statistics or score, but not lives or duration.

Revisit when: Playtest evidence shows that one mode harms clarity or retention.

## D-005 — Use a minimal leaderboard identity model

- Date: 2026-07-12
- Status: Accepted

Context: Profiles and personal-result tracking add onboarding, privacy, account, and synchronization complexity that the prototype does not need.

Decision: Maintain mode-specific Top 20 leaderboards. Ask for a name after a run and remember only the last validated name as a local form convenience. Do not create player profiles, personal-best records, or local score histories.

Consequences: Production stores shared results in Vercel Blob; local development uses ignored `.data/leaderboard.json`. Validation must remain compatible with legacy rows. The client-authoritative prototype is not anti-cheat secure.

Revisit when: Accounts, verified competition, friends, cross-device identity, or moderation become product requirements.

## D-006 — Use opt-in Web Audio for reaction-critical Sound FX

- Date: 2026-07-12
- Status: Accepted

Context: iPhone browsers require trusted gestures to start or resume audio and can interrupt contexts asynchronously. Media elements introduced unacceptable latency for rapid feedback.

Decision: Sound FX defaults off and uses predecoded Web Audio buffers when enabled. Disabled Sound FX performs no context creation, fetch, decode, cache, or playback work. Resume occurs only from trusted gestures; unready cues are skipped rather than delayed; sources are bounded and cleaned up across restart, backgrounding, interruption, and opt-out.

Consequences: Avoid `HTMLAudioElement`, user-agent sniffing, overlapping high-gain cues, and abrupt non-zero stops. Physical-iPhone testing is required for audio releases. Preserve approved lossless masters and provenance.

Revisit when: Native packaging provides a lower-latency audio layer or browser behavior materially changes.

## D-007 — Treat themes as presentation, not gameplay semantics

- Date: 2026-07-12
- Status: Accepted

Context: Classic and Disco should change identity and material treatment without changing what a color means or how a tile behaves.

Decision: Keep theme palettes and surfaces separate from engine rules. Color-blind glyphs are enabled by default and remain consistent across the HUD, theme previews, and active tiles.

Consequences: Theme work must preserve color semantics, contrast, target readability, and timing visibility. A new theme should not require new scoring or progression code.

Revisit when: A mode intentionally uses theme-specific mechanics.

## D-008 — Version the complete PWA graph with one build ID

- Date: 2026-07-12
- Status: Accepted

Context: Installed iPhone PWAs and service workers can otherwise mix old HTML, modules, styles, and caches.

Decision: Use one `YYYYMMDD-N` build ID across HTML entry references, module imports, the service worker cache, and static release tests. The release integrator assigns the ID after intended changes are combined.

Consequences: A release is incomplete if any stale ID remains. Optional runtime audio stays outside the install-time app shell unless a later decision explicitly changes the policy.

Revisit when: A build pipeline generates hashed assets and release manifests automatically.

## D-009 — Release only clean, committed snapshots

- Date: 2026-07-12
- Status: Accepted

Context: Separate Codex tasks can see and modify the same Local checkout without sharing transcripts. A direct deploy from a dirty checkout can silently combine unrelated work.

Decision: Production must correspond to a tested Git commit. Use separate branches and worktrees for parallel tasks. Prefer a pull request into `main` after a GitHub remote is configured; until then, use reviewed local commits and deploy from an isolated clean worktree at the exact commit.

Consequences: Record the commit SHA, build ID, Vercel deployment ID, immutable URL, and prior rollback target. Keep the previous immutable deployment until production smoke testing passes. Never deploy the dirty shared checkout.

Revisit when: CI/CD reliably builds and deploys only reviewed commits from the remote repository.

## D-010 — Defer server-authoritative anti-cheat

- Date: 2026-07-12
- Status: Accepted

Context: The prototype measures mechanic quality; authoritative event validation would substantially increase scope.

Decision: Keep the current leaderboard explicitly unverified. Do not market it as secure ranked competition.

Consequences: Client submissions remain validated and throttled but can be manipulated. Verified ranking requires a separate backend design decision.

Revisit when: Rewards, public competition, or meaningful ranking create an incentive to cheat.

## D-011 — Adaptive music lifecycle

- Date: 2026-07-12
- Status: Accepted

Context: The prototype benefits from musical identity and audible escalation, but browser autoplay policy, installed-PWA caching, compressed-audio loop seams, and asynchronous context interruption can otherwise produce silent starts, late playback, clicks, or stale audio.

Decision: Keep music independently switchable from Sound FX and default it on while remembering an explicit opt-out. Loading may begin before interaction, but audible playback starts or resumes only from a trusted user gesture. Keep the menu arrangement through the 1×1 warm-up and restore it at Game Over; select richer and faster regions from engine snapshots rather than from duplicated timers. Decode one retained runtime asset, crossfade between explicit sample-aligned loop regions, fade before shutdown, and retain a lossless rollback master plus source notes. Keep optional audio outside the install-time app shell. Cache only the soundtrack after its first service-worker-controlled runtime request; never add Sound FX to that runtime cache.

Consequences: Music stage changes follow engine state rather than duplicate progression rules. Music being enabled does not cause disabled Sound FX assets to be requested, decoded, or cached. The worker must control the page before soundtrack fetching so the first request is retained. Section mastering and runtime boundaries must remain aligned and quiet. Runtime caching, default state, loop boundaries, backgrounding, and interruption behavior require automated tests; physical-iPhone listening remains required before calling an audio release device-validated.

Revisit when: Native packaging changes the audio lifecycle, a production build pipeline emits separate loop assets, or device testing shows that AAC region looping remains unreliable.
