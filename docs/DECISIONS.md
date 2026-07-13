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
- Status: Superseded

Supersession scope: D-014 replaces only the Top 20 capacity and presentation rule. The minimal name-only identity model remains accepted through D-014.

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

## D-012 — Rotate the approved adaptive soundtrack set

- Date: 2026-07-13
- Status: Accepted

Context: Three original soundtrack variants were approved with the same adaptive structure. The game reaches 4×4 at 40 seconds and reintroduces decoys at 50 seconds, but playtesting favors holding the mid-tempo arrangement longer, adding the mature-pressure arrangement around 1:30, and reserving the fastest arrangement for two-minute runs.

Decision: Promote Neon Circuit Refined, Deep Current, and Power Grid as the production soundtrack set. Each track uses 100 BPM for the menu and 1×1 opening, 120 BPM from 2×2 through early 4×4 play, 140 BPM from 90 seconds elapsed, and 168 BPM from 120 seconds elapsed. Advance through the fixed three-track cycle whenever a completed result screen opens, play the newly selected track's menu region there, and retain it for the following run. This supersedes D-011 only where it specifies one runtime asset; its opt-out, trusted-gesture, Web Audio, crossfade, shutdown, cache, and rollback requirements remain accepted.

Consequences: Musical escalation is intentionally not identical to the 40-second grid and 50-second decoy transitions. Runtime selection uses the engine snapshot's authoritative elapsed time, which currently includes each 1.5-second life-loss recovery. Playtesting must review whether response windows and recovery time create the intended felt pacing. Each runtime AAC is cached only after its first service-worker-controlled request and remains outside the install-time app shell. Retain every approved WAV master and the prior soundtrack for rollback.

Revisit when: Real-run data or physical-iPhone listening shows that the 90-second or 120-second thresholds, recovery-time treatment, track order, decoded-memory cost, or transition behavior should change.

## D-013 — Measure reactions from presentation to pointer contact

- Date: 2026-07-13
- Status: Accepted

Context: Starting a reaction clock in a timer callback before DOM work and ending it with a new timestamp inside the pointer handler adds the browser's paint wait and input-dispatch delay to the player's result. Separately scheduling a full response-window timeout after rendering can also leave a target visible after its logical deadline and allow expiry plus queued input to remove two lives.

Decision: Start the run and each active round on a browser animation-frame timestamp, render the tile in that frame, and anchor expiry to an absolute deadline derived from it. Use the original monotonic `PointerEvent.timeStamp` when it is compatible with the current performance clock, with a guarded handler-time fallback. Ignore queued pre-presentation input and input already covered by a deadline resolution.

Consequences: Displayed reaction milliseconds, scoring, progress, and expiry share one presentation-aware clock and no longer include an avoidable full-frame or input-dispatch bias. Animation-frame time is still a browser approximation of physical pixel onset, and pointer time is still an approximation of hardware contact; claims of photon-to-contact precision require external high-speed measurement.

Revisit when: A native runtime exposes display-present and touch-hardware timestamps, browser event time origins change, or device testing finds material rAF-to-pixel variance.

## D-014 — Retain a deeper leaderboard and return compact rank context

- Date: 2026-07-13
- Status: Accepted

Context: A Top 20 board gives most submitted runs no visible placement, while sending or rendering hundreds of detailed rows would add unnecessary network and mobile-browser cost. The existing private Vercel Blob document can hold a larger prototype board without a storage migration.

Decision: Retain the best 1,000 validated results independently for Normal and Zen modes. Public leaderboard reads return only the top five. A successful score submission returns the top five plus the submitted run and up to two neighboring ranks on each side, with absolute rank numbers. Keep D-005's name-only form convenience and its prohibition on profiles, personal bests, and local score histories. This supersedes D-005 only where it specified a Top 20 capacity.

Consequences: Existing positions below 20 were previously discarded and cannot be recovered. The private Blob remains one document and is rewritten through the existing optimistic-concurrency flow; only compact result windows are sent to the browser. A submission below rank 1,000 is not retained. The browser-authoritative ranking remains unverified.

Revisit when: Submission volume, Blob rewrite cost, moderation, pagination, historical rank lookup, or server-authoritative competition warrants indexed storage.

## D-015 — Offer tap-driven melody over pre-recorded state backing

- Date: 2026-07-13
- Status: Accepted

