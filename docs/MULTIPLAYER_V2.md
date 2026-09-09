# Multiplayer v2 PHP bridge — local source only

Implemented locally on 2026-09-09. Not deployed, configured, ranked, or a claim of
complete realtime/device acceptance. V1 remains independently available.

The PHP bridge authenticates the new realtime service and stores unranked alpha
aggregates. The realtime service owns room membership, Ready, Start and gameplay;
PHP receives no live taps. The bridge does not provide a competing lobby directory.

## Configuration and migration

Apply additive migration `023_multiplayer_v2_auth.sql` to a disposable/local schema
for development. It creates four isolated tables and does not rewrite v1 data.
`SPEEDYTAPPER_REALTIME_URL` and `SPEEDYTAPPER_MULTIPLAYER_SERVICE_SECRET` are empty
by default. All v2 routes return 503 until both are valid. Use `wss://` on a host;
`ws://` is permitted only for localhost/loopback development. Credentials, query
strings and fragments are rejected in the configured URL. The independent secret
must be 32–512 printable ASCII characters and belongs only in private runtime
configuration. Never include a service key or ticket in an URL or logs.

## Exact HTTP contract

All bodies are JSON objects; unexpected fields are rejected. All responses are
`Cache-Control: no-store`. `expiresAt` is an integer Unix timestamp in seconds.
Every request below includes `protocolVersion: 2` and
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
 "sessionBinding":"<opaque>","expiresAt":1790003600,
 "protocolVersion":2,"ruleset":"multiplayer-shared-arcade-v2"}
```

Only visible selected pets are included. `sessionBinding` is an independent random
credential returned to the service, not the PHP session ID or registry digest.
The binding expires after at most one hour, bounded by the source session expiry;
validation never extends it. At most 12 unexpired bindings per session may exist.
Invalid, consumed, expired, deleted or revoked credentials return 401; an
unconfirmed name returns 403. Fresh tickets are required after a binding expires.

The realtime service must validate at least every 15 seconds and before reconnect
or resuming live participation. Logout/rotation/deletion cascades credentials out
of MariaDB; the service must stop a seat on validation failure. This is bounded
polling revocation, not instantaneous push revocation. Network/PHP outage must not
silently promote a stale binding to authenticated ranked authority.

## Unranked aggregate intake

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
Duration is 0–900,000ms; score 0–100,000,000; lives/misses 0–3; hits/dodges
0–100,000; reaction total 0–100,000,000ms; fastest reaction null or 0–1000ms.
All metrics are integers. These are admission bounds, not independent PHP replay.
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
There is no public v2 result read or ranked season in this implementation.

## Verification and remaining integration

`composer check` runs deterministic v2 service and App boundary tests alongside
retained v1 checks. `composer test:mariadb:multiplayer-v2` runs the same suite
against a disposable MariaDB 11.4 schema using actual migration 023 and foreign
keys. The separate account-deletion suite exercises the v2 cleanup hook.

Before hosting: verify TLS and Authorization forwarding on the actual API host,
service revalidation/reconnect behavior, durable outbox retry, cleanup scheduling,
secret rotation, correlated logs without credentials, and backup/rollback. The
current PHP work is local code only; hosting purchase/deployment remain unauthorised.
