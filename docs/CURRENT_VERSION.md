# Current backend source contract

Reviewed: 2026-08-16

This is a snapshot of repository source. It is not a claim about a Hostinger
artifact, database migration state, external worker, iOS binary, TestFlight
build, or App Store release. Verify each external system directly before
describing it as current or deployed.

## Runtime and schema

- PHP 8.2 or newer and MariaDB/MySQL are required.
- The ordered schema history is migrations `001` through `024`, inclusive.
- Additive migration `024` preserves v2 aggregate rows while allowing bounded
  cumulative misses after heart pickups; lives remain capped at three.
- Migration `020_reset_internal_alpha_player_data.sql` is a destructive,
  idempotently claimed internal-alpha reset. Treat its first application as a
  separately authorized maintenance operation.
- Apache and the development router expose `/api` only. Private source,
  dependency, configuration, certificate, and key paths are not public API.

## Client compatibility

The minimum network build ID is `20260729-1`. A build is accepted only when it
has exact `YYYYMMDD-N` form, contains a real date and a positive sequence, and
contains no more than 32 ASCII characters. Its numeric `(date, sequence)` tuple
must be at least `(20260729, 1)`.

A higher accepted build ID is audit metadata. It does not select or infer a
new gameplay contract.

| Mode | Current contract |
| --- | --- |
| Ranked Arcade | `reaction-proof-v3`, proof version `2` |
| Ranked Multiplayer | `multiplayer-own-color-v1`, protocol version `1`, proof version `1` |
| Local Multiplayer v2 alpha | `multiplayer-shared-arcade-v2`, protocol `2`; disabled by default, service-reported aggregates only, unranked |
| Zen | Historical leaderboard/profile reads only; no ticket, proof, result, coin, or achievement write |

## Ranked Arcade

`POST /api/runs` accepts only ranked mode `normal` and an accepted build ID.
It returns a player/session-bound run UUID plus the exact v3/proof-2 contract.
`POST /api/runs/finish` must echo that stored contract in an object containing
only `runId`, `mode`, `buildId`, `ruleset`, `proofVersion`, and `events`.
`mode` is `normal`, `ruleset` is `reaction-proof-v3`, and `proofVersion` is `2`.

The non-empty integer event list has at most 10,000 entries:

| Opcode | Tuple |
| ---: | --- |
| `0` | target `[0, at, cell, targetColor]` |
| `1` | hit `[1, inputAt, handledAt, cell, resultingPlayerColor]` |
| `2` | miss `[2, inputAt, handledAt, reason, cell]` |
| `3` | decoy activation `[3, at, decoyId, cell, decoyColor, lifetimeMs]` |
| `4` | decoy expiry `[4, at, decoyId, ...]` |
| `5` | finish `[5, logicalAt, handledAt]` |
| `6` | decoy opportunity tick `[6, at]` |

Miss reasons are `0` empty, `1` wrong, and `2` late. PHP replays the
color-bearing tuples and persistent 1–3 second decoys, then derives score,
ratings, multiplier, dodges, duration, eligibility, coins, and achievements.
Only exact stored-run retries are idempotent; cloned traces are withheld.

## Ranked Multiplayer

Multiplayer is own-color play for 2–4 eligible profiles. PHP owns lobby state,
the immutable manifest, unanimous transcript collection, replay, settlement,
leaderboard rows, and publication intent. It receives no live tap traffic.

The manifest records the creator's accepted build ID and exact v1 protocol.
Each participant submits the same seat-only transcript, bounded to 2,500 events
and 900,000 logical milliseconds. Clean replay creates immutable results with
verification method `peer_consistent_v1`; Multiplayer awards no coins or
achievements. See [MULTIPLAYER.md](MULTIPLAYER.md) for the exact tuples and
lifecycle.

## Current HTTP families

- Health and session: `/api/health`, `/api/session`
- Primary identity and profile: `/api/auth/*`, `/api/profile*`, `/api/logout`
- Solo reads and progression: `/api/leaderboard`, `/api/achievements*`,
  `/api/pets*`, `/api/themes*`, `/api/runs*`
- StoreKit: `/api/mobile/v1/storekit/transactions`,
  `/api/app-store/notifications/v2`
- Multiplayer: `/api/mobile/v1/multiplayer/*`
- Unreleased v2 bridge: `/api/mobile/v2/multiplayer/tickets` and exact
  `/api/internal/multiplayer/v2/{tickets/redeem,sessions/validate,results}` routes;
  see [MULTIPLAYER_V2.md](MULTIPLAYER_V2.md).
- Operator moderation: `/api/admin/leaderboard*`

The exact methods, bodies, status behavior, authentication requirements, and
operator commands are in [PHP_BACKEND.md](PHP_BACKEND.md).