Context: The approved adaptive tracks establish a sticky identity, but their lead plays independently of the player and their large fixed tempo regions understate progression before an abrupt late change. Fully synthesizing and mixing music at runtime would follow gameplay precisely but adds browser CPU, scheduling, and mobile-audio risk. A single long timeline also cannot exactly follow the hit-driven 2×2 transition or different late-game performance.

Decision: Retain D-012's approved soundtrack as the default and add **Interactive Music (Beta)** as a separately remembered, default-off variant under the existing Music master switch. In Interactive mode, correct target taps alone trigger successive notes from a fixed 16-note per-track motif; pitch order depends only on the run's successful-hit number, not color or reaction speed. Misses, dodges, and unready audio do not play or defer notes. Keep bass, drums, percussion, pads, and enrichment in pre-recorded backing-only AAC sprites. Select authored 100–168 BPM backing states from engine snapshots, using grid and phase boundaries plus the mean spawn delay and a response window capped at 400 ms for late-game pace. Move between adjacent states on the next beat through dedicated, bidirectional bridge audio produced in the same render; play tap notes immediately without beat quantization. Lazy-decode only the active Interactive backing and its PCM note bank, while sharing the existing trusted-gesture, rotation, caching, fade, background, and opt-out lifecycle.

Consequences: The legacy and Interactive variants remain parallel and cannot sound simultaneously because one controller replaces its audio context when the setting changes. Pre-recorded states keep playback CPU low and make richness deterministic, but their discrete tempo choices approximate rather than continuously reproduce each player's hit-dependent opportunity curve. A future slowdown mechanic can select a lower authored state through the retained reverse bridges, but no such power-up is part of this decision. Runtime music remains outside the install-time app shell and is cached only after a service-worker-controlled request. Retain the original legacy assets, all new PCM masters, the generated cue manifest, and provenance. Automated AAC/PCM checks do not replace physical iPhone Safari and installed-PWA listening.

Revisit when: Playtesting establishes preferred Interactive defaults, motif balance, transition latency, tempo thresholds, or backing richness; decoded memory proves too high; a slowdown power-up is designed; or native audio makes more continuous tempo control practical.

## D-016 — Host the profile prototype on PHP and MySQL

- Date: 2026-07-13
- Status: Accepted

Context: Cross-device profiles, one stable leaderboard identity, and neighboring-rank queries do not fit the name-only Vercel Blob document. The existing Hostinger Premium plan provides PHP 8.2 and MariaDB/MySQL but not the managed Node.js runtime used by higher hosting tiers.

Decision: Keep the browser PWA and its deterministic JavaScript engine, but serve its account and leaderboard boundary from a same-origin PHP 8.2+ API backed by Hostinger MariaDB/MySQL at `speedytapper.otcsoft.com`. Retain the current Vercel deployment and Blob data as a read-only rollback generation rather than importing those unverified names or scores. Keep credentials outside the repository and web root.

Consequences: The PHP branch has its own schema, migrations, configuration example, deployment instructions, and automated checks. PWA installation preferences do not automatically migrate between the Vercel and Hostinger origins. A production migration requires a clean committed release, database backup/rollback notes, HTTPS, same-origin cookie checks, and a physical-iPhone smoke test.

Revisit when: Traffic, server-authoritative gameplay, real-time multiplayer, or operational load justifies a managed application platform or a dedicated service.

## D-017 — Use Google-only profiles and seasonal personal ranks

- Date: 2026-07-13
- Status: Accepted

Context: A public nickname alone cannot identify one player across browsers, while password recovery, email collection, and several social providers would expand a mechanics prototype into a general account system.

Decision: Authenticate profiles only with Google Identity Services. Verify every Google ID token on the PHP server, key an internal random player UUID to a one-way digest of the verified Google `sub`, and expose only a nickname the player explicitly confirms. Never persist or publish the Google display name. Do not collect or store an email address, a local password, or TikTok, Facebook, or Instagram credentials. Maintain one best leaderboard result per authenticated player, game mode, and clean season. Submit completed runs automatically only after the player has confirmed a public nickname; unsigned players may play but must sign in and choose one before a result can be ranked. This supersedes D-005 and D-014 for the Hostinger profile generation; the retained Vercel rollback remains governed by those earlier decisions.

