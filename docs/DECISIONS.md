# SpeedyTapper decision log

This file records durable product, architecture, privacy, and release decisions. It is not a backlog, task-status document, or release log. Git commits and recorded Hostinger deployments determine current release state; retained Vercel references describe only the legacy rollback generation.

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

## D-033 — Preview Misha as a nickname-triggered cosmetic easter egg

- Date: 2026-07-13
- Status: Accepted

Context: A full pet shop would require spendable-coin, inventory, ownership, and profile-avatar decisions that are outside the current prototype. The generated Misha pixel cat can still validate whether a companion adds charm without distracting from the direct reaction loop.

Decision: Unlock Misha only for an authenticated, confirmed profile whose normalized nickname case-folds to `misha_boy`. Show the animated cat below the Profile shortcut at the same dialog position across every non-game view. During a run, move Misha above the Speed streak meter and turn him left or right only after a board tap is accepted, using the tap position relative to the board midpoint. Keep the cat decorative, pointer-transparent, reduced-motion safe, and entirely separate from scoring, streak progress, coins, ranking, and leaderboard identity. Do not add a shop, purchasing, inventory, or pet field to the profile schema in this release.

Consequences: The nickname is a discoverable presentation switch rather than secure ownership, and any confirmed profile may use it. The sprite joins the versioned offline app shell. Responsive and physical-iPhone checks must confirm that the cat does not cover targets, the response timer, streak information, navigation, or dialog copy.

Revisit when: A pet shop, permanent ownership, spendable coins, selectable companions, or leaderboard pet portraits receive a separately accepted product decision.

## D-034 — Rename Normal to Arcade and deepen the Misha preview

- Date: 2026-07-13
- Status: Accepted

Context: `Normal` describes the endless three-life rules but reads like a technical default beside Zen. The Misha easter egg also needs enough non-game behavior and physical context to test whether a companion feels alive, especially on the shorter viewport used by the 2022 iPhone SE.

Decision: Present the endless three-life mode as **Arcade** everywhere players choose, review, or hear about the mode, while retaining `normal` as its engine, storage, database, leaderboard, and API identifier. On the main menu only, place Misha on a white cat climber with a light-blue pouch at the top; keep Misha alone at the established upper-right anchor on every other non-game view. After exactly five seconds without a non-game pointer tap, switch Misha to a sleeping pose. Any non-game pointer tap wakes him, turns him toward the tap relative to the visible viewport midpoint, and restarts the five-second timer. Gameplay cancels the idle timer, keeps the existing accepted-board-tap direction behavior, and raises the Misha/streak composition until only his ears and upper head overlap the non-interactive board frame. Render Misha at 48px on compact/short phone viewports and 64px elsewhere. Keep all companion layers decorative, pointer-transparent, reduced-motion safe, and gated by the existing `misha_boy` easter egg.

Consequences: Existing `normal` leaderboard rows, routes, profile ranks, migrations, and clients remain compatible while player-facing copy changes to Arcade. The sprite gains one sleeping frame and the climber becomes a versioned offline-shell asset. Duplicate profile-session renders must preserve the current pose and idle deadline; entering and leaving gameplay must cancel or restart the timer without allowing stale callbacks to sleep a hidden cat. Short-phone and physical-iPhone QA must confirm the larger compact sprite and board overlap do not block navigation, streak information, or reaction targets.

Revisit when: Arcade needs a different ruleset, protocol identifiers can be migrated safely, Misha should sleep during gameplay, the climber belongs on additional views, or physical-device testing calls for different compact sizing or board overlap.

## D-035 — Verify ranked runs with server-issued attempts and chronological proofs

- Date: 2026-07-13
- Status: Accepted

Context: Public points and profile coins now motivate direct API tampering. The aggregate PHP boundary can reconcile arithmetic but cannot prove that a run started, lasted as claimed, ended under the mode rules, or contained the submitted reactions and dodges. A forged internally consistent request can currently mint hundreds of millions of points and days of coin credit immediately.

Decision: Before ranked play, require a Google-authenticated profile with a confirmed nickname, issue a random run UUID from PHP, and bind it to that player, an opaque browser session key, build, mode, ruleset, proof version, server start time, and one-time database state. Keep at most one issued attempt per player. Record target presentation, pointer input/handling, misses, every independent decoy opportunity (including ignored opportunities), decoy activation/natural expiry, and finish as compact ordered tuples. At completion, submit that proof instead of authoritative score aggregates. PHP replays the transitions, derives every point/rating/multiplier/dodge/completion statistic, confirms that server elapsed time covers the proof, rejects or withholds omitted/sustained-late timers and implausible reaction distributions, then atomically claims the event-trace hash, consumes the run, inserts immutable proof/result/coin records, and awards only protocol-verified time bounded by server elapsed time. Retire aggregate `POST /api/leaderboard`; add a pre-parse authenticated-session finish limit plus persisted per-player start/completion limits in MySQL. Require a rotating session CSRF token on every mutation and a restrictive Content Security Policy at the web boundary.

Classify pre-release rows as `legacy`; they remain visible until reviewed because their gameplay cannot be reconstructed. Structurally valid but conservatively high-risk new runs use `review`, are excluded from ranking, and receive no coin credit. Moderation operates on exact IDs, appends an audit event, uses reversible quarantine before logical deletion, and recalculates coins plus carried remainder from eligible completed time. Never delete a player or completed-run proof as routine cleanup.

Consequences: This supersedes D-010 and D-024 where they accepted client-generated ranked runs, client-authoritative duration, or unverified coin credit. It narrows D-031 so only `legacy` and `verified` rows participate in ranks; `review`, `quarantined`, and `deleted` rows remain audit history. Direct aggregate editing, compressed-duration coin minting, omitted decoy cadence, impossible state transitions, cloned traces, and duplicate rewards are rejected or withheld. Offline/API-unavailable and signed-out games remain local practice and can never be promoted later. The score formula now uses the same rounded reaction milliseconds shown to the player so PHP and JavaScript derive identical integer results. Integer proof timestamps tolerate the one-millisecond equality boundary where a browser transition occurred fractionally before a decoy expiry.

This protocol does not attest a human. A modified browser can automate visible targets, omit physical mistakes, or synthesize a plausible proof in real time. Describe rows as **protocol verified**, not human verified, secure ranked, or bot-proof. Stronger protection requires deterministic server-seeded schedules plus periodic real-time checkpoints or a trusted native client; even those do not eliminate computer vision and automation.

Revisit when: cheating pressure justifies seeded cross-runtime board replay, periodic event checkpoints, best-per-player public ranking, stronger bot analysis, device attestation, a native runtime, or coins acquire purchasable or redeemable value.

## D-036 — Use uniform native tap cues and rebalance Interactive Music

- Date: 2026-07-14
- Status: Accepted

Context: Playtesting of D-030's runtime pitch lift found that 2× Web Audio playback compressed selected half-second tap slots to a quarter-second, halved their releases, and made the motif alternate between full and abruptly short high notes. A separate 1.08× accent also varied cue loudness. Measurement shows that backing crossfades return to unity after 24–120 ms and do not cause sustained attenuation; the larger phone-speaker imbalance comes from midrange tap cues competing with bass-heavy backing.

Decision: Retain D-030's engine-driven backing-state mapping but remove its runtime tap-register lift. Replace the active note banks with versioned, lossless 48 kHz/16-bit mono banks containing the complete fixed 16-cue motif. Render every cue natively with the same 20,160-frame sound envelope and 3,840-frame zero tail inside a 24,000-frame slot, RMS-normalize the series, play only at 1×, and apply no per-position accent. Keep the shared music master at `0.45`, raise Interactive backing loops and bridges to `1.25` relative gain, and lower tap cues to `0.34`; retain the two-voice cap and every prior backing asset. Keep the superseded one-octave banks as rollback masters and remove them from current runtime requests and service-worker music caching. This supersedes D-030 only for tap-register lifting and D-025 only for Interactive backing/note gain.

Consequences: The approved melody order remains sticky and immediate, but every occurrence now has identical wall-clock length, attack/release timing, and nominal energy regardless of game pace. Interactive backing becomes about 6.6 dB more prominent relative to the old tap-gain relationship before accounting for the newly equalized cue assets. Legacy music, backing composition, tempo/richness progression, Sound FX, and their masters remain unchanged. Automated duration, energy, transition, and headroom checks do not replace physical-iPhone Safari and installed-PWA listening.

Revisit when: Physical-device listening supports a different backing/tap ratio, the fixed register becomes masked in richer states, a limiter allows more headroom, or a future native-pitch bank deliberately restores register progression without time compression.

## D-037 — Make pets durable spendable cosmetics

