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

### Local verification — 2026-09-11

Verified implementation commit: `1860c61ae9722cb9526fa599699ac5b52536b384`,
branch `codex/mp29-rewards`, isolated worktree
`/Users/vlad/Documents/SpeedyTapper-mp29-rewards`. PHP CLI 8.5.7; disposable
MariaDB 11.4. This documentation-only follow-up does not change runtime source.
No production database, Hostinger, Railway, TestFlight or original PHP checkout
was changed. Native iOS/device acceptance remains a separate gate.

| Command | Result | Local evidence |
| --- | --- | --- |
| `composer check` | PASS: Composer validation/audit, all PHP lint and 24 test programs, including 39 new reward-contract assertions | `/tmp/speedytapper-mp29-check-final.log` |
| `composer test:mariadb:multiplayer-rewards` | PASS: 94 assertions; populated 001–024 upgrade to 025, no backfill, concurrency/rollback, reward/debt/reset/deletion and HTTP privacy | `/tmp/speedytapper-mp29-mariadb.log` |
| `composer test:mariadb:arcade-v5` | PASS: 154 assertions; retained v3/v4/v5 persisted finish/retry contracts under actual 001–025 | `/tmp/speedytapper-mp29-arcade-v5.log` |
| `composer test:mariadb:multiplayer-v2` | PASS: 132 assertions; legacy authentication, reconnect, revocation and result compatibility | `/tmp/speedytapper-mp29-v2-auth.log` |
| `composer test:mariadb:game-center` | PASS: 14 assertions | `/tmp/speedytapper-mp29-game-center.log` |
| `composer test:mariadb:internal-alpha-reset` | PASS: existing integration harness, no numeric assertion count emitted | `/tmp/speedytapper-mp29-reset.log` |
| `bash /tmp/speedytapper-mp29-nickname-tcp-check.sh` | PASS: 8 assertions using unchanged `test/nickname-migration-mariadb.php` | `/tmp/speedytapper-mp29-nickname-tcp.log` |
| `git diff --check` and `git diff --cached --check` | PASS; implementation committed and worktree clean | Local Git checks at commit above |

Nickname exception: the stock `composer test:mariadb:nickname` wrapper twice
reported its local Unix-socket readiness before MariaDB accepted the published
TCP connection, failing at PDO connection initialization (`Error while reading
greeting packet` / `MySQL server has gone away`), before any test assertion.
The last failure is `/tmp/speedytapper-mp29-nickname.log`. A temporary local
wrapper waited for `mariadb -h127.0.0.1 ... -e 'SELECT 1'` before invoking the same
unchanged PHP test against a fresh disposable database; all 8 assertions passed.
Only that task-created container/volume was removed; no repository harness or
application behavior was changed to bypass the failure. These `/tmp` evidence
files are local and ephemeral, not durable production-release records.