Consequences: The profile can show its current rank, top percentage, and two neighboring places without ambiguous duplicate identities. A new clean season intentionally starts with no imported Blob records. Logout clears only the local authenticated session; the server profile remains linked to Google. The browser-authoritative scores are still not anti-cheat secure.

Revisit when: Account recovery independent of Google, account deletion/export, child-safety requirements, additional identity providers, or verified competition becomes necessary.

## D-018 — Extend Zen to a three-minute score run

- Date: 2026-07-13
- Status: Accepted

Context: One minute ends before many players experience the mature 4×4 pressure and late soundtrack states.

Decision: Zen mode lasts exactly 180 seconds, never removes lives, and represents its unlimited lives with an infinity symbol. Normal remains endless and ends only when all three lives are lost. This supersedes D-004 only where it specified a 60-second Zen duration.

Consequences: UI copy, timers, deterministic tests, result records, service-worker release wiring, and music playtesting must use the new duration. Existing one-minute Zen results are not imported into the clean profile season.

Revisit when: Completion and replay data shows that three minutes is too fatiguing or prevents useful late-game exposure.

## D-019 — Classify reaction speed and run decoys independently

- Date: 2026-07-13
- Status: Accepted

Context: Round-bound decoys always appearing beside a target are predictable and disappear too slowly. Players also need a readable summary of how their speed was distributed, not only one fastest and one average value.

Decision: Classify each correct reaction by the same rounded millisecond value shown to the player: under 200 ms is **Godlike**, under 300 ms is **Perfect**, under 400 ms is **Great**, and 400 ms or slower is **Good**. Show brief non-blocking overlays and a proportional four-category result bar; persist the four counts with the leaderboard result. Decoys are independent non-player-color entities that may appear at random positions and times, overlap one another, and live no longer than 500 ms. A decoy that expires by itself awards one dodge and the configured average dodge score. Correctly tapping the active target clears every visible decoy without awarding those dodges; a miss, target expiry, restart, or run end also clears them without a dodge.

Consequences: Reaction classification, decoy creation, expiry, clearing, scoring, and statistics remain deterministic engine rules. Browser timers only request engine transitions and render snapshots. Playtesting must tune independent spawn intervals and visible duration without moving those rules into DOM code.

Revisit when: Overlapping decoys obscure the target, dodge scoring dominates reaction scoring, or player data supports different rating boundaries.

## D-020 — Deploy PHP through an isolated Hostinger MCP artifact

- Date: 2026-07-13
- Status: Accepted

Context: Hostinger hPanel Git was configured against the parent website and overwrote `otcsoft.com`. The Hostinger MCP archive endpoint is labelled for static websites, but its implementation uploads and extracts a prebuilt root-flat archive into the exact selected website without inspecting file types. A harmless probe deployed to an independent `speedytapper.otcsoft.com` addon website executed successfully under PHP 8.3. The endpoint rejects a nested `vhost_type: subdomain` target with HTTP 403 but accepts an isolated `vhost_type: addon` website with its own document root.

Decision: Keep GitHub and `php-main` as version history, but perform PHP releases through Hostinger MCP `hosting_deployStaticWebsite` using a curated prebuilt artifact from an exact clean commit. The target must be the independent addon website `speedytapper.otcsoft.com`, never `otcsoft.com` or a directory selected through the parent site. Build from `git archive` in temporary staging; include only runtime browser files, PHP API/server files, `.htaccess`, and production Composer dependencies. Exclude repository metadata, tests, docs, package files, and non-runtime audio sources. Prefer a private home-directory config; when MCP cannot write outside the document root, an ignored artifact-only `server/config.local.php` is permitted because `/server` is denied and production probes must verify that protection. Apply pending idempotent migrations automatically before API dispatch under a database-scoped advisory lock.

Consequences: A release is identified by commit SHA, build ID, and artifact SHA-256 rather than by an hPanel Git hook. The MCP transport requires no browser session, SSH key, or manual file upload. The secret-bearing staging tree and archive must be tightly controlled and never committed. The database user retains schema-change privileges on only the dedicated game database so first-request migration can work. The previous immutable Vercel deployment remains the rollback generation until the PHP release and physical-iPhone flow are verified. This supersedes D-016 only for the production configuration-location exception and deployment/migration procedure; its PHP/MySQL architecture remains accepted.

Revisit when: Hostinger exposes secret injection or a first-class generic PHP deployment API, SSH automation is intentionally enabled, migrations require a separate least-privilege deploy role, or the app moves to managed CI/CD.