- Date: 2026-07-14
- Status: Accepted

Context: The Misha preview validated a companion in the menu and reaction layout. The accepted follow-up needs five selectable pets, permanent ownership, server-side coin spending, richer directional poses, shop previews, and current-pet leaderboard portraits.

Decision: Add Pet Shop above Settings with the stable catalog `foka/10`, `kesha/20`, `tauta/50`, `misha/100`, and `pancake/500`. Store catalog rows, profile ownership, and one equipped choice in MySQL. Use one CSRF-protected same-origin operation for both actions: an unowned pet atomically locks the player, checks and debits its exact price, records ownership, and equips immediately; an owned pet changes selection without another charge. Existing confirmed `misha_boy` profiles receive one free Misha entitlement only through the migration. Leaderboard reads expose the profile's current selection rather than snapshotting a pet into each historical result. Keep every companion cosmetic.

Use five directional poses for the four animals plus their authored idle behavior. Pancake retains its supplied orientation and mirrors only for left-facing taps. Main-menu homes appear only in menu/shop contexts; gameplay keeps the selected pet above the Speed streak meter with pointer-transparent rendering and reduced-motion support. This supersedes D-033's nickname-only entitlement and no-shop rule and D-034's Misha-only presentation where they conflict.

Consequences: Purchases and run credits serialize on the player row, ownership is durable, retries cannot charge an owned pet again, and all runtime sprites/habitats enter the versioned offline shell. Asset provenance is retained in `assets/pets/SOURCES.md`; physical-iPhone Safari and installed-PWA checks remain required before the visuals are described as device-validated.

Revisit when: Pets gain gameplay effects, refunds, gifts, randomized acquisition, real-money value, historical cosmetic snapshots, additional animation states, or distribution rights require replacing a supplied source.

## D-038 — Make Zen targets persistent and reaction-adaptive

- Date: 2026-07-14
- Status: Accepted

Context: A fixed target deadline makes the three-minute no-lives mode feel like Arcade without its terminal consequence. Zen needs continuous flow while remaining deterministic enough for browser/PHP proof parity.

Decision: Keep each correct-color Zen target visible until the player taps it correctly or the exact 180-second run deadline arrives. Wrong-color, decoy, and empty-board inputs remain mistakes and reset the boost, but they clear live decoys and retain the target. Zen has no target-response deadline. Begin its target-to-target quiet interval at 1,000 ms and, after each correct tap, move that interval halfway toward the rounded reaction time. Natural decoy expiry records a dodge but awards zero Zen points. Implement the identical transition rules in the JavaScript engine and PHP proof replay.

Consequences: Zen measures sustained score and self-selected cadence rather than survival. A slower reaction also slows the following presentation, while fast correct play accelerates it. The mode still ends exactly at 180,000 logical milliseconds and submits a chronological proof; old issued attempts are invalidated by the new build and ruleset identifiers.

Revisit when: Playtesting favors a minimum/maximum cadence clamp, Zen should retain multiplier progress through mistakes, or adaptive timing creates undesirable score incentives.

## D-039 — Make achievements durable, verified, and transactionally connected to pets

- Date: 2026-07-14
- Status: Accepted

Context: Six planned achievements include **Buy a pet**, which could not be completed safely before Pet Shop ownership existed. A browser click is not proof of purchase, and unverified legacy or held runs must not mint achievement rewards.

Decision: Store per-player achievement unlock and claim state in MySQL. Unlock gameplay achievements only from protocol-verified, coin-eligible completed runs. Claims are CSRF-protected, idempotent, and append a positive `achievement_reward` coin-ledger event. Unlock **Buy a pet** inside the first-purchase database transaction after the player debit and ownership insert and before commit; insufficient funds, rollback, an already-owned pet, or a mere Buy click do not qualify. Refresh achievement presentation only after the committed purchase response.

Consequences: Purchase ownership and its achievement cannot disagree after a successful transaction. Verified achievement unlocks and claimed rewards are durable player progression and are not automatically revoked when a contributing run is later moderated; this is an explicit product policy, while unverified legacy/review/quarantined runs never unlock them in the first place.

Revisit when: Achievement rewards gain real-money value, moderation must revoke source-specific unlocks, or achievements need multiple contributing-run references.

## D-040 — Reconcile spendable coins with an immutable debt-aware economy ledger

- Date: 2026-07-14
- Status: Accepted

Context: Recomputing a wallet only from eligible play time would recreate coins already spent on pets and erase achievement rewards. Quarantining cheated earnings after they were spent also cannot be represented safely by clamping a recalculated balance to zero.

Decision: Retain immutable play-credit events and add negative `pet_purchase` plus positive `achievement_reward` ledger events. Define net entitlement as eligible verified/retained play credit plus economy events. Store either a nonnegative spendable balance or a nonnegative `coin_debt`, never both; future run and achievement credits pay debt first. Moderation recomputes the full eligible timeline and appends a reconciliation event instead of deleting economic history.

Consequences: Reversible score moderation no longer grants free pets, erases legitimate rewards, or leaves already-spent revoked coins as unearned purchasing power. Purchases remain serialized on the player row. The currency still has no real-money or redeemable value, and production migrations must preserve existing legitimate balances while introducing the exact ledger model.

Revisit when: Refunds, gifts, chargebacks, real-money purchases, cross-profile transfers, or an external accounting system are introduced.

## D-041 — Align non-game pets and turn them relative to their own position

- Date: 2026-07-14
- Status: Accepted

Context: Physical iPhone SE playtesting found the shop animals visually too low against their beds and surfaces, with a smaller version of the same issue in the menu. The existing direction resolver also divided the whole viewport or board into horizontal bands. Because the menu pet is anchored near the right edge, a tap beside that pet selected a full-right pose and made its half-left/right poses effectively unreachable near the animal.

Decision: Raise Pet Shop sprites by a base 8 px and menu sprites by a base 4 px; raise Foka and Kesha an additional 5 px in the shop and 2 px in menus, while keeping the approved gameplay sprite placement unchanged. Render Misha above both climber layers. Resolve the four directional animals from the actual visible 64 px pet-sprite center using both pointer coordinates. A tap horizontally centered within 2 px keeps the front pose. Otherwise, an angular displacement up to and including 30 degrees from the vertical axis selects the corresponding persistent half-left or half-right pose; a wider displacement through 90 degrees selects the full left or right pose. Keep Pancake's authored binary left/right behavior.

Consequences: Non-game pets sit higher without changing habitat assets, card dimensions, board clearance, or gameplay scoring. Direction now follows the pet instead of the screen layout, so the same nearby tap behaves consistently across compact phones, menus, and gameplay. Automated geometry and responsive browser checks do not replace a physical iPhone Safari/PWA confirmation of the corrected composition.

Revisit when: Another habitat needs a pet-specific baseline, taps above the pet should use different poses, more direction frames are authored, or device testing favors a larger front dead zone or a threshold other than 30 degrees.

## D-042 — Separate selected-pet ownership from visibility

- Date: 2026-07-14
- Status: Accepted

Context: A single equipped state cannot express the requested shop actions. Hiding the current pet must remove it from menus, gameplay, and leaderboard portraits without selling it or forgetting which owned pet should receive the **Show** action.

Decision: Retain one durable selected pet row and add a persistent visibility flag. Buying or selecting a pet always selects and shows it. The selected visible pet presents **Hide**; the same pet while hidden presents **Show**; every other owned pet presents **Select**; and unowned pets retain **Buy**. A hidden selection remains owned and remembered, but the public `equippedPetId` is `null` and leaderboard joins exclude hidden selections. Visibility changes are authenticated, same-origin, CSRF-protected mutations scoped to the exact selected pet.

Consequences: Hide and Show are reversible presentation choices with no coin, achievement, scoring, or ownership effect. Selecting a different owned pet also shows it. Profile payloads distinguish `selectedPetId`, `petVisible`, and the compatibility/display field `equippedPetId`, allowing older clients to keep a safe null-equipment interpretation.

Revisit when: Profiles need multiple simultaneously visible companions, per-screen visibility, pet refunds, or historical leaderboard portraits.

## D-043 — Authorize exact-result moderation and reset rewards by economy generation

- Date: 2026-07-14
- Status: Accepted

Supersession note: Accepted D-065 supersedes this record's all-value wallet reset and all-cosmetic removal. Its backend-first rule resets earned progression only while preserving purchased value, refund debt, paid entitlements, and paid/mixed-funded cosmetics.

Context: Protocol verification raises the cost of direct aggregate forgery but does not attest a human, and suspected automated results already require operator review. Coins and pets make deleting only a public row insufficient: simply setting a wallet to zero can be undone by later reconciliation, deleting purchase rows creates idempotency-key collisions, and client-side or score-derived administrator status would be forgeable.

