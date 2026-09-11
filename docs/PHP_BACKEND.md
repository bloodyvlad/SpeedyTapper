# PHP/MariaDB backend contract

This document describes the API and operator behavior implemented by this
repository. `server/src/App.php`, its services, migrations, and deterministic
tests remain authoritative. Nothing here proves that a particular commit,
artifact, schema, worker, or external platform configuration is deployed.

## Runtime and configuration

The service requires PHP 8.2+ with cURL, Intl, JSON, mbstring, OpenSSL, PDO, and
PDO MySQL, plus MariaDB/MySQL with InnoDB, `utf8mb4`, window functions, and
advisory locks. Apache uses `mod_rewrite`; `composer dev` provides the API-only
local router.

Configuration is loaded in this order:

1. `SPEEDYTAPPER_CONFIG_PATH`, when set;
2. `~/.config/speedytapper/config.php`;
3. ignored `server/config.local.php`.

Environment values override the selected PHP array. Start from
`server/config.local.example.php`. Required or optional groups cover:

- database, season, and Google client identity;
- Sign in with Apple audience, dedicated key, refresh-token encryption, and
  optional private JWKS cache;
- Game Center assertion trust, publication credentials, encrypted persistent
  player ID, and prerelease/production lane;
- StoreKit bundle/app IDs, exact product catalog, accepted environments,
  public trust roots, retention HMAC, and Server API credentials.

Apple sign-in, StoreKit Server API, and Game Center publication use distinct
keys. Private key paths must remain outside the project and document root.

## HTTP boundary

- Requests and responses are JSON. Bodies must be JSON objects, are limited to
  256 KiB, and decode to depth 32.
- Errors use the route status with `{ "error": "message" }`.
- Responses default to `Cache-Control: no-store` and security headers. Only an
  unauthenticated Multiplayer leaderboard read gets the short public cache
  override implemented in `App.php`.
- Trailing slashes are normalized away. Unknown API paths return `404`.
- The current client contract is the exact body shown below. Identity,
  deletion, StoreKit, Multiplayer, and proof parsers reject unsupported fields.

### Cookie session and CSRF

`GET /api/session` creates or refreshes the PHP session and returns the current
`csrfToken`. The `speedytapper_session` cookie lasts 30 days and is HTTP-only,
SameSite=Lax, path `/`, and Secure on HTTPS. The database stores only the
SHA-256 digest of the rotating 256-bit authentication ID.

Every mutation except Apple's signed notification receiver requires
`X-SpeedyTapper-CSRF` and the session cookie. A request with no `Origin` is
accepted for the iOS client; an explicit mismatched origin or
`Sec-Fetch-Site: cross-site` is rejected. Login rotates the session ID,
authentication ID, CSRF token, and run binding. Logout and account deletion
revoke the database mapping, so stale PHP session files fail closed.

## Read routes

| Method and path | Authentication and response |
| --- | --- |
| `GET /api/health` | Public `{ ok, season }` health response |
| `GET /api/session` | Session, CSRF, identity bindings, profile, wallet, StoreKit, Game Center, and ranks |
| `GET /api/profile?mode=normal|zen` | Authenticated profile state and the selected solo leaderboard view |
| `GET /api/leaderboard?mode=normal|zen` | Public top five; an authenticated player's best result and neighboring ranks are added |
| `GET /api/achievements` | Five-goal catalog and optional authenticated state |
| `GET /api/pets` | Server pet catalog plus optional profile, ownership, selection, and balance |
| `GET /api/themes` | Server theme catalog plus optional profile, ownership, selection, and balance |
| `GET /api/mobile/v1/multiplayer/leaderboard` | Public top five plus optional authenticated best result and neighboring ranks |

`normal` is the ranked Arcade wire mode. Zen records remain readable through
the solo leaderboard/profile and administrator filters, but no route creates a
Zen ticket, proof, result, coin credit, or achievement.

## Identity and profile routes