## D-021 — Keep leaderboard generations internal

- Date: 2026-07-13
- Status: Accepted

Context: The database needs an operational partition for clean imports, rollback generations, and controlled leaderboard maintenance. Calling that partition a season in the player interface implies a recurring competitive schedule and makes an otherwise simple personal-best system harder to understand.

Decision: Keep the existing `season_id` as an internal storage and API implementation detail. Player-facing copy refers only to a personal best, leaderboard position, and best runs. Any future change of the active leaderboard generation must preserve each profile's personal best, unless a separately accepted product decision explicitly introduces a visible reset before release.

Consequences: No schema migration or API contract change is required. Technical documentation and server tests may continue to use season terminology where it describes the partitioning model, but browser UI copy must not expose it as a gameplay concept.

Revisit when: SpeedyTapper intentionally launches scheduled competitive seasons, reset rewards, archived rankings, or an all-time leaderboard alongside time-limited rankings.

## D-022 — Default the tested Web Audio variants on

- Date: 2026-07-13
- Status: Accepted

Context: Sound feedback and tap-driven music are important to the intended reaction loop, but default-off switches leave most first-time playtests silent. The controllers already preload bounded Web Audio resources, resume audible output only from trusted gestures, and retain explicit opt-outs.

Decision: Default both Sound FX and Interactive Music on for a device with no stored preference. Preserve any explicit `off` preference. Keep Music as the master switch and retain every lifecycle, caching, late-cue, backgrounding, and rollback constraint from D-006, D-012, and D-015. This supersedes D-006 and D-015 only for their default states.

Consequences: A first visit may create contexts and prepare enabled audio before gameplay, while audible resume still requires a trusted gesture. Switching Sound FX off must continue to close its context and prevent further fetch, decode, cache, or playback work. Physical-iPhone Safari and installed-PWA testing remains required before calling this default device-validated.

Revisit when: Opt-out data, latency, memory, accessibility feedback, browser autoplay changes, or physical-device tests show that either audio path should return to opt-in.

## D-023 — Reward fast streaks with score multipliers

- Date: 2026-07-13
- Status: Accepted

Context: Reaction ratings are visible but do not currently create a longer performance arc. A compact meter can reward sustained fast play without changing target rules or adding another input.

Decision: Godlike and Perfect taps advance a five-step boost meter. Each completed group of five unlocks the next multiplier for subsequent correct taps: 2×, 3×, 4×, then a 5× cap. The threshold tap uses the multiplier that was active when it appeared; the newly unlocked level begins on the next tap. Great scores at and preserves the current multiplier without advancing the meter. Good resets to 1× before it scores. Every mistake resets immediately in both modes. Decoy expiry is neutral and its fixed dodge points are never multiplied. Keep deterministic per-tier hit and base-point totals so the PHP boundary can exactly reconcile base score, multiplier bonus, dodges, and total score.

Consequences: The meter appears directly below the board and shows progress toward the next tier or `MAX`. A multiplier affects only the current correct tap; it never rescales the accumulated run total, fixed dodge awards, or time-based coins. Multiplied scores are not comparable with pre-multiplier leaderboard rows, so migration `004_clear_leaderboard_for_multiplier_scoring.sql` removes those rows before the new scoring model goes live.

Revisit when: Playtesting shows that Great should break or advance the meter, the threshold tap should receive the new multiplier, five hits is the wrong cadence, or multiplied scores overwhelm reaction readability.

## D-024 — Credit profile coins from idempotent completed runs

- Date: 2026-07-13
- Status: Accepted

Context: Coins need to accrue from total play time even when a completed run does not improve the player's leaderboard best. Adding currency directly to the one-best leaderboard upsert would lose lower runs and retrying a request could credit the same run more than once.

Decision: Give every started run a client-generated UUID retained through Game Over, sign-in, nickname confirmation, and retries. For authenticated accepted runs, store a completed-run ledger row and transactionally add its duration to the profile's carried sub-minute remainder. Award one coin for each cumulative 60,000 ms and retain the new remainder. Repeating an identical run UUID returns its original result without another credit; reusing that UUID with a different payload is a conflict. Expose the lifetime balance in the utility header immediately left of Leaderboard.