Decision: Store leaderboard administrator roles against internal player UUIDs in MySQL and derive the public `isAdmin` capability from that role on every profile read. Bootstrap the initial role only if the two exact known production result UUIDs still share one immutable player owner and retain their expected Arcade/Zen mode, 77,825 Arcade score, active status, and common leaderboard generation; never authorize by score, nickname, rank, email, Google claim, or browser state at runtime. Expose bounded full-list, conservative scan, exact-detail, quarantine, and delete-and-reset routes. Require authentication on every route; additionally require same-origin CSRF, a Google authentication no more than 15 minutes old, exact result UUID, expected current status, explicit confirmation, and a reason for mutations. Delete-and-reset also requires the exact internal player UUID returned with the selected row. Refuse self-moderation and moderation of another administrator.

Require quarantine before delete-and-reset. In one player-first transaction, logically delete only the selected result, revoke its linked completed run when present, abandon outstanding issued attempts, remove every owned/selected pet for that player, reset spendable coins, debt, sub-minute remainder, current total play, and current total collected coins, then increment an unsigned economy generation. Tag all new completed runs and economy ledger events with the locked player's generation; include that generation in repurchasable pet and achievement event keys; and filter future reconciliation and historical reward eligibility to the current generation. Preserve achievement state plus every proof, run, moderation event, ledger row, and a dedicated immutable reward-reset record. Retrying the same trigger UUID returns the original reset and must not erase value earned afterward.

Consequences: The administrator can inspect scan evidence without treating flags as automatic guilt, and destructive actions remain exact, two-stage, attributable, and auditable. Removed pets can be purchased again, while rewards from earlier generations cannot reappear through reconciliation or late achievement synchronization. A long-lived authenticated session must complete Google sign-in again before destructive moderation. The browser still cannot prove that a ranked player is human, so moderation remains a reviewed operational judgment rather than an automatic anti-cheat verdict.

Revisit when: Multiple administrator roles need approval workflows, account-wide result quarantine is desired, moderation appeals require restoration across economy generations, real-money value is introduced, or a stronger attestation/checkpoint protocol replaces operator review.

## D-044 — Gate progression behind identity and retain pet habitats outside gameplay

- Date: 2026-07-14
- Status: Accepted

Context: Anonymous practice must remain instantly playable, but showing an active wallet, shop, or achievements to a signed-out player suggests that local runs earn durable progression. Pet bedding also supplies visual identity on result and utility screens, while the same furniture adds clutter beside the reaction-critical board. Achievement cards repeated **In progress** even though their locked state was already visually clear.

Decision: Keep Arcade and Zen available without authentication, but award no anonymous coins, scores, pets, or achievements. Present the coin control, Pet Shop, and Achievements as visually gated with `aria-disabled` semantics while leaving their explanatory action available; activating any of them shows the same Google-login benefits message and a route to Profile. Repeat that message after a signed-out completed run and on the signed-out Profile view. Do not create an anonymous wallet or local progression record.

Show the selected pet together with its bedding, climber, perch, floe, or glow surface on every non-game screen, including public leaderboard portraits. During gameplay show only the pet and omit the habitat. Label the Pet Shop amount with **Your balance:**. Remove **In progress** from locked achievement cards and render each reward as `+N` beside the shared gold coin mark.

Consequences: Practice stays frictionless while durable economy surfaces make their authentication boundary explicit. The controls are not natively disabled because a native disabled control cannot explain why it is unavailable; the server remains the authority and rejects anonymous progression requests regardless of browser markup. Pet identity is consistent across menus without placing extra furniture near high-speed input. Signed-out completion copy may repeat until login, but it does not infer, store, or track an anonymous player profile.

Revisit when: Guest accounts gain server-side migration into Google profiles, anonymous progress becomes a deliberate product feature, gameplay furniture is proven non-distracting on physical devices, or achievement states need richer progress measurements.

## D-045 — Make Zen endless unranked practice without decoys or rewards

- Date: 2026-07-14
- Status: Accepted

Context: Timed Zen inherited the full decoy, proof, leaderboard, achievement, and coin pipeline from Arcade. That made the supposedly relaxed mode another pressured three-minute competition and contradicted the requested endless, distraction-free practice experience.

Decision: Make **Zen** endless local practice. It has unlimited lives, no target deadline, no decoys, no automatic completion, no ranked run ticket, no result submission, no leaderboard write, no achievement unlock, and no coin credit. Its current target survives mistakes. The next target delay starts at 1,000 ms and moves halfway toward the previous correct reaction time. The HUD shows elapsed time and the infinity symbol; Restart and Main menu discard the practice run. The Zen mode button states **No coins awarded**. PHP refuses new ranked Zen starts and finishes so the reward boundary is server-enforced rather than merely hidden in the browser.

Retain existing Zen leaderboard rows and historical three-minute proof support as read-only audit/history. Retire `complete_zen` from the active five-achievement catalog without deleting historical database rows or migrations. This supersedes D-018, D-038, and D-039 wherever they define Zen as timed, ranked, decoy-enabled, coin-eligible, or achievement-completable. Arcade remains the only active ranked and coin-earning mode.

Consequences: Zen can continue indefinitely and its live points, reaction ratings, streak, and music progression remain useful immediate feedback, but leaving the mode produces no Game Over screen or durable result. Cached older clients cannot mint Zen rewards because the server rejects those attempts. The historical Zen leaderboard can still be inspected, but cannot receive new entries under this decision.

Revisit when: Zen needs an explicit manual finish with a separate non-coin record type, practice statistics should be stored outside competitive leaderboards, a new finite relaxation challenge is designed, or historical Zen rows should move into a dedicated archive view.

## D-046 — Permit administrator-owned result moderation and hide deleted rows by default

- Date: 2026-07-14
- Status: Accepted

Context: The first moderation release prohibited administrators from acting on their own or another administrator's results. The sole administrator needs to remove any exact cheated row, including one attached to that same account, while logically deleted records should not clutter routine review lists.

Decision: Allow a database-authorized leaderboard administrator to quarantine and delete/reset any exact result, including their own result or another administrator's. Preserve recent Google authentication, same-origin CSRF, exact result and player IDs, expected-status matching, written reason, explicit confirmation, quarantine-before-delete, transaction boundaries, idempotent reset UUIDs, immutable audit records, and generation-safe reward cleanup. This supersedes D-043 only where it refused self- or administrator-target moderation.

Treat `status=all` as **all non-deleted statuses** in both the full administration list and conservative scan. Return logically deleted rows only when `status=deleted` is selected explicitly. Public ranked lists continue to exclude deleted rows as before.

Consequences: A single administrator can clean any known cheat without a second privileged account, while destructive work remains deliberate, attributable, and reversible only through the existing audited mechanisms. Routine lists stay focused; deletion history remains available through an intentional filter.

Revisit when: Multiple administrators justify two-person approval, role separation, moderation appeals, bulk account actions, or an independent audit-only role.

## D-047 — End Zen explicitly and show ephemeral local results

- Date: 2026-07-14
- Status: Accepted

Context: Endless Zen no longer has a natural completion, but using Arcade's Restart and Main menu controls discarded useful practice statistics without giving the player a deliberate way to finish. The standard Game Over wording also incorrectly implied failure in a mode with unlimited lives.

Decision: Replace Zen's in-game Restart and Main menu controls with one **End run** action. It may end a waiting or active Zen run, freezes elapsed time, score, fastest and average reaction, rating distribution, and other accumulated statistics, clears the unfinished target, and opens the shared result layout under the title **Results**. Keep this result in memory only: it creates no ranked ticket or proof submission, leaderboard write, achievement, coin credit, profile record, or local persisted history. Restart and Main menu remain available as small square controls at the top of both the Zen Results and Arcade Game Over views; remove their bottom result controls. Arcade keeps its in-game Restart and Main menu shortcuts and still ends only after the third lost life. This supersedes D-045 only where it said leaving Zen produces no result screen and where its in-game controls discarded the run.

Consequences: Players can inspect a self-ended Zen practice session without turning Zen back into a finite or rewarded mode. Ending at zero taps is valid and displays zero/empty statistics. The in-memory result survives a temporary round trip to historical Leaderboard or Profile views, but a reload, restart, or menu return discards it. Cached older clients remain unable to submit Zen because the server-side rejection is unchanged.

Revisit when: Practice history should persist, Zen receives a separate noncompetitive server record type, an explicit pause/resume model is added, or result navigation is redesigned across all modes.

## D-048 — Ship only one Sound-FX-controlled tap-tone bank

