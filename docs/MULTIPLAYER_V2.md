# Multiplayer v2 PHP bridge

Current source contract; PHP deployment independently verified on 2026-09-10 as
recorded below. This does not assert realtime/device acceptance or TestFlight
distribution. Legacy v2 remains unranked; v1 remains independently available.
The local explicit revision-3 rewards/leaderboard extension and additive025 are
documented in [MULTIPLAYER_V2_REWARDS.md](MULTIPLAYER_V2_REWARDS.md); they are not
covered by the historical deployment evidence below.

The PHP bridge authenticates the new realtime service and stores unranked alpha
aggregates plus explicitly versioned current settlement. The realtime service owns room membership, Ready, Start and gameplay;
PHP receives no live taps. The bridge does not provide a competing lobby directory.

## Configuration and migration

Additive migration `023_multiplayer_v2_auth.sql` creates four isolated tables and
does not rewrite v1 data. Exercise upgrades against a disposable schema first.
Additive migration `024_multiplayer_v2_heart_result_bounds.sql` preserves existing
aggregates and widens cumulative misses for matches with heart pickups. Apply it
before releasing heart-enabled gameplay; original v2 result payloads remain valid.
`SPEEDYTAPPER_REALTIME_URL` and `SPEEDYTAPPER_MULTIPLAYER_SERVICE_SECRET` are empty
by default. Ticket/internal bridge routes return 503 until both are valid. Use `wss://` on a host;
`ws://` is permitted only for localhost/loopback development. Credentials, query
strings and fragments are rejected in the configured URL. The independent secret
must be 32–512 printable ASCII characters and belongs only in private runtime
configuration. Never include a service key or ticket in an URL or logs.

## Exact HTTP contract

All bodies are JSON objects; unexpected fields are rejected. Responses default to
`Cache-Control: no-store`; only anonymous leaderboard reads have short public caching.
`expiresAt` is an integer Unix timestamp in seconds. Every POST below includes `protocolVersion: 2` and
`ruleset: "multiplayer-shared-arcade-v2"`. Missing/different capabilities return 409.
Higher build identifiers never select v2.

`POST /api/mobile/v2/multiplayer/tickets` requires the existing PHP cookie,
`X-SpeedyTapper-CSRF`, same-origin check, and a confirmed public name. Native
requests may omit Origin. It has a 1024-byte body limit:

```json
{"protocolVersion":2,"ruleset":"multiplayer-shared-arcade-v2"}
```

Response 201: `{ "ticket": "<opaque>", "expiresAt": 1790000060,
"realtimeURL": "wss://configured-host/socket" }`. The ticket is 256 random bits,
single use, expires after at most 60 seconds, and is bound to the exact player,
database session and protocol. No Game Center proof is required. Up to 12 tickets
per session per minute are permitted (429 above that).

The following internal endpoints require only
`Authorization: Bearer <SPEEDYTAPPER_MULTIPLAYER_SERVICE_SECRET>`. A cookie/CSRF
token cannot substitute for this secret. Authentication runs before body decoding;
bad authentication returns 401 without opening a PHP session.

| Route | Additional request fields | Success |
| --- | --- | --- |
| `POST /api/internal/multiplayer/v2/tickets/redeem` | `ticket` | 200 identity object below |
| `POST /api/internal/multiplayer/v2/sessions/validate` | `playerID`, `sessionBinding` | 200 refreshed public identity with original binding expiry |
| `POST /api/internal/multiplayer/v2/results` | Final envelope below | 200 receipt below |

Redeem/validate have 1024-byte limits. The identity object contains exactly:

```json
{"playerID":"<internal UUID>","name":"ConfirmedName","petID":null,
 "sessionBinding":"<opaque>","expiresAt":1790003600,"economyGeneration":0,
 "protocolVersion":2,"ruleset":"multiplayer-shared-arcade-v2"}
```

Only visible selected pets are included. The current identity adds unsigned
`economyGeneration`; old clients may ignore it. New services capture it at actual
match start, never overwrite it on refresh, and never substitute zero when absent.
`sessionBinding` is an independent random
credential returned to the service, not the PHP session ID or registry digest.
The binding expires after at most one hour, bounded by the source session expiry;
validation never extends it. At most 12 unexpired bindings per session may exist.
A newly consumed valid ticket atomically replaces the oldest same-session binding
when that cap is reached. The cap bounds stored credentials, not active sockets;
ordinary reconnects never require a new primary login merely to reset it. Invalid,
expired or replayed tickets cannot evict bindings, and other sessions are unaffected.
Invalid, consumed, expired, deleted or revoked credentials return 401; an
unconfirmed name returns 403. Fresh tickets are required after a binding expires.