Consequences: Lower non-best runs still earn play-time coins. Unsigned runs can be credited after Google sign-in and nickname confirmation while their Game Over result remains pending. The client remains browser-authoritative, so coins are farmable and must not be sold, redeemed, or treated as secure value without server-verifiable gameplay.

Revisit when: Coins gain spending, rewards, purchases, fraud incentives, offline earning, account deletion/export requirements, or server-authoritative run evidence.

## D-025 — Normalize the runtime mix without replacing approved masters

- Date: 2026-07-13
- Status: Accepted

Context: Objective loudness measurement shows that the approved music and Interactive note assets are already normally mastered around −15 to −13 LUFS, but the runtime music bus attenuates them by about 13 dB. Phone playtesting consequently requires excessive hardware volume, while the life-loss cue sits much closer to a normal audible level.

Decision: Preserve every approved runtime file and rollback master. Raise the shared music master from `0.22` to `0.45`, retain `0.58` relative gain for tap notes, and cap simultaneous tap-note voices at two. Raise the subtle target hum from `0.30` to `0.75` and rebalance the life-loss cue from `0.68` to `0.55`. Do not add a limiter or destructively normalize/re-encode source assets in this pass.

Consequences: Music becomes about 6.2 dB louder, the ambient cue becomes audible without competing with targets, and the failure cue remains clear without dominating the raised soundtrack. The two-note cap retains conservative headroom for the worst supported backing-plus-note mix. Automated gain tests cannot replace physical-iPhone Safari and installed-PWA listening before production validation.

Revisit when: Physical-device listening finds the soundtrack still too quiet, note attacks become masked, the hum distracts from play, a user volume control is added, or a proper output limiter permits a hotter mix.

## D-026 — Relax speed bands and decoy cadence without removing mastery

- Date: 2026-07-13
- Status: Accepted

Context: Playtesting finds the prior 200/300/400 ms rating bands unnecessarily strict, late decoys can begin only 100 ms apart, and a merely Good correct tap erases too much earned streak progress. The compact five-pixel streak line also hides the mechanic.

Decision: Classify the same rounded reaction value as Godlike below 250 ms, Perfect below 350 ms, Great below 450 ms, and Good otherwise. Godlike and Perfect continue to advance the five-hit meter; Great and Good preserve the current progress and multiplier without advancing it; mistakes remain the only reset. Raise decoy phase intervals and enforce a 300 ms late-game onset floor while retaining 300–500 ms lifetimes and occasional overlap. Replace the thin progress line and visible fraction with a large animated gradient fill, an explicit `x1`–`x5` label, and a glow at full charge. This supersedes D-019 and D-023 where their thresholds, Good reset, cadence, or meter presentation conflict.

Consequences: More correct taps retain earned multiplier progress, ratings better match current phone playtests, and decoys remain independent without clustering into unreadable bursts. Scores produced under these rules are not directly comparable with older rows; production deployment needs an explicit leaderboard-retention or reset decision because this change does not itself authorize data deletion.

Revisit when: Telemetry or playtesting supports different bands, Good should advance rather than preserve, a 300 ms decoy gap feels sparse, the meter competes with the board on small screens, or leaderboard continuity is resolved another way.

## D-027 — Let Great reactions advance the speed streak

- Date: 2026-07-13
- Status: Accepted

Context: Playtesting at an active multiplier showed that Great reactions left the visible streak fill unchanged. Although this matched D-026, it looked like an intermittent meter failure because Great is presented as a successful fast rating beside Godlike and Perfect.

Decision: Godlike, Perfect, and Great reactions each advance the five-step streak meter. Good reactions remain neutral: they score with and preserve the current progress and multiplier, but do not advance it. Mistakes still reset the meter and multiplier, and decoy dodges remain neutral and unmultiplied. The PHP submission boundary counts all three advancing ratings when validating whether a reported multiplier milestone is possible. This supersedes D-026 only where that decision excluded Great from streak advancement.

Consequences: The visible meter now responds to every reaction rated Great or better, matching player expectations. Scores under this rule can rise faster than under D-026 and are therefore not directly comparable with earlier leaderboard rows; this decision does not authorize clearing production data.

Revisit when: Playtesting indicates that Great makes multipliers too easy to sustain, Good should advance, the five-hit milestone needs adjustment, or leaderboard comparability requires a formal season boundary.

## D-028 — Show the active multiplier tier beneath streak progress

- Date: 2026-07-13
- Status: Accepted