- Date: 2026-07-14
- Status: Accepted

Context: The hum and layered adaptive/Interactive Music paths created audible clicks, timing concerns, overlapping preference semantics, and a large audio asset surface. The desired immediate feedback is the correct-tap melody itself, and it should follow the Sound FX preference rather than either music switch.

Decision: Remove the hum, life-loss cue, legacy adaptive soundtrack, Interactive Music backing, track rotation, pace mapping, all Music/Interactive Music switches and preferences, the music controller, runtime music caching, alternative tone banks, generation scripts, and in-tree music rollback masters. Keep only the former Deep Current uniform bank as `assets/audio/tap-tones.wav`; it was objectively the cleanest of the three equal-energy banks, with the highest harmonic concentration and gentlest largest sample transition. Treat that lossless 48 kHz/16-bit mono file as both runtime asset and current master. Play its fixed 16 half-second slots sequentially on correct taps only, wrapping after sixteen hits, at native pitch and independent of reaction time or game pace. Gate the entire lifecycle behind Sound FX, cap overlap at two voices, release a retired voice briefly, skip unready cues rather than playing them late, and keep the bank outside the service-worker app shell and runtime cache. Prior assets remain recoverable at Git commit `7d4b0d6427892af08ae77ece62734294c79d22be`. This supersedes D-006, D-011, D-012, D-015, D-022, D-025, D-030, and D-036 wherever they require hum, life-loss audio, music, Interactive Music, multiple banks, their settings, or their runtime caching.

Consequences: One switch now has one understandable effect: it enables or disables immediate correct-tap tones. Disabled Sound FX performs no context creation, fetching, decoding, caching, or playback. The current tree is roughly 102 MB smaller and no background sound continues between taps. Automated PCM, lifecycle, sequence, and concurrency tests still do not replace physical-iPhone Safari and installed-PWA listening for latency, output level, and subjective timbre.

Revisit when: A separately designed background-audio layer is ready, physical-device testing prefers a different mastered tone bank, a user-requested failure cue can meet the same latency/edge constraints, or native builds replace browser Web Audio.

## D-049 — Restore life-loss feedback and one independent background loop

- Date: 2026-07-14
- Status: Accepted

Context: D-048 removed every non-tap asset to isolate audible clicking and preference ambiguity. The player clarified that the life-loss cue remains important feedback and asked for optional background music that supports rather than competes with the immediate tap tones. The former Interactive system's Deep Current opening was already authored as an original, seamless backing-only island without a time-driven lead.

Decision: Restore the original lossless `oops.wav` cue under **Sound FX**, with at most one life-loss voice, a short retirement fade, predecode alongside the tap bank, and the same strict disabled/no-work and trusted-gesture rules. Add a separate, default-on **Music** preference which plays only during a run. Crop the 460,800-frame Deep Current backing-only opening into a retained lossless master and a 9.6-second AAC runtime loop; play it at `0.28` gain with a 120 ms entrance and 80 ms exit. Stop it for Results, Game Over, and Main menu. Keep the hum, adaptive stages, tempo/richness progression, track rotation, tap-note ownership by Music, Interactive Music toggle, Interactive Music controller paths, and all pace/reaction pitch changes removed. Keep every runtime audio asset outside the install-time app shell and service-worker cache. This supersedes D-048 only where it removed life-loss audio, background music, and a separate Music switch.

Consequences: Sound FX once again means both immediate success and failure feedback, while Music is an independently removable background layer. Turning either category off creates no context or network/decode/playback work for that category. The single low-complexity loop avoids adaptive transition clicks and decodes to a small bounded buffer; its bass-heavy phone audibility and balance against two overlapping tones still require physical-iPhone listening. The soundtrack is entirely original SpeedyTapper audio: a stylistic reference influenced only broad restrained downtempo qualities, and no third-party recording or composition is sampled or imitated.

Revisit when: Physical-device testing calls for a different low-end balance or gain, a longer non-repetitive bed is commissioned, music should continue into menus, or an explicitly redesigned adaptive system can justify its added lifecycle and test surface.

## D-050 — Pair Power Grid tap tones with a lighter fixed background

- Date: 2026-07-14
- Status: Accepted

Context: Direct listening favored the Power Grid uniform tap bank over Deep Current. Objective inspection also showed that every archived backing opening remained dominated by energy below 80 Hz, making even the brighter Power Grid backing feel heavier and more pressing than the requested calm, lightly uplifting bed. The Disco presentation also needs atmosphere without reintroducing adaptive-music timing or lifecycle complexity.

Decision: Replace only the fixed Sound FX bank bytes with the lossless Power Grid uniform bank while retaining the existing sixteen native half-second slots, two-voice cap, gain, sequence behavior, and Sound FX lifecycle. Replace the fixed Deep Current backing runtime with **Daylight Circuit**, a deterministic original four-bar loop at 80 BPM in F-sharp major. Use warm pads, restrained mid-bass, half-time tonal percussion, and quiet chord blooms; exclude a lead melody, sub-led ostinato, noise hats, reaction mapping, adaptive stages, and track rotation. Retain the lossless master and generator, keep Music at `0.28` runtime gain, and use the authored 12-second boundary. Preserve the former Deep Current lossless master and its release commit as rollback sources.

Keep the plain Disco concrete as the material for gameplay tiles, the streak meter, and pet cards. Add a separate, static full-cover concrete variant with broad, pre-blurred cyan, violet, and warm reflected light only to Disco page, overlay, dialog, board-surround, and theme-preview backgrounds. Do not animate or live-blur it, repeat it as a colored spot grid, or expose it in Classic.

Consequences: The preferred tap timbre changes without touching reaction-critical scheduling. The background stays roughly nine decibels behind a tap voice at the current gains, while its below-80-Hz energy is about one tenth rather than more than nine tenths of total spectral energy. The longer, slower loop should feel calmer. Reflections add Disco atmosphere around the game without becoming false target cues or adding runtime blur cost. Automated level, seam, and CSS-scope checks do not establish subjective balance, iPhone-speaker quality, or small-screen visual contrast; physical Safari and installed-PWA review remain required before production validation.

Revisit when: Physical-device listening calls for a different tempo, harmony, gain, percussion density, or loop length, or when a future separately designed background system justifies more variation.

## D-051 — Rebalance runtime audio and separate Themes from Settings

- Date: 2026-07-14
- Status: Accepted

Context: Phone listening made the fixed background difficult to hear while the immediate Power Grid tones were comparatively prominent. Players also need independent level control without re-encoding approved masters. The growing main menu benefits from clearer separation between visual customization, accessibility/audio settings, progression, and mode identity.

Decision: Keep every approved audio file byte-for-byte and rebalance only the Web Audio graph. Raise the Daylight Circuit base gain from `0.28` to `0.42`, lower only the tap-tone base gain from `0.50` to `0.375`, and retain the life-loss cue at `0.55`. Add persistent 0–100% Music and Sound FX sliders that scale separate output gain nodes after existing fades, gates, and cue-level balancing. A stored 100% means the new accepted base mix. Updating a slider must not create a context, fetch, decode, or start playback while its category is disabled; the corresponding on/off switch retains the strict disabled/no-work and trusted-gesture rules.

Move the Classic/Disco selector from Settings into a dedicated **Themes** view. Put **Pet Shop** and **Themes** side by side at 45% width, enlarge the Arcade and Zen names, give Arcade a red glow and Zen a calm light-green glow, and lower Zen's **No coins awarded** note beneath the name. Reuse the utility-header pixel coin for the Pet Shop balance and all prices, and visually mute a price after its pet is owned. Overlay a glowing `*` on the Achievements control only when the authenticated achievement payload contains an unclaimed reward.

Consequences: The approved source and runtime audio assets, hashes, loop seam, and provenance remain unchanged. Players can correct phone-speaker or headphone balance per device without confusing a volume preference with an opt-out. Two additional local-storage values are introduced, defaulting to 100%, and slider changes use short ramps to avoid discontinuities. The Settings view is focused on accessibility and audio, while Themes becomes a first-class customization destination. Automated controller and static UI tests do not establish subjective volume, small-screen comfort, or installed-PWA behavior; physical iPhone Safari and installed-PWA review remain required before this candidate is called device-validated.

Revisit when: Physical-device listening calls for different base gains or logarithmic slider mapping, more themes require browsing or ownership state, the menu gains more first-class destinations, or accessibility testing prefers a non-symbol achievement badge.

## D-052 — Separate melodic menu and clean gameplay music variants

- Date: 2026-07-14
- Status: Accepted

