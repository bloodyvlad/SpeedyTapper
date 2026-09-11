# Multiplayer v2 competitive settlement — local candidate

This source extends the PHP release `f84dc9218b58bb937326be931f2ee969abed4282`.
It is **not deployed**. Migration 025, the matching Swift service and the iOS client
must be released together in that order. Existing Arcade, identity, paid economy,
v1 Multiplayer and legacy v2 outbox contracts remain valid.

## Exact trusted result revision

The existing service-Bearer-only `POST /api/internal/multiplayer/v2/results`
retains protocol `2`, ruleset `multiplayer-shared-arcade-v2` and the 16,384-byte
bound. Devices cannot call it using a login cookie or CSRF token. The service
derives all fields from its own room/engine state; no client coin-amount claims.

New results add this exact top-level tuple to the retained aggregate envelope:

```json
{"resultRevision":2,"gameplayRevision":3,
 "rewardPolicy":"multiplayer-alive-minute-v1",
 "matchKind":"competitive","completionReason":"completed"}
```

`matchKind` is `competitive|tutorial`; `completionReason` is `completed|aborted`.
`rankingEligible` must equal competitive AND completed. Partial or mixed versions
fail. Each seat also includes:

```json
{"eligibleAliveMs":65000,"survivalMs":65000,
 "maxMultiplier":5,"economyGeneration":1}
```

- `eligibleAliveMs` is alive **and connected** match time, including recovery but
  excluding countdown, disconnected intervals and spectating. It is bounded by
  `survivalMs`, which is bounded by match `durationMs` (at most 900,000).
- `survivalMs` is start to elimination/final cutoff, not a winning tiebreak.
- `maxMultiplier` is the real observed peak, integer 1–5. Cumulative misses remain
  0–1000; forfeiting can leave lives 0 and misses 0. No fabricated speed-rating counts.
- `economyGeneration` is the PHP identity generation captured immutably at actual
  match start, unsigned 0–4,294,967,295. Missing/null means unavailable, never 0.
  A refresh/reconnect cannot replace the captured generation.
- The service ends a competitive match only when everyone is out or time expires;
  highest final score wins, including an earlier-eliminated player. Ties share place.
  Local/dev-auth and tutorial fixtures cannot request real competitive rewards.

PHP admission bounds authenticate the reporting service; they are not independent
gameplay replay, human verification or anti-collusion proof.

## Wallet and immutable receipt

Only completed competitive seats whose reported generation equals their current
account generation accrue time. Per current account generation:

`coins = 2 × (floor((priorEligibleMs + eligibleMs)/60000) − floor(priorEligibleMs/60000))`.

This awards pairs of coins per full minute, not one coin per 30 seconds. Remainder
carries independently from Arcade. No minimum score/hit-count heuristic is added.
The 15-minute match cap limits one seat to 30 coins. Tutorial/aborted, legacy,
missing-generation and stale-generation results cannot earn or change carry.
Missing/stale reward generation does not withdraw an otherwise valid score.

Stable account locks, payload digest, shared result and per-owner immutable
receipts make all seats, wallet credits, provenance and board inserts one atomic
transaction. Exact retries return the original outcome; changed data under an
existing match ID fails 409. Old alpha envelopes retain their exact canonical
payload/digest and `stored_unranked` receipt; they never gain revision fields or
earn on migration/retry.

Modern internal acknowledgment:

```json
{"matchID":"<UUID>","duplicate":false,"rankingEligible":true,
 "state":"stored_ranked","resultRevision":2,
 "rewardPolicy":"multiplayer-alive-minute-v1",
 "rewards":[{"playerID":"<UUID>","creditedAliveMs":65000,
 "coinsEarned":2,"remainderMs":5000,"totalAliveMs":65000,"coinStatus":"eligible"}]}
```

The example shows one seat; the service receives one receipt for every seat.
Unranked modern results acknowledge `stored_unranked,false` and zero credits.
Coin status is `eligible|ineligible|missing_generation|stale_generation`.