The realtime service must validate at least every 15 seconds and before reconnect
or resuming live participation. Logout/rotation/deletion cascades credentials out
of MariaDB; the service must stop a seat on validation failure. This is bounded
polling revocation, not instantaneous push revocation. Network/PHP outage must not
silently promote a stale binding to authenticated ranked authority.

## Retained unranked aggregate intake

`POST /api/internal/multiplayer/v2/results` accepts at most 16,384 bytes:

```json
{"matchID":"<UUID v4>","protocolVersion":2,
 "ruleset":"multiplayer-shared-arcade-v2","durationMs":12000,
 "rankingEligible":false,"players":[
   {"playerID":"<UUID v4>","seat":0,"score":12500,"lives":0,
    "hits":12,"misses":3,"dodges":2,"reactionTotalMs":2500,
    "fastestReactionMs":190},
   {"playerID":"<UUID v4>","seat":1,"score":9500,"lives":0,
    "hits":10,"misses":3,"dodges":1,"reactionTotalMs":2800,
    "fastestReactionMs":220}
 ]}
```

The envelope requires 2–4 distinct existing players and contiguous seats from 0.
Duration is 0–900,000ms; score 0–100,000,000; lives 0–3; cumulative misses 0–1000; hits/dodges
0–100,000; reaction total 0–100,000,000ms; fastest reaction null or 0–1000ms.
All metrics are integers. These are admission bounds, not independent PHP replay.
`misses` counts all mistakes, including those before a heart pickup restores a
life; it is not bounded by three and is not derived as `3 - lives`.
No names, pets, credentials, wallet or achievement fields are accepted.

Response: `{"matchID":"<UUID>","duplicate":false,"rankingEligible":false,
"state":"stored_unranked"}`. A retry with the same normalized payload returns
`duplicate:true`; reordering object keys or seats does not cause a false conflict.
Different content under the same match ID returns 409. Deleted/missing players
return 409 and are never recreated by a delayed outbox delivery.

This stores one payload digest and normalized seat aggregates atomically. It does
not store raw input, claim PHP replay validation, issue rewards, or write public
rankings, v1 results, moderation state or Game Center outboxes. Account deletion
removes the entire shared alpha aggregate and its digest in the deletion transaction.
The legacy envelope has no reward receipt. The new explicit extension adds a
separate current board and private per-owner receipt, without reinterpreting any
legacy aggregate or granting it retrospective value.

## Verification and deployment evidence

`composer check` runs deterministic v2 service and App boundary tests alongside
retained v1 checks. `composer test:mariadb:multiplayer-v2` runs the same suite
against a disposable MariaDB 11.4 schema using actual migrations 023/024 and foreign
keys, including repeated upgrades, retained aggregates, reconnect credential
rotation, concurrent single-ticket redemption and revocation. The separate
account-deletion suite exercises the v2 cleanup hook.

The PHP-only deployment snapshot verified on 2026-09-10 at approximately 19:12 UTC:

- Host: `https://speedytapper.otcsoft.com`; document root
  `/home/u966828068/domains/speedytapper.otcsoft.com/public_html`.
- Exact deployed source: `9fe555d179326cecd5e23f0a6a16788b4af0ba34`, not a later
  documentation-only HEAD.
- Allowlisted runtime ZIP SHA-256:
  `080b96234b290687ddc5b3f38c88881209f640cf423766fbbfe524e3179f99c5`.
- Direct private audit confirmed ledger 001–024, unsigned SMALLINT `misses`,
  `seat <= 3 AND lives <= 3 AND misses <= 1000`, exact service source hashes,
  consumed migration markers and no public operator helper. Migration 020 was
  already applied before this release; it was not rerun.
- 35 live boundary checks passed, including TLS API responses, independent
  service authentication, session/CSRF guards and private-path denial. Runtime,
  consistent database and private configuration backups were verified. Temporary
  cron jobs were removed; the two existing workers were preserved.

Full artifact, direct-host, backup and rollback evidence is retained in the private
[release record](/Users/vlad/Documents/SpeedyTapper-release-artifacts/20260910-mp26.S14gtt/RELEASE.md).
This dated evidence is not a claim about a future deployment. Positive real-player
authentication and hosted match results were not exercised by that PHP smoke run.
Verify realtime/device behavior, durable outbox retry, cleanup scheduling, secret
rotation and credential-free observability independently when releasing the app
and socket service.