Context: The main menu benefits from more musical identity, while reaction-critical gameplay needs the Power Grid tones to remain immediate, tap-triggered feedback rather than a competing pre-recorded melody. The Pet Shop and Themes controls also inherited an unintended large internal gap from the generic Settings row cascade, and the first glowing mode palette remained darker than intended.

Decision: Keep the approved 12-second Daylight Circuit backing unchanged as the gameplay runtime. Add a deterministic menu variant that overlays all sixteen approved Power Grid notes at the track's sixteen 80 BPM beat positions, with restrained gain and subtle alternating pan. Music owns and predecodes both variants only while Music is enabled: menu views select the melodic mix after a trusted gesture, Arcade and Zen select the clean mix, and Results/Game Over remain silent. Correct taps continue to own the only in-game notes through Sound FX. Match the life-loss cue's base gain to the tap-tone base gain and retain the strict Sound FX opt-out lifecycle.

Constrain the Pet Shop and Themes controls to a compact 48 px minimum touch height with a two-pixel caption/value gap. Lighten Arcade toward pink-red and Zen toward a summer-leaf green while preserving readable text and their distinct glow identities. This supersedes D-051 only for life-loss gain, menu silence, feature-control spacing, and exact mode colors.

Consequences: Menu and gameplay share one musical identity without embedding predictive tones into the reaction loop. Switching scenes replaces one bounded looping source with another under the existing fade/output graph; both optional runtimes remain outside the install-time app shell and service-worker cache. Disabling Music still creates, fetches, decodes, caches, and plays neither variant, while disabling Sound FX suppresses both tap tones and life-loss feedback. The retained menu master and deterministic generator expand the release artifact only by the runtime AAC. Physical iPhone listening remains required before calling latency, balance, and scene transitions device-validated.

Revisit when: Phase-continuous scene switching becomes important, the menu melody should be sparser, physical-device listening calls for a different embedded-note gain, or a future music system justifies more than two fixed variants.

## D-053 — Consolidate production development on `main`

- Date: 2026-07-14
- Status: Accepted

Context: The repository's historical `main` still pointed to the obsolete Vercel generation while current Hostinger production evolved linearly through `php-main` and short-lived integration branches. Cloud tasks therefore defaulted to code dozens of commits behind production, even though every surviving release branch was already an ancestor of the latest deployed commit.

Decision: Make `main` the sole long-lived source and release branch. Fast-forward it to the latest reviewed Hostinger release commit without rewriting history, keep immutable `hostinger-YYYYMMDD-N` tags as deployment anchors, and delete merged remote PHP and integration branches after exact-SHA verification. Future work starts from current `main`, lands through reviewable feature branches, and deploys only an explicitly authorized clean `main` commit through the isolated Hostinger MCP artifact workflow. This supersedes D-020 only where it names `php-main` as the version-history branch.

Consequences: Local and cloud Codex tasks now select current production code by default, while release history remains reachable through `main` and annotated Hostinger tags. Branch deletion does not delete commits or deployments. The legacy Vercel deployment may remain as a separate immutable rollback without controlling branch structure or current product behavior.

Revisit when: A protected release branch, staging environment, signed release workflow, or organization-wide branch rules become useful.

## D-054 — Make themes complete cosmetic products with server-owned purchases and audio identities

- Date: 2026-07-14
- Status: Accepted

Supersession note: Accepted D-065 supersedes this record only where it says administrator delete-and-reset removes paid theme ownership. The StoreKit rule preserves a theme with any active purchased-lot allocation and removes only an earned-only or otherwise unpaid entitlement during moderation.

Context: Default and Disco were device-local visual preferences, while the requested Light and Pixel directions need durable coin ownership and their own coherent menu music, clean gameplay backing, and tap-tone sequence. Treating paid themes as a browser flag would permit free purchases, double charges, stale balances, and moderation resets that recreate spent coins.

Decision: Keep the stable internal IDs `classic`, `disco`, `light`, and `pixel`, while presenting `classic` as **Default**. Default and Disco remain free and always owned. Light costs 50 coins and Pixel costs 100 coins. Store paid ownership and one selected theme in MySQL. Use one authenticated, same-origin, CSRF-protected select operation: selecting an owned theme is free, while selecting an unowned paid theme locks the player, validates and debits the server catalog price, inserts ownership, records a generation-qualified negative `theme_purchase` ledger event, and selects it in one transaction. Never trust browser price, balance, ownership, or purchase state. An administrator delete-and-reset removes paid theme ownership and selection with pets, records the removed theme IDs in the immutable reset audit, and advances the economy generation; ordinary score reconciliation retains legitimate theme spending.

Render Theme Shop as a compact two-column catalog with Pet Shop-style **Buy**, **Select**, and disabled **Selected** actions; cards keep only the name and omit descriptive subtitle lines. Owned prices remain visible but greyed. Signed-out players may switch between Default and Disco; paid actions route to Google Profile. Light uses near-white panels, white board gaps and tile borders, a pale-blue sky with only two thin CSS clouds, dark readable UI text, distinct bright tile colors, and white color-blind glyphs. Pixel self-hosts the unmodified OFL-licensed Pixelify Sans variable font with a documented system fallback, hard square edges, stepped shadows, and a restrained arcade grid. Theme surfaces remain code-native rather than introducing a bitmap dependency.

Give every theme an immutable menu/background/tone manifest. Default retains Daylight Circuit and Power Grid. Disco uses the original 120 BPM **Mirror Circuit** suite, Light the 100 BPM **Open Sky** suite, and Pixel the 160 BPM **Coin-Op Spark** suite. Each suite contains a 12-second melodic menu loop, a matching clean gameplay loop, and sixteen native half-second tap cues. Load, decode, and keep in memory only the selected suite while its audio category is enabled. A theme change aborts stale loads and replaces decoded theme assets inside the already-unlocked contexts, preserving the shared loss cue and avoiding a second iPhone user-activation requirement after an awaited purchase. Keep the life-loss cue under Sound FX, keep every optional runtime outside the service-worker app shell and runtime cache, and retain deterministic generators, lossless masters, decoded-seam verification, hashes, and provenance. This supersedes D-052 only where it defined one fixed music and tap-tone identity for every visual theme, and extends D-040/D-043 with `theme_purchase` accounting and theme cleanup.

Consequences: Themes become durable cosmetic progression without affecting gameplay, scoring, achievements, or coin earning. Purchases serialize with run credits and pet spending, retries cannot charge an already-owned theme, and moderation cannot accidentally restore spent coins. Theme preference follows an authenticated profile after session refresh; free selection also remains locally convenient. Because the browser must receive theme CSS and audio to render them, this is entitlement enforcement through the supported UI/API rather than tamper-proof digital-rights protection. Automated audio and responsive-browser checks do not replace physical-iPhone Safari/PWA listening and visual validation.

Revisit when: Themes need previews before purchase, refunds or gifts, remote catalog administration, per-theme life-loss cues, downloadable content delivery, stronger native-client entitlement protection, or a shared cross-device accessibility preference.

## D-055 — Adopt PimPoPom branding and a server-authorized nickname companion

- Date: 2026-07-14
- Status: Accepted

Context: The prototype needs a more playful public identity, clearer customization shortcuts, and one hidden companion tied to an exact saved nickname. The existing two-part SpeedyTapper wordmark, neutral menu borders, and duplicate-front right-turn frame made those elements feel less distinct. A nickname cosmetic inferred only in browser code would also appear without the server-confirmed saved profile state.

Decision: Present the product and installable PWA as **PimPoPom** while retaining SpeedyTapper as the repository, domain, PHP namespace, storage-key, CSRF-header, and compatibility API name. Render the wordmark as code-native `Pim`, `Po`, and `Pom` spans with three distinct gradients and one accessible combined label; do not ship a raster text logo. Give Achievements, Pet Shop, and Themes their own theme-aware outline colors. Put Pet Shop and Themes in an equal two-column grid and pair their labels with a paw and palette icon.

Add **Mitsuri**, a bright red rabbit with one pale light-pink stripe on her right ear, as a non-purchasable nickname easter egg. The server returns `specialPetId: mitsuri` only for an authenticated, confirmed nickname whose normalized stored value is exactly lowercase Cyrillic `кокос`; uppercase or Latin lookalikes do not qualify. This derived presentation temporarily overrides the visible purchased pet without altering its ownership, selection, visibility, wallet, achievement state, or ledger. Saving any other nickname removes Mitsuri and restores the stored selection. The leaderboard derives the same override from server-held nickname data, while the browser never decides eligibility from typed text. Keep the regular five-pet shop unchanged.

Make authored right turns symmetric by skipping the duplicate front cell: half-right moves through frames 5–6 and full-right through frames 5–7. This corrects Foka's delayed half/full right turn and applies to every ten-frame directional pet with the same sheet contract.