| Method and path | Complete request body | Success behavior |
| --- | --- | --- |
| `POST /api/auth/google` | `{ "credential": "...", "intent": "login|register|reauth" }` | Verifies Google, resolves or creates only for the explicit intent, rotates or refreshes the primary-auth session, returns session payload |
| `POST /api/auth/apple/challenge` | `{ "intent": "login|register|link|reauth" }` | `201`; returns a five-minute single-use challenge under `appleSignIn` |
| `POST /api/auth/apple` | `{ "challengeId": "...", "state": "...", "identityToken": "...", "authorizationCode": "..." }` | Consumes the challenge, verifies token and code exchange, stores encrypted revocation material, returns session payload |
| `POST /api/profile/identities/google` | `{ "credential": "..." }` | Links Google to the recently primary-authenticated current profile |
| `POST /api/profile/game-center/challenge` | `{}` | `201`; returns a five-minute single-use Game Center challenge |
| `POST /api/profile/game-center` | `{ "challengeId": "...", "teamPlayerId": "...", "gamePlayerId": "...", "publish": true, "publicKeyUrl": "...", "signature": "...", "salt": "...", "timestamp": 1700000000000 }` | Verifies the Apple assertion, assigns the team/game pair to the current profile, enables publication, returns binding state |
| `DELETE /api/profile/game-center/publication` | `{ "confirm": true }` | Requires recent primary authentication and disables future publication |
| `POST /api/profile/nickname/availability` | `{ "nickname": "..." }` | Authenticated advisory availability response; does not reserve the name |
| `PATCH /api/profile` | `{ "nickname": "..." }` | Saves the normalized confirmed public name or returns the authoritative conflict |
| `POST /api/logout` | `{}` | Revokes the current mapping and returns an anonymous session payload |
| `DELETE /api/profile` | `{ "confirmation": "DELETE MY ACCOUNT" }` | Requires recent primary authentication, revokes Apple authorization when linked, deletes live account state, revokes sessions, returns `authenticated: false` |

Confirmed public names are NFKC-normalized Unicode of at most 20 characters,
contain no Unicode whitespace, and are unique under
`utf8mb4_unicode_ci`. Provider display names and email are not imported.

Google and Apple are primary identities. A second primary provider is attached
only through an explicit link flow. Game Center is link-only and cannot log in,
register, merge profiles, or move profile-owned value. Reassigning a verified
Game Center pair uses current-profile-wins only for that secondary binding and
publication destination.

## Ranked Arcade and progression routes

| Method and path | Current request body | Success behavior |
| --- | --- | --- |
| `POST /api/runs` | `{ "mode": "normal", "buildId": "YYYYMMDD-N" }`; optionally both exact `ruleset` and integer `proofVersion` | `201`; issues one 24-hour player/session-bound attempt and abandons any previous issued attempt for that player; omitted capabilities retain v3/proof 2 |
| `POST /api/runs/abandon` | `{ "runId": "uuid-v4" }` | Idempotent `200 { "abandoned": true }` for the bound session |
| `POST /api/runs/finish` | `{ "runId", "mode", "buildId", "ruleset", "proofVersion", "events" }` | `201` on first accepted finish, `200` on exact retry; PHP derives result and eligibility |
| `POST /api/achievements/claim` | `{ "id": "stable_achievement_id" }` | `201` on first eligible claim, `200` on retry; returns `authenticated`, `achievements`, `claimedCount`, `totalCount`, `coinBalance`, `achievement`, `coinsEarned`, and `duplicate` |
| `POST /api/pets/select` | `{ "petId": "stable_pet_id" }` | Atomically buys when necessary and selects; `201` only for a purchase |
| `PATCH /api/pets/selection` | `{ "petId": "stable_pet_id", "visible": true|false }` | Updates visibility for an owned pet |
| `POST /api/themes/select` | `{ "themeId": "stable_theme_id" }` | Atomically buys when necessary and selects; `201` only for a purchase |