Context: A dark track at every multiplier makes the larger streak meter communicate only progress toward the next tier. Players also need the already-earned x2, x3, or x4 state to remain visually obvious while a new five-hit streak is filling.

Decision: Keep the x1 track dark. At x2 through x5, give the track and multiplier label one shared tier color—green, blue, violet, then gold—and layer the existing animated progress gradient above the solid tier background. Keep the streak label above both layers and retain the full-meter glow.

Consequences: The base color communicates the active multiplier even at zero progress, while the animated overlay continues to communicate progress toward the next multiplier. The meter gains no new text or gameplay rule.

Revisit when: Physical-device playtesting finds the saturated base too bright, the overlay difficult to distinguish from a tier color, or the tier palette conflicts with target recognition.

## D-029 — Weight streak progress by reaction quality

- Date: 2026-07-13
- Status: Accepted

Context: Treating Great like the two faster ratings made the multiplier progress too quickly, while giving Godlike and Perfect identical progress did not reward the highest reaction tier. The fully opaque active-tier track also competed with the animated progress overlay.

Decision: Godlike adds two steps and Perfect adds one to the five-step meter. Great and Good remain neutral: they preserve the current progress and multiplier without advancing either. Carry a Godlike step beyond an unlock into the next tier, score the unlocking tap with its pre-unlock multiplier, and clamp x5 to a full meter. Retain the solid multiplier-label colors but render x2–x5 track backgrounds at 50% opacity beneath the animated gradient. This supersedes D-027 and D-028 where their progression weights or background opacity conflict.

Consequences: A Godlike-only path reaches x2 after three taps and produces lower-tier hit buckets of 3, 2, 3, and 2 on the way to x5. PHP validation uses the weighted step total and these lower bounds while retaining exact base-score and multiplier-bonus reconciliation. Scores are not directly comparable with D-027 runs; this decision does not authorize clearing production leaderboard data.

Revisit when: Playtesting finds two-step Godlike progress too generous, neutral Great/Good reactions make the meter feel stalled, carried overflow is unclear, or the translucent tier background is too subdued.

## D-030 — Couple adaptive music and tap pitch to programmed game pace

- Date: 2026-07-13
- Status: Accepted

Context: The approved Interactive backing already contains twelve increasingly fast and rich authored states, but runtime selection inferred pressure from elapsed time and timing opportunities. The legacy soundtrack separately waited for exact 90- and 120-second thresholds. Those duplicate clocks can make the music lag or disagree with the phases the engine actually programmed, and the tap-note register stayed static while the backing intensified.

Decision: Make the engine expose one bounded `paceLevel` derived only from board phase and challenge tier. Map the twelve Interactive states directly to levels 0–11 and map the legacy regions from the same level; do not use reaction performance or an independent soundtrack timer. Keep adjacent authored bridges and evaluate their audio-clock completion when selecting the audible note register. Lift the existing five-degree tap-note bank diatonically as soundtrack richness increases, wrapping into an octave at no more than 2× playback rate. Retain every approved runtime file, bridge, source master, and provenance record. This supersedes the timing-selection portions of D-012 and D-015 without changing their track rotation, caching, lifecycle, opt-out, or rollback requirements.

Consequences: Faster targets and higher programmed decoy pressure now have one authoritative audiovisual progression. Measured reaction milliseconds do not directly select the soundtrack, although successful play still advances challenge tiers; delayed JavaScript timers cannot move tap pitch ahead of the bridge actually heard. No audio binary is regenerated or replaced.

Revisit when: Playtesting supports a different phase-to-section curve, a gameplay mechanic intentionally lowers pace, the octave lift masks attacks on phone speakers, or richer authored note banks replace runtime modal reuse.

## D-031 — Rank every accepted profile result

- Date: 2026-07-13
- Status: Accepted

Context: The completed-run ledger already records every authenticated run for idempotent coin accounting, while the leaderboard retained only one best row per profile and mode. Players now need their lower and later runs to remain visible as distinct competitive results without losing the convenience of a single best profile position.

Decision: Insert every accepted run as an immutable leaderboard row keyed by its stable run UUID. Remove only the unique `(season_id, player_id, mode)` constraint and replace it with a non-unique lookup index; preserve all current rows, profiles, coins, and completed-run history. Continue to show a profile's best row and Top percentage in profile/utility views. A successful submission instead centers its returned ±2 window on that exact result while still returning the profile's best rank separately. Public reads retain the top five. This supersedes D-018 and D-021 only where they require one best stored row or describe the board as best runs; internal season handling and hidden player-facing season terminology remain accepted.