Consequences: The public name changes without a risky backend namespace or persistence migration. Menu customization destinations remain distinct in every theme and fit narrow phones without literal 50%-plus-gap overflow. Mitsuri appears immediately after the exact nickname save, disappears just as immediately after a different save, cannot be purchased or forged through the supported API, and never disturbs durable pet state. Physical-iPhone Safari/PWA review is still required for logo fit, outline contrast, stripe legibility, and turn cadence.

Revisit when: The domain/repository/backend names should follow the public brand, nickname cosmetics become permanent inventory, special pets need hiding or selection controls, or more menu destinations require a different information hierarchy.

## D-056 — Label primary run controls and align Mitsuri's compact cushion

- Date: 2026-07-15
- Status: Accepted

Context: Icon-only Restart and Main menu controls were compact but required players to infer two important actions. Mitsuri's 32×48 cushion art also places its visible pixels at the bottom of each two-layer source cell, so the generic habitat top offset produced a large empty gap below the 32×32 rabbit when both were scaled for non-game scenes and portraits.

Decision: Keep non-run utility shortcuts icon-only, but render visible **Restart** and **Menu** captions immediately to the right of their icons in Arcade gameplay and both result views. Preserve a 44 px touch height and use content-width buttons so the controls remain compact on the 2022 iPhone SE viewport. Keep Zen's single **End run** action unchanged. Give Mitsuri's cushion dedicated shared non-game-scene and leaderboard offsets that account for its bottom-weighted transparent source cells; do not move the rabbit sprite or alter gameplay, where habitats remain hidden.

Consequences: The two primary navigation actions are self-explanatory without expanding every utility icon, and Mitsuri visually rests on her cushion across non-game presentations. The source sheets remain unchanged, so this release does not recover detail discarded by the current 32 px logical-frame pipeline. Automated and desktop responsive checks do not replace physical-iPhone Safari/PWA confirmation of touch spacing and pixel sharpness.

Revisit when: Utility labels need localization, narrow layouts need a different header hierarchy, or the pet pipeline moves from generated 32 px sheets to hand-authored 64 px logical frames.

## D-057 — Recover source masters and adopt native 64 px pet frames

- Date: 2026-07-15
- Status: Accepted

Context: The browser displayed 32×32 logical pet cells at 64 CSS pixels. Nearest-neighbor scaling kept their edges crisp, but reducing the generated masters to 32 pixels had already discarded facial, fur, feather, and outline detail. The final Mitsuri source was still recoverable only from a temporary image-generation cache and an unreachable Git object; the other generated sources also lived outside the repository and could be lost. Pancake differs because its accepted runtime art came from a user-supplied recording whose original frames no longer survive in Git.

Decision: Retain the accepted high-resolution chroma masters, reviewed alpha intermediates, and former 32 px layout sheets under `assets/pets/sources/`. Build Foka, Kesha, Tauta, Misha, and Mitsuri from those high-resolution alpha masters into ten-cell 640×64 PNG sheets with `scripts/build-pet-sprites.py`. Extract only each authored connected pose, remove residual chroma spill, resize with nearest-neighbor sampling, harden the final runtime matte to binary alpha at cutoff 96, and preserve the former layout sheet's exact pose order, baseline, and visible footprint. Keep CSS scene sizes and frame percentages unchanged. Preserve Pancake's accepted recording-derived look as an exact nearest-neighbor 2× 640×64 sheet; retain its generated concept only as unused provenance and never substitute it silently. Keep all habitat sheets on their existing two-layer contract.

Consequences: Five generated companions gain real 64 px source detail without changing animation timing, click direction, habitat alignment, gameplay geometry, or shop ownership. Pancake becomes dimensionally consistent and stays crisp but gains no new information. The source pipeline is reproducible and no longer depends on garbage-collectable Codex/Git temporary objects. Chroma and intermediate masters are source-only and must remain outside curated production artifacts. Asset-dimension tests now require 640×64 sprite sheets. Direct alpha-sheet inspection cannot replace physical-iPhone Safari/PWA review of final perceived sharpness and cache replacement.

Revisit when: Pancake's original recording or an approved higher-resolution replacement becomes available, habitats also move to higher-resolution logical layers, individual frames are hand-retouched at 64 px, or native clients need density-specific sprite atlases.

## D-058 — Give Zen an Any-color identity and add a second server-authorized companion

- Date: 2026-07-15
- Status: Accepted

Context: Zen has no decoys and accepts every live target, but its HUD continued to display one changing Arcade color and a matching colored frame glow. That implied a color-selection rule which does not exist in practice. The exact-nickname Mitsuri path also established a safe server-authorized pattern for personal easter eggs without granting inventory or trusting browser text.

Decision: In Zen only, replace the changing color identity with a permanent **Any** label and a colorful gradient swatch containing a white yin-yang mark. Keep the containing **Your color** field on the current theme's neutral panel and border with an inset-only shadow; do not apply a player-color border, radial wash, or glow. Arcade retains its named palette, optional accessibility glyph, and colored field treatment unchanged.

Add **Muse** as a second non-purchasable nickname companion. The server returns `specialPetId: muse` only for an authenticated confirmed nickname whose normalized stored value is exactly case-sensitive lowercase `bloodyvlad`; browser code never derives eligibility. Muse temporarily overrides the visible shop selection without changing ownership, visibility, wallet, achievements, or ledger state, and the leaderboard derives the same override from server-held profile data. Keep the five-item Pet Shop unchanged. Render Muse as a semi-realistic adult pixel-art companion with light wheat-brown ponytail, fitted pale crop top, full-length warm-beige yoga leggings, kneeling half turns, neutral side-view yoga poses for full turns, and a ponytailed side-sleep sequence on a two-layer wooden floor. Build right transition, half-turn, and full-turn cells by mirroring the accepted left source cells instead of trusting separately generated right anatomy. Use a dedicated native 64 px placement map and retain both chroma and reviewed alpha masters.

Consequences: Zen communicates its actual rule without color churn or distracting frame glow, while every theme keeps its own panel geometry. The personal companion remains an authenticated presentation override rather than free inventory, requires no schema migration, and cannot be activated through supported client state alone. Mechanical mirroring makes left/right anatomy deterministic, while the explicit 640×64 sprite and 64×48 habitat contracts preserve existing controller, scene, and leaderboard rendering. Physical-iPhone Safari/PWA review remains required for the yin-yang legibility, neutral-frame contrast, human likeness at 64 px, ponytail readability, floor alignment, and directional cadence.

Revisit when: Zen gains color-specific practice rules, the Any indicator needs a non-symbol accessibility alternative, special companions become owned cosmetics, nickname collision policy changes, or a hand-authored human sprite replaces the generated master.

## D-059 — Reserve a stable menu hint stage and suppress redundant pet turns

- Date: 2026-07-15
- Status: Accepted

Context: The large **Ready to react?** heading repeated the product's purpose without teaching the three useful actions, while showing or hiding an absolutely positioned pet changed how much width the hint occupied and could move the controls below it. After players learn the rule, the same instructional copy also wastes a playful identity opportunity. Separately, every accepted pointer event restarted a pet turn sequence even when its resolved final direction already matched the pet, making an already full-right companion visibly replay front-to-right.

Decision: Remove the visible menu heading while retaining a visually hidden **PimPoPom** dialog label. Reserve one fixed 112 px menu-only hint area with the same 76 px pet-safe right inset whether a pet is visible or hidden. Before the first local Arcade Game Over, show three normal-size rows: **Tap your color**, **Become the fastest**, and **Collect rewards!**. After that first Game Over, persist one device-local unlock and replace those rows with one of the approved 26 larger, bold, colored, slightly tilted motivational one-liners. Pick a different line at every later Arcade Game Over, keep it stable while navigating subviews, and restore a line after reload. Zen Results neither unlock nor rotate this state.

When a pet tap resolves to the direction already stored, do not cancel or restart an active turn/wake sequence and do not replay a completed turn. Refresh only the non-game idle deadline. Sleeping, stopped, or settling pets still wake, Pancake still resumes dancing from stopped, and gameplay's existing no-idle behavior remains unchanged.

Consequences: New players get concise instruction, returning players get personality, and pet visibility cannot create menu-content jumps. Motivation is a local presentation preference rather than profile progression. Repeated same-direction taps no longer create distracting back-and-forth frames, while genuine direction changes and wake transitions retain their approved animation. Physical-iPhone Safari/PWA review remains required for fixed-area fit, phrase wrapping, tilt/color contrast, and live pet geometry.