Ranked run start/finish requires a Google- or Apple-authenticated profile with a
confirmed name. Accepted build IDs are exact `YYYYMMDD-N` values at or above
`20260729-1`. Build IDs do not choose semantics. Omitted capabilities use retained
`reaction-proof-v3`, proof `2`; explicit `reaction-proof-v4`, proof `3` retains
2x2 pickup eligibility (see [ARCADE_V4.md](ARCADE_V4.md)). The local v5/proof3
candidate requires 4x4 with unchanged wire tuples (see [ARCADE_V5.md](ARCADE_V5.md));
a higher build never selects different replay rules. The finish proof is capped
at 10,000 events and must match the stored ticket exactly. See
[CURRENT_VERSION.md](CURRENT_VERSION.md) for its tuples.

PHP derives score, speed ratings, multipliers, dodges, duration, risk, and coin
eligibility. One cumulative eligible Arcade minute grants one earned coin;
sub-minute remainder carries. Exact retries are idempotent. A cloned event trace
or structurally valid high-risk proof is stored but withheld from ranking and
credit.

Achievements are `complete_arcade`, `godlike_speed`, `collect_5_coins`,
`score_over_100k`, and `buy_a_pet`. The first four unlock only from eligible
verified progression; `buy_a_pet` unlocks inside the first successful pet
purchase transaction.

Pet prices are server-owned: `foka` 10, `kesha` 20, `tauta` 50, `misha` 100,
and `pancake` 500 coins. Themes are `classic` and `disco` at zero, `light` at
50, and `pixel` at 100 coins. Client price, balance, ownership, or grant values
are never authoritative.

## StoreKit routes and value

| Method and path | Complete request body | Success behavior |
| --- | --- | --- |
| `POST /api/mobile/v1/storekit/transactions` | `{ "signedTransaction": "JWS", "appAccountToken": "uuid-v4" }` | Authenticated verification and idempotent account-bound grant |
| `POST /api/app-store/notifications/v2` | `{ "signedPayload": "JWS" }` | Public Apple-signed notification verification and idempotent state transition |

The configured product map must be empty or exactly this allowlist:

| Product | Server grant |
| --- | --- |
| `com.otcsoftware.pimpopom.coins.50.v1` | 50 purchased coins and account-bound ad-free |
| `com.otcsoftware.pimpopom.coins.100.v1` | 100 purchased coins and account-bound ad-free |
| `com.otcsoftware.pimpopom.coins.500.v1` | 500 purchased coins and account-bound ad-free |
| `com.otcsoftware.pimpopom.coins.1000.v1` | 1,000 purchased coins and account-bound ad-free |
| `com.otcsoftware.pimpopom.removeads.lifetime` | Non-consumable account-bound ad-free |

Apple-signed product, bundle, environment, ownership, quantity, transaction
identity, and account binding are authoritative. Earned coins spend before
purchased lots; purchased lots spend FIFO with exact cosmetic allocation
provenance. Refunds remove affected value and funded cosmetics, reversals
restore exact prior state, and future credits settle debt before becoming
spendable.

## Multiplayer routes

The current surface is rooted at `/api/mobile/v1/multiplayer`:

| Method and suffix | Body |
| --- | --- |
| `GET /lobbies?limit=1..50` | none |
| `POST /matches` | `{ "mode": "own_color", "capacity": 2|3|4, "buildId": "YYYYMMDD-N" }` |
| `GET /matches/{uuid}` | none |
| `POST /matches/{uuid}/join` | `{}` |
| `POST /matches/{uuid}/leave` | `{}` |
| `PATCH /matches/{uuid}/readiness` | `{ "ready": true|false }` |
| `POST /matches/{uuid}/gamekit-roster` | `{ "localGamePlayerId": "...", "observedGamePlayerIds": ["..."], "coordinatorGamePlayerId": "..." }` |
| `POST /matches/{uuid}/start` | `{}` |
| `POST /matches/{uuid}/submissions` | `{ "manifestHash": "base64url-sha256", "transcript": { ... } }` |
| `GET /matches/{uuid}/settlement` | none |