`GET /api/mobile/v2/multiplayer/results/{matchID}` requires a cookie-authenticated
participant and returns `matchID`, `state`, `rankingEligible`, `resultRevision`,
`rewardPolicy` and their singular `reward` object without `playerID`. It exposes
neither other seats nor a current account balance. Invalid, absent and
nonparticipant IDs all return 404. A 404 while the outbox is syncing means pending
or unavailable, not failed. Clients may poll briefly, then honestly show pending;
after confirmation refresh `/api/session` for the actual wallet. Never optimistically
mint coins from the local clock or interpret historical awarded coins as a current
balance after a reset/refund/spend.

## Fresh global board

`GET /api/mobile/v2/multiplayer/leaderboard` retains the public envelope
`{season,mode:"multiplayer",entries,totalEntries,playerRank,topPercent}`.
Rows contain `position`, `rank`, `name`, `petId`, `score`, `place`, `playerCount`,
`survivalMs`, `fastestReactionMs`, `averageReactionMs`, `hits`, `misses`, `dodges`,
`maxMultiplier`, `createdAt`, `isCurrentPlayer`, `verification:"server_reported_v2"`.
`speedRatings` is omitted because it was not collected; no private IDs are exposed.

Rank uses score only: ties have equal rank/place (1,1,3). `position` is a distinct
ordered-row identity for UI, using result time/entry ID only for stable
presentation. The window is top five plus authenticated best-result neighbors±2,
bounded even for large ties. Season isolation and the retained v1 endpoint remain.
Session/profile adds `ranks.multiplayerV2`; existing `ranks.multiplayer` stays v1.

Migration 025 creates an empty board instead of erasing historical v1 test rows.
No historical result is copied into it. No achievements, Arcade rankings or Game
Center publication/outbox are touched.

## Provenance, moderation, reset and deletion

- `multiplayer_credit` ledger entries own independent alive-time carry. Existing
  Arcade time, remainder and achievement counters are untouched.
- Credits use the existing earned wallet, settling exact refund debt first and
  then earned debt. Purchased lots remain unchanged. Refund repayment references
  `mp2:<matchID>:<playerID>` and the source economy generation.
- Arcade moderation includes current-generation MP gross earned credits when
  recomputing the earned wallet; it cannot erase unrelated multiplayer rewards.
- Existing earned reset advances the economy generation, clears MP carry by that
  fence, reopens exact old earned-funded refund debts and preserves paid value.
  Immutable old receipts still report historical awards, never reapply them.
- New receipt/board tables have an owner FK but no shared match FK. Account
  deletion removes that owner's rows and the shared alpha aggregate, while a
  surviving participant keeps their coins, provenance, board row and own receipt.
- **Retained limitation:** if any participant is deleted before initial outbox
  settlement, the whole result returns 409. The service must handle that final
  rejection without recreating identity or silently paying a partial roster.

## Migration, compatibility and verification

`025_multiplayer_v2_rewards_and_leaderboard.sql` only creates the two empty tables.
It does not alter Arcade/StoreKit schema, existing balances or migration020.
Apply it before the new PHP runtime/service are enabled. For rollback, stop new
revision-3 matches/outbox writes before reverting runtime; keep 025 and value
evidence intact. Old PHP cannot accept modern results or preserve new MP credits
through Arcade reconciliation, so a runtime-only rollback while rewards continue
is not safe. Do not drop tables or restore an old wallet snapshot to roll back.

Run `composer check` and `composer test:mariadb:multiplayer-rewards`. The disposable
MariaDB suite upgrades a populated 001–024 baseline, verifies no backfill, exact
legacy retry, real Swift aggregate admission, private HTTP reads, rank ties/window,
refund debt provenance, actual earned reset, Arcade reconciliation, transactional
rollback, concurrent duplicate/carry settlements and full account deletion.

`test/fixtures/multiplayer-v2-revision3-swift.json` comes from the actual Swift
`realEngineCompetitiveAggregateCrossLanguageFixture` test, source commit
`bf85ea8e1afd331a36ed2ecb05498ecc6ca6bb3a` (unchanged through `9b28b21`).
The PHP copy adds a final newline only; every decoded value is identical.
Its earlier-eliminated higher-score player wins with a real peak 5 and zero misses
after forfeiture. The fixture is not independent PHP replay.