Revisit when: Hints become server-personalized, localization makes a fixed stage impractical, players need an option to restore instructions, or animation state moves to a timeline controller.

## D-060 — Give the bootstrap administrator one-time cosmetic test ownership

- Date: 2026-07-15
- Status: Accepted

Context: The initial leaderboard administrator needs to exercise every current Pet Shop and paid Theme Shop state without manufacturing gameplay currency or contaminating competition and economy records. Targeting a mutable nickname, email, raw Google identity, rank, or browser flag would violate existing identity boundaries, while recording zero-cost test access as a purchase would create false ledger and **Buy a pet** achievement evidence.

Decision: Migration `013_grant_admin_test_cosmetics.sql` targets only the durable `leaderboard_admin` role whose provenance is exactly `granted_by = 'migration-011'`. Add `admin_test_grant` to pet acquisition sources, then insert zero-price ownership for every active shop pet and each active paid theme. Default and Disco remain implicitly owned. Use duplicate-key no-ops so existing paid ownership, acquisition source, ledger entries, and achievements remain unchanged. Do not update the player's balance, economy generation, selections, visibility, achievements, or ledger, and do not grant nickname-only companions.

Consequences: The requested administrator can test all current store cosmetics without affecting coins or competitive accounting. Reconciliation ignores these non-economic ownership rows. A destructive account reward reset removes the grants with other cosmetics; because the migration is one-time, it does not silently restore them afterward. Newly added future cosmetics are not auto-granted without another explicit decision.

Revisit when: Multiple test accounts are needed, entitlements require expiry or revocation independent of reward reset, future cosmetics should auto-grant to a testing role, or a non-production staging environment replaces production test access.

## D-061 — Use Jersey 10 for the Pixel theme

- Date: 2026-07-15
- Status: Accepted

Context: Pixelify Sans preserved a playful bitmap identity, but its rounded and irregular small-text forms made result explanations, buttons, and statistics unnecessarily difficult to scan on an iPhone-sized screen. The selected Jersey 10 face is a deliberately compact display bitmap with clearer, more rectilinear Latin forms.

Decision: Replace Pixelify Sans throughout the Pixel theme with the unmodified Google Fonts Jersey 10 Regular binary. Self-host the font in the install-time app shell, retain its SIL Open Font License and provenance, and preserve the existing system monospace fallback. Jersey 10 contains Latin and Latin Extended only; unsupported scripts, including Cyrillic nicknames, intentionally use that documented fallback. Keep the Pixel theme's hard edges, stepped shadows, grid, colors, and gameplay rules unchanged. This supersedes D-054 only where it names Pixelify Sans as the Pixel theme font.

Consequences: Pixel UI copy uses a less curved, more traditional arcade face while remaining offline. Jersey 10 has one 400-weight face, so weight-based hierarchy comes primarily from size, color, spacing, panels, and the existing font fallback rather than synthetic bolding; global `font-synthesis: none` remains unchanged. Cyrillic and other unsupported characters stay readable but will not share the Jersey letterforms.

Revisit when: Physical-iPhone review finds that Jersey 10 is still tiring for paragraphs, a matching multiweight family is needed, broader script coverage becomes a product requirement, or the Pixel theme adopts separate display and body faces.

## D-062 — Rebalance the main-menu hint and pet anchors

- Date: 2026-07-15
- Status: Accepted

Context: The fixed hint stage left more space above the first instruction than the compact menu needed, while the companion sat slightly too high beside that copy. Moving only individual buttons would weaken the no-jump layout contract and risk different geometry between the introductory and motivational states.

Decision: Move the entire fixed menu hint stage 10 px upward by reducing only its top margin, so every following main-menu control naturally moves upward by the same amount. Move the companion 15 px downward only while the dialog is in its true main-menu state. Keep the 112 px hint-stage height, 76 px pet-safe inset, all per-pet sprite/habitat offsets, and every non-menu view anchor unchanged. Apply the same geometry to the introductory and motivational variants.

Consequences: The menu uses vertical space more efficiently without changing button spacing or producing a first-run/post-run jump. The companion sits closer to its intended visual center while Profile, Leaderboard, shops, Results, and gameplay retain their prior layouts. Physical-iPhone Safari/PWA review remains required for final perceived balance and scroll reach.

Revisit when: The utility header height changes, localized hint copy needs a different stage, pet sizes diverge by companion, or the main menu is redesigned as a grid.

## D-063 — Animate and directly advance learned menu slogans

- Date: 2026-07-16
- Status: Accepted

Context: The three first-run instructions need slightly more presence against the themed menu surfaces, while a learned motivational slogan becomes static during longer menu visits. Rotation must not consume work during gameplay or hidden tabs, restart the five-second wait after direct interaction, or alter the fixed hint geometry.

Decision: Give only the introductory instruction rows a restrained theme-aware text glow. After the local Arcade unlock, treat the motivational hint stage as one keyboard-focusable control: advance to a different approved slogan when tapped, clicked, or activated with Enter/Space, and automatically advance after five visible main-menu seconds. Reset the five-second timer after every advance. Stop the timer whenever the menu overlay is hidden, another dialog view opens, the document becomes hidden, or the page exits; resume a fresh interval when the visible main menu returns. Keep the existing pool, non-repeat selection, colors, tilts, 112 px stage, and Zen unlock exclusion.

Consequences: New players retain stable instructional copy with a modest glow, while returning players see more of the playful slogan library without navigating away. The timer performs no background or gameplay work, direct interaction is accessible beyond touch, and slogan changes cannot move the controls below. Physical-iPhone Safari/PWA review remains required for glow restraint, touch behavior, timer suspension, and service-worker upgrade behavior.

Revisit when: Slogans gain localization, animation transitions, per-player preferences, reduced-motion behavior beyond static replacement, or server-authored campaigns.

## D-064 — Keep gameplay API traffic bounded and move migrations to deployment bootstrap

- Date: 2026-07-18
- Status: Accepted

Context: Production load probes showed that proof replay itself remained inexpensive, but Hostinger's dynamic PHP path degraded sharply under modest concurrency. Every request also opened MySQL, inspected all migrations, and upserted the season. A normal run caused redundant leaderboard, session, and achievement requests around the two authoritative start/finish mutations. Personalized top-score reads used session/profile work and a window-ranked query even when the HUD needed only the public leader.

Decision: Preserve the existing chronological proof, server clock, one-time run, economy, and moderation rules. Gate production migration checks behind an untracked `server/.migrations-pending` file created only in the staged release artifact. The first API request that sees it runs the advisory-locked migration sequence, ensures the configured season, and removes the marker; normal requests perform neither operation. Local and shell-capable environments continue to run the migration CLI explicitly.

Add a session-free `GET /api/top-scores` endpoint backed by a mode-specific ordered `LIMIT 5` query and five-second browser/ten-second shared caching. Keep personalized leaderboard windows on the existing endpoint, but use the same indexed top-five path for signed-out reads. Ranked start and finish capture the player/session binding, apply finish throttling before proof parsing, close the PHP session, and then use a lightweight nickname-confirmation lookup before database work. One Arcade run therefore uses one start mutation and one finish mutation; the finish response includes rank, balance, total verified time, and the current achievement snapshot so the browser does not immediately refetch them. Initial menu state uses one session response plus the cacheable public top-score read.

Consequences: Request count and per-request schema/profile work drop substantially without weakening score verification or changing gameplay. Different players no longer pay pet/theme hydration during ranked authentication, and same-session PHP locks do not cover proof replay or ranking queries. The small public cache may show a leader up to roughly ten seconds late, while a submitted result still receives exact uncached context. This is capacity hardening, not evidence that shared Hostinger PHP can sustain 1,000 concurrent active players; production load must be repeated after deployment, and a dedicated runtime/database remains the next step if tail latency is still unacceptable.

Revisit when: Deployment gains a shell migration hook, public leaderboard invalidation needs event-driven freshness, Hostinger exposes worker/database limits, or measured concurrency justifies a dedicated API service, Redis, queueing, or managed database.

## D-065 — Add a backend-first StoreKit ledger and identity-erasing account deletion

- Date: 2026-07-19
- Status: Accepted

Context: The native product needs App Store coin packs and permanent ad removal, but client-reported receipts, quantities, prices, balances, ownership, refund state, and account binding are not authoritative. Mixing purchased coins into the existing earned wallet would also make exact refunds impossible after a cosmetic uses value from several sources. Apple can deliver notifications late, out of order, or more than once, and account deletion must erase the game identity without discarding the minimum detached evidence needed to settle a later refund or reversal. The current player-facing StoreKit purchase/restore surface is still a placeholder, so the trust and accounting boundary must land before UI activation.