Consequences: One player can occupy several leaderboard positions and `totalEntries` counts ranked results rather than distinct players. `improved` remains meaningful as an indication that the submitted result became the profile best. Retrying an identical run UUID cannot add a second row or award coins twice. Migration `005_allow_multiple_leaderboard_results.sql` performs no leaderboard reset; rolling old application code back after duplicates exist is not schema-compatible without first restoring the new code or reconciling rows.

Revisit when: The board needs a per-player display cap, distinct-player rankings, pagination/history UI, retention limits, visible seasons, account deletion/export, or server-authoritative anti-cheat.

## D-032 — Lengthen decoys while halving their average opportunity rate

- Date: 2026-07-13
- Status: Accepted

Context: Playtesting finds 300–500 ms decoys too fleeting to read, while their opportunity cadence remains too frequent and predictable. Simply doubling every minimum would remove overlap from phases that are intended to permit multiple visible decoys and would not resolve the direct decoy-to-target replacement race when expiry and activation occur before one paint.

Decision: Increase random decoy lifetime by exactly 50% to 450–750 ms. Approximately double mean opportunity intervals across progression so average generation frequency is roughly halved, while widening selected ranges down to a 600 ms floor and compensating with longer upper gaps so occasional overlap remains possible. Retain the existing simultaneous-decoy caps. Record every naturally expired decoy cell and exclude it from the next target selection even when expiry was processed before target activation; if every otherwise-free cell is reserved, active-decoy safety takes priority and the engine may fall back to a recently expired cell. This supersedes D-019 and D-026 where their decoy lifetime, cadence, or target-cell reuse rules conflict.

Consequences: Decoys remain readable for longer but arrive less often, timing is less predictable, and late progression can still place more than one on screen. The next correct target no longer appears to replace a just-expired wrong color under either callback order. Existing scoring, dodge awards, color exclusion, active caps, and target pace are unchanged.

Revisit when: Physical-phone playtesting finds 750 ms too distracting, 600 ms lower gaps recreate clutter, overlap is too rare, long upper gaps feel empty, or a paint-confirmed idle transition becomes preferable to one-target cell reservation.

## D-033 — Use uniform native tap cues and rebalance Interactive Music

- Date: 2026-07-14
- Status: Accepted

Context: Playtesting of D-030's runtime pitch lift found that 2× Web Audio playback compressed selected half-second tap slots to a quarter-second, halved their releases, and made the motif alternate between full and abruptly short high notes. A separate 1.08× accent also varied cue loudness. Measurement shows that backing crossfades return to unity after 24–120 ms and do not cause sustained attenuation; the larger phone-speaker imbalance comes from midrange tap cues competing with bass-heavy backing.

Decision: Retain D-030's engine-driven backing-state mapping but remove its runtime tap-register lift. Replace the active note banks with versioned, lossless 48 kHz/16-bit mono banks containing the complete fixed 16-cue motif. Render every cue natively with the same 20,160-frame sound envelope and 3,840-frame zero tail inside a 24,000-frame slot, RMS-normalize the series, play only at 1×, and apply no per-position accent. Keep the shared music master at `0.45`, raise Interactive backing loops and bridges to `1.25` relative gain, and lower tap cues to `0.34`; retain the two-voice cap and every prior backing asset. Keep the superseded one-octave banks as rollback masters and remove them from current runtime requests and service-worker music caching. This supersedes D-030 only for tap-register lifting and D-025 only for Interactive backing/note gain.

Consequences: The approved melody order remains sticky and immediate, but every occurrence now has identical wall-clock length, attack/release timing, and nominal energy regardless of game pace. Interactive backing becomes about 6.6 dB more prominent relative to the old tap-gain relationship before accounting for the newly equalized cue assets. Legacy music, backing composition, tempo/richness progression, Sound FX, and their masters remain unchanged. Automated duration, energy, transition, and headroom checks do not replace physical-iPhone Safari and installed-PWA listening.

Revisit when: Physical-device listening supports a different backing/tap ratio, the fixed register becomes masked in richer states, a limiter allows more headroom, or a future native-pitch bank deliberately restores register progression without time compression.