All are authenticated; mutations require CSRF. Eligibility, responses,
manifest, transcript, replay, settlement, and trust limits are defined in
[MULTIPLAYER.md](MULTIPLAYER.md).

## Administrator HTTP routes

These routes require the database `leaderboard_admin` role. Mutations also
require recent primary authentication, CSRF, exact result targeting, explicit
confirmation, and an immutable audit event.

| Method and path | Contract |
| --- | --- |
| `GET /api/admin/leaderboard?view=all|scan&mode=all|normal|zen&status=all|legacy|verified|review|quarantined|deleted&offset=0&limit=1..100` | Bounded list or conservative scan; default status omits logically deleted rows |
| `GET /api/admin/leaderboard/entries/{uuid}` | Exact result, linked run, risk flags, and moderation history |
| `POST /api/admin/leaderboard/entries/{uuid}/quarantine` | `{ "reason": "...", "expectedStatus": "...", "confirm": true }` |
| `POST /api/admin/leaderboard/entries/{uuid}/delete-reset` | `{ "reason": "...", "expectedStatus": "quarantined", "confirm": true, "confirmPlayerId": "uuid-v4" }` |

Quarantine is reversible. Delete/reset is allowed only after exact-row review
and quarantine. It logically deletes the result and resets earned progression
while preserving purchased value, StoreKit/refund evidence, paid entitlements,
refund debt, and paid or mixed-funded cosmetics.

## Migrations and operator commands

The ordered schema history is `server/migrations/001_*.sql` through
`024_multiplayer_v2_heart_result_bounds.sql`. `php server/bin/migrate.php` runs pending
migrations under a database advisory lock and ensures the configured season.
Migration `020` is a destructive internal-alpha reset; verify backup,
maintenance fencing, worker pause, and explicit authorization before its first
application to any database containing live account data.

Current operator commands are:

```bash
php server/bin/migrate.php
php server/bin/leaderboard-admin.php help
php server/bin/purge-run-attempts.php [--limit=5000] [--apply]
php server/bin/publish-game-center.php [--limit=50] [--backfill]
php server/bin/publish-game-center.php --list-held
php server/bin/publish-game-center.php --requeue-held=OUTBOX_UUID
php server/bin/reconcile-storekit.php [--limit=100]
php server/bin/storekit-environment-status.php --summary
php server/bin/configure-storekit-environments.php --enable-sandbox-and-production
php server/bin/configure-game-center-publisher-key.php --key-id=APP_STORE_CONNECT_KEY_ID
```

The attempt purge and administrator mutations default to dry-run unless
`--apply` is supplied. Game Center and StoreKit workers use lane/environment
advisory locks. Held publication diagnostics expose bounded, redacted metadata.

Composer exposes `dev`, `lint`, `test`, `check`, and disposable MariaDB harnesses
for Game Center, nicknames, internal-alpha reset, Multiplayer v2 and Arcade
power-ups. See the exact `test:mariadb:*` aliases in `composer.json`.

## Deployment prerequisites

Deployment requires separate explicit authorization. Before any release:

1. run `composer check` and `git diff --check` on the exact clean commit;
2. build an allowlisted root-flat artifact from `git archive` of that commit;
3. install locked production Composer dependencies in staging;
4. inject private configuration only in staging or use the private home path;
5. use `server/.maintenance` while a separately controlled destructive
   operation must fence the API;
6. add `server/.migrations-pending` only to the artifact when ordinary pending
   migrations should run on the first API request; the marker is claimed,
   migrated under the shared lock, and removed on success;
7. verify API JSON, authentication, Arcade and Zen reads, StoreKit, Game Center,
   Multiplayer, administrator access, non-API `404`s, and denial of `server`,
   `vendor`, Git metadata, configuration, certificates, and keys;
8. record commit SHA, artifact SHA-256, exact host/path, schema/season, and a
   tested rollback artifact.

Do not infer deployed state from this file, a tag, a branch name, or a local
database.