Decision: Configure exactly five products: consumable `com.otcsoftware.pimpopom.coins.50.v1` (50 coins, recommended $2.99), `com.otcsoftware.pimpopom.coins.100.v1` (100, $4.99), `com.otcsoftware.pimpopom.coins.500.v1` (500, $9.99), and `com.otcsoftware.pimpopom.coins.1000.v1` (1,000, $14.99), plus non-consumable `com.otcsoftware.pimpopom.removeads.lifetime` (recommended $1.99). Every direct product creates an account-bound ad-free source. Only the standalone lifetime product is Apple-restorable and Family-Shareable; a Family Sharing beneficiary receives ad-free and never coins. Keep ad-free active while any verified, non-refunded source remains. Treat price points as App Store configuration guidance, not signed server input or a client-authoritative field.

Accept credit only after validating Apple's signed JWS chain and the signed product, transaction/original-transaction identity, product type, ownership, environment, bundle, one-unit quantity, and server-issued `appAccountToken` binding. Never accept a client quantity, price, wallet balance, entitlement, or grant calculation. Store purchased coins separately from earned coins. Spend earned value first and purchased lots FIFO, retaining the exact source split for every cosmetic debit. On `REFUND`/`REVOKE`, remove the refunded lot's unspent value, revoke only cosmetics whose debit actually used that transaction, reverse their debit so unrelated earned and other purchased allocations return to their sources, and record uncovered value as visible `refundDebt`; all future credits pay that debt before becoming spendable. Apply `REFUND_REVERSED` idempotently by restoring the exact credit, entitlement, debt settlements, and affected cosmetics.

Limit administrator delete-and-reset to earned progression: zero earned coins and earned debt; clear the earned time remainder plus current collected/play totals; advance the economy generation; and remove only cosmetics with no active purchased-lot allocation. Preserve purchased coins and lots, IAP/refund history, active paid entitlement sources, existing refund debt, and all paid or mixed-funded cosmetics. Reopen the exact refund obligation when an invalidated earned credit had previously settled it. This decision supersedes D-043's all-value wallet/all-cosmetic reset and D-054's paid-theme removal; D-060's zero-price test grants remain removable because they carry no purchased allocation.

Process App Store Server Notifications V2 idempotently and add a database-locked App Store Server API reconciliation command for missed notification history and retained transaction checks; require PHP `ext-curl` for that client. Migration `014_storekit_paid_value_and_account_deletion.sql` adds source-separated wallet provenance, purchased lots, entitlement sources, notification/transaction observations, exact spend and refund allocations, reconciliation state, and deletion-safe foreign keys while treating all pre-migration wallet value as earned. Migration `015_player_sessions.sql` maps a SHA-256 digest of a rotating 256-bit opaque authentication ID to the player; PHP session files no longer carry a raw player UUID, deletion removes all mappings, and stale sessions fail closed. Forward-only migration `016_storekit_schema_hardening.sql` repairs installations that may already have recorded an earlier form of `014` by enforcing the deletion-safe reward-reset actor foreign key, reconciliation index, and separate notification lifecycle signed-date watermark now present in a clean `014` schema.

Expose the server-generated StoreKit binding in authenticated session/profile state as `storeKit.appAccountToken` and `bindingStatus: "bound"`, alongside `wallet` (`earned`, `purchased`, `earnedDebt`, `refundDebt`, `total`) and `adFree`; signed-out session state uses null/false values. Account deletion requires same-origin CSRF, the exact `DELETE MY ACCOUNT` confirmation, and Google reauthentication within 15 minutes. Delete the player UUID, Google digest, nickname, public leaderboard data, runs/proofs, achievements, cosmetics, and ordinary gameplay/economy history; invalidate every browser session by deleting all identity mappings so any surviving opaque PHP session file fails closed. Retain only transaction, notification, purchased-lot, entitlement, purchased-spend, and refund/reversal evidence necessary for settlement, detach it from the player, replace retained linkable references with keyed pseudonyms, and never recreate or transfer the deleted identity from later Apple traffic.

Consequences: The backend can conserve paid value across mixed-source purchases, refunds, crossed refund/reversal delivery, moderation, and retries without treating browser state as payment evidence. Family Sharing cannot mint consumable currency, and one refunded ad-free source cannot revoke another. Deletion invalidates every browser login while keeping narrowly scoped, non-public payment evidence for Apple accounting. StoreKit is not released merely because these migrations and endpoints exist: App Store Connect configuration, notification/reconciliation operations, native purchase/restore integration, and end-to-end Sandbox/device testing remain required, and the current UI stays a placeholder.

Revisit when: Pricing or the product catalog changes, Apple policy requires a different consumable entitlement treatment, family entitlements need transfer or recovery after deletion, payment-evidence retention receives a fixed legal schedule, moderation must affect purchased value, or the purchase/restore UI is ready for release review.

## D-066 — Preserve ranked play across explicitly compatible asset releases

- Date: 2026-07-19
- Status: Accepted

Context: The installed native build identifies itself as `20260718-1`, while subsequent browser/backend releases advanced the app-shell build ID without changing `reaction-proof-v2`. Strictly equating a ranked protocol client with the latest asset release made an otherwise valid native build unable to obtain a run ticket and exposed users to a misleading refresh instruction that cannot update a TestFlight binary.

Decision: Keep `20260719-3` as the current release identifier, but accept only the explicit compatible IDs `20260718-1`, `20260719-1`, `20260719-2`, and `20260719-3` for `reaction-proof-v2` version 1. Preserve the requesting build ID in the issued attempt, echo it in the ticket, and require the submitted proof to match that stored attempt exactly. Continue rejecting every unknown build, ruleset, and proof version. Adding or removing a compatible build is a reviewed server change; it is never inferred from version order or a client claim.

Consequences: The current native/TestFlight app and immediately preceding installed PWA can start and finish ranked runs after this deployment without weakening one-time ticket, player/session, proof, server-clock, trace-clone, or ruleset validation. Routine CSS, image, audio, StoreKit-configuration, and app-shell releases no longer strand those unchanged protocol clients. A future gameplay/proof change must use a new ruleset or proof version and must not be placed in this compatibility window merely to avoid an app update.

Revisit when: A supported build contains a client vulnerability, gameplay semantics diverge, a new proof version ships, minimum native-version policy is introduced, or telemetry confirms that a retired installed build can be removed safely.

## D-067 — Accept and reconcile StoreKit Sandbox and Production concurrently

- Date: 2026-07-19
- Status: Accepted

Context: TestFlight and Apple Sandbox traffic must continue while the same backend begins receiving Production purchases and App Store Server Notifications V2. A mutable single-environment switch would necessarily reject one side, and using unscoped transaction or notification identifiers could let an identifier collision suppress, credit, refund, or reconcile the wrong environment. Apple signs `environment` and `bundleId` in transaction and notification payloads, signs `appAppleId` in Production notification data, and omits `appAppleId` from Sandbox notification data and transaction JWS.

Decision: Explicitly configure both `Sandbox` and `Production` as accepted environments. Verify the Apple certificate chain before trusting the outer V2 notification; require exact bundle `com.otcsoftware.pimpopom`, an accepted signed environment, and Production Apple App ID `6792328590`. Permit Sandbox to omit `appAppleId`, but reject a wrong value when present. Independently verify nested transaction JWS and require its signed environment to match the outer notification. Continue deriving product type, coin quantity, and capability only from the exact five-product server allowlist and require the established account-token binding.

Persist transaction IDs, notification UUIDs, all purchased-value foreign references, and Family Sharing bindings under their signed environment while retaining raw Apple IDs for API calls and audit. Migration `017_storekit_dual_environment.sql` converts existing Sandbox evidence to that scoped identity graph. Run reconciliation once per accepted environment against its corresponding Apple API origin, lock and checkpoint each environment independently, and expose environment-bound request/status helpers for Apple's V2 test-notification API. Configure the same HTTPS V2 callback URL explicitly in both App Store Connect fields.

Consequences: TestFlight/Sandbox and Production can operate at the same time without a configuration flip or cross-environment idempotency collision. A Production outer notification cannot smuggle a Sandbox transaction, and one environment's refund cannot reach the other's purchased lot or entitlement. Sandbox and Production test notifications prove URL, TLS, Apple JWS, and durable receiver behavior only; a real Sandbox purchase/refund/restore and a later controlled Production transaction remain separate end-to-end validation gates. This extends D-065 without changing its paid-value, deletion, moderation, or exact-product rules.

Revisit when: Apple changes signed payload fields or environment routing, a supported official PHP verifier becomes available, reconciliation moves to a dedicated service/queue, or the product catalog/bundle/App ID changes.
