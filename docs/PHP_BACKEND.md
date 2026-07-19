# PHP/MySQL backend integration

This document describes the PHP backend shipped from `main` to the independent Hostinger production site. The retained Vercel generation is a separate legacy rollback and is not evidence of current production state.

## Runtime and setup

- PHP 8.2 or newer with PDO MySQL, JSON, mbstring, OpenSSL, and Intl.
- MySQL 8 or a current MariaDB release with window-function support.
- Composer dependencies installed with `composer install --no-dev --optimize-autoloader`. The committed Google cleanup hook retains only the `Oauth2` service wrapper and removes unrelated generated Google API clients from the release artifact.
- Apache `mod_rewrite` enabled so the repository `.htaccess` can route extensionless `/api/*` requests to `api/index.php`.

For production, `~/.config/speedytapper/config.php` under the private hosting-account home remains the preferred location. `SPEEDYTAPPER_CONFIG_PATH` can point to another private path, and individual environment variables with the same names take precedence. The MCP-only deployment cannot write outside the target document root, so its curated artifact may instead contain the ignored `server/config.local.php`. That exception is acceptable only when the root `.htaccess` is present, direct requests to case variants of `/server/config.local.php` are verified as 403/404 with no body leakage, the archive is built from an exact commit in temporary staging, and the secret-bearing file is never committed or copied into a general source archive.

```bash
composer install
php server/bin/migrate.php
npm run check:php
```

Run `php server/bin/purge-run-attempts.php --apply` from a daily Hostinger cron job. It deletes only bounded batches of unranked stale attempt metadata (7-day abandoned/expired retention and 30-day rejected retention); dry-run is the default, and completed/ranked/reviewed runs are never eligible.

Production artifacts contain an untracked `server/.migrations-pending` marker. Only an API request that sees that marker runs the shared migration runner, ensures the configured season, and removes the marker after success. Ordinary requests do not inspect `schema_migrations` or write the season row. The database advisory lock still serializes concurrent first requests. Local and shell-capable environments run `php server/bin/migrate.php` explicitly. Migrations create a season, Google-backed internal player profiles, and immutable leaderboard results. Migration `004` historically deleted pre-multiplier leaderboard rows once; migration `005` preserves multiple results; `006` adds server-issued run proofs and moderation; `007` adds durable pets; `008` adds achievements; `009` adds debt-aware economy events; `010` keeps the selected pet while persisting whether it is shown; and `011` adds database roles, paginated web moderation, immutable account reward-reset audits, and economy generations. Only `SHA-256("google\\0" + sub)` is stored from the Google identity token; email claims and raw Google subject values are not stored.

Migration `012` extends that sequence with the authoritative theme catalog, paid ownership/selection, `theme_purchase` ledger events, and theme-aware reset audits. It does not clear leaderboard results.

Migration `013` gives the original migration-011 bootstrap administrator zero-price test ownership of every active shop pet and paid theme. It targets the internal UUID only through the durable `leaderboard_admin` role row whose `granted_by` value is `migration-011`; it never uses nickname, email, Google identity, score, or browser state. These ownership rows do not alter coins, selections, visibility, achievements, or `coin_ledger`, and an existing paid purchase is preserved. Default and Disco remain implicit free ownership. A destructive account reward reset removes the one-time grants and the migration runner does not apply them again.

## Backend-first StoreKit and account-deletion foundation

This section describes the StoreKit and deletion boundary included in the current backend. Account deletion is active when this build is deployed. The player-facing StoreKit purchase and restore UI remains a placeholder; do not advertise products, paid balance, ad-free entitlement, or restore as released until Apple configuration is complete and the full purchase, notification, reconciliation, refund, reversal, restore, and Family Sharing flows have been exercised in Apple Sandbox on a physical device.

### Runtime, configuration, and migrations

The StoreKit backend additionally requires PHP cURL; `composer.json` declares `ext-curl` because App Store Server API reconciliation uses the cURL extension. StoreKit remains disabled unless the bundle, explicit accepted environment list, exact product map, at-least-32-byte retention HMAC key, and pinned Apple trust roots are configured. Production requires numeric Apple App ID `6792328590`. Configure `SPEEDYTAPPER_STOREKIT_ENVIRONMENTS` as `['Sandbox', 'Production']` (or a comma-separated environment value); the older singular environment key is only a one-environment fallback. Reconciliation additionally requires the App Store Connect issuer ID, key ID, and private `.p8` path outside the web root.

On an existing MCP-only Hostinger installation, the guarded operator command `php server/bin/configure-storekit-environments.php --enable-sandbox-and-production` atomically updates only the private home configuration and validates the resulting dual-environment Server API setup. It never prints configuration values. `php server/bin/storekit-environment-status.php --summary` reports only each environment's latest retained `TEST` notification and reconciliation timestamps/error, allowing deployment verification without exposing signed payloads or payment evidence.

Migration `014_storekit_paid_value_and_account_deletion.sql`:

- separates `earned_coins`/`earned_coin_debt` from `purchased_coins`/`refund_coin_debt`, preserving the compatibility totals in `coins` and `coin_debt`;
- backfills every pre-migration wallet and ledger amount as earned value;
- adds immutable signed transaction observations, notification evidence, purchased-coin lots, entitlement sources, exact spend allocations, refund-debt allocations, refunded-cosmetic and cosmetic-restore-debt records, Family Sharing tombstones, and reconciliation cursors;
- retains source conservation constraints and makes the StoreKit/payment rows detachable from a deleted player.

Migration `015_player_sessions.sql` adds a server-side opaque-session registry. A PHP session stores a rotating 256-bit authentication ID rather than a raw player UUID; MySQL stores only its SHA-256 digest, player mapping, and 30-day expiry. Login rotates both the PHP session and opaque authentication ID. Logout revokes the mapping, player deletion removes every mapping by foreign-key cascade, missing/expired mappings fail closed, and legacy PHP sessions containing the old raw UUID cannot authenticate.

Forward-only migration `016_storekit_schema_hardening.sql` is required for installations that may already have recorded an earlier form of migration `014`. It makes the reward-reset administrator reference nullable with `ON DELETE SET NULL`, ensures the `(environment, transaction_id)` reconciliation index, adds/backfills a distinct non-null `lifecycle_signed_date_ms` watermark, and leaves clean installations at the same finalized schema already declared by the current `014` migration.

### Exact product and trust contract

The product map must be empty (StoreKit disabled) or exactly the following five rows; partial or extra maps fail closed.

| Product ID | Signed type | Coins | Ad-free source | Recommended US price | Restore/Family Sharing policy |
| --- | --- | ---: | --- | ---: | --- |
| `com.otcsoftware.pimpopom.coins.50.v1` | Consumable | 50 | Account-bound | $2.99 | Neither |
| `com.otcsoftware.pimpopom.coins.100.v1` | Consumable | 100 | Account-bound | $4.99 | Neither |
| `com.otcsoftware.pimpopom.coins.500.v1` | Consumable | 500 | Account-bound | $9.99 | Neither |
| `com.otcsoftware.pimpopom.coins.1000.v1` | Consumable | 1,000 | Account-bound | $14.99 | Neither |
| `com.otcsoftware.pimpopom.removeads.lifetime` | Non-Consumable | 0 | Account-bound | $1.99 | Apple-restorable and Family-Shareable |

Apple is authoritative for localized storefront prices. A price never enters the grant calculation: the server maps a verified product ID to its fixed coin amount. It accepts only one signed unit and rejects Family Sharing for a consumable. A Family Sharing beneficiary can receive only the standalone lifetime ad-free entitlement and receives no coins. Each direct coin pack also creates its own account-bound ad-free source. `adFree` is true while any active verified source remains, so refunding one purchase does not cancel a separate valid pack or lifetime source.

The client submits a signed transaction and the `appAccountToken` previously issued for its authenticated PimPoPom profile. The submitted token must match the server binding, and the signed transaction must carry the same token for direct ownership. The server verifies Apple's ES256 JWS and pinned three-certificate chain, then checks the signed transaction/product identity, signed product type, direct/Family ownership, exact bundle, accepted signed environment, one-unit quantity, dates, and account binding. It never trusts client quantity, price, balance, product type, ownership, entitlement, or coin totals. Transaction JWS has no Apple App ID field; Production App ID validation therefore occurs on the independently verified outer Notifications V2 data object, while Sandbox permits the field to be absent and rejects it if present but wrong.

### Wallet allocation, refunds, entitlements, and moderation

`wallet.total` is the sum of spendable earned and purchased coins; the two sources remain separate. Cosmetic spending is serialized on the player and blocked while `refundDebt` is outstanding. It consumes earned coins first, then the oldest available purchased lots by `(credited_at, transaction_id)`. Every pet or theme ledger debit gets allocation rows that preserve the exact earned amount and each purchased transaction-lot amount, including a mixed-source split.

For a verified `REFUND` or `REVOKE`:

1. Remove only the refunded transaction's unspent lot value and deactivate only that transaction's ad-free entitlement source.
2. Identify pet/theme purchases with an active allocation from that exact lot; unrelated cosmetics remain owned.
3. Revoke each affected cosmetic and reverse its whole debit. Earned allocations and allocations from other purchased lots return to those same sources; the refunded lot's contribution does not become spendable again.
4. Record the transaction's already-spent or debt-settling shortfall as `refundDebt`. New earned rewards/run credits and new verified purchased credits settle exact refund obligations before increasing either spendable source.

`REFUND_REVERSED` is ordered by the dedicated lifecycle signed-date watermark (the outer notification signed date when present) and idempotently reinstates the lot, entitlement source, exact prior debt-settlement allocations, and refund-revoked cosmetics. Restoring a cosmetic reapplies its recorded debit against current earned-first/FIFO funds; any unavailable amount remains explicit refund debt rather than inventing value. Repeated, stale, crossed, or out-of-order deliveries cannot apply the same transition twice or roll a newer state backward. Ad-free remains active whenever any other valid source survives.

Leaderboard moderation may revoke or restore earned eligibility and can reopen the exact earned credits that previously settled refund debt. It must never zero, recreate, or net away purchased balances, purchased-lot history, StoreKit refund debt, active paid entitlement sources, or cosmetics funded by paid allocations. The immutable allocation graph, not a current aggregate balance, is the authority for paid-value effects.

### Candidate API and session payload

All authenticated StoreKit and account-deletion routes retain the existing same-origin and `X-SpeedyTapper-CSRF` requirements.

- `POST /api/storekit/transactions` and `POST /api/mobile/v1/storekit/transactions` accept exactly `{ "signedTransaction": "APPLE_JWS", "appAccountToken": "server-issued-uuid" }`. Unknown fields are rejected. The response contains `transactionId`, `status`, `duplicate`, the refreshed `wallet`, and `adFree`.
- `POST /api/app-store/notifications/v2` accepts exactly `{ "signedPayload": "APPLE_NOTIFICATION_V2_JWS" }`. It does not use a player session. The verified outer payload must be V2 and carry the exact bundle plus an accepted signed environment; Production must also carry Apple App ID `6792328590`. A nested transaction must independently verify and match the outer environment. Environment-scoped notification UUID plus payload hash supplies retry idempotency. `ONE_TIME_CHARGE`, `REFUND`, `REVOKE`, and `REFUND_REVERSED` are processed; `TEST`, unsupported events, and `CONSUMPTION_REQUEST` are durably retained/ignored without inventing a policy response.
- `DELETE /api/profile`, `DELETE /api/account`, and `DELETE /api/mobile/v1/account` require `{ "confirmation": "DELETE MY ACCOUNT" }` after recent Google authentication and return `deleted: true`, `authenticated: false`, plus counts of the detached StoreKit evidence retained.

Authenticated `GET /api/session` responses, and authenticated `GET`/`PATCH /api/profile` responses, add this server-authoritative state:

```json
{
  "wallet": {
    "earned": 50,
    "purchased": 100,
    "earnedDebt": 0,
    "refundDebt": 0,
    "total": 150
  },
  "adFree": true,
  "storeKit": {
    "appAccountToken": "server-issued-uuid",
    "bindingStatus": "bound"
  }
}
```

The binding token is an account correlation value for StoreKit's signed `appAccountToken`, not payment proof. When signed out, the session response instead uses `wallet: null`, `adFree: false`, and `storeKit: null`. The existing compatibility `profile.coins` remains the aggregate spendable total while clients migrate to the source-aware `wallet` object. The current browser/native purchase surface does not yet consume these endpoints and remains a placeholder.

### Notifications and reconciliation

App Store Server Notifications V2 are the primary asynchronous refund/reversal path. Notification UUID and transaction ID are each scoped by the verified `Sandbox` or `Production` environment before persistence, so identical Apple test identifiers cannot cross-credit or cross-revoke. Within an environment, payload hash plus immutable signed fields make retries idempotent and conflicting replays fail closed. Transaction JWS observations retain their own maximum signed date, while lifecycle changes use the separate outer-notification signed-date watermark when available, so an older refund or reversal cannot undo a newer transition merely because Apple re-signed the nested transaction later.

Run `php server/bin/reconcile-storekit.php --limit=100` from a bounded cron or operator job after configuring App Store Server API credentials. One invocation iterates every accepted environment, uses an environment-specific MySQL advisory lock, calls that environment's Apple API origin, replays history with a one-minute overlap (or the preceding 24 hours on its first run), follows at most 20 history pages, and rechecks an Apple-transaction-ID-ordered batch. The default transaction limit is 100 and the maximum is 500. Each environment stores its own history timestamp, raw Apple transaction cursor, and last error in `storekit_reconciliation_state`; failure in one environment is recorded without reusing or advancing the other's state. This repair loop complements notifications and client delivery rather than replacing JWS verification.

### Account deletion and retained evidence

Deletion requires an authenticated profile, same-origin CSRF, the exact case-sensitive confirmation `DELETE MY ACCOUNT`, and a Google login no more than 15 minutes old. One transaction removes the player row and therefore the Google-subject digest, public nickname, achievements, pet/theme ownership and selection, roles, every opaque browser-session mapping, public leaderboard entries, completed/issued runs, proofs, trace claims, moderation rows targeting the player, and ordinary gameplay/economy ledger history. References where the deleted account acted on another player's retained moderation history are anonymized rather than deleting that other player's evidence.

Only the minimum StoreKit settlement graph remains: signed transaction and observation metadata, notification hashes/status, purchased lots, entitlement-source rows, purchased spend allocations, exact refund-debt settlements, refund-revoked cosmetics, cosmetic-restore debts, and Family Sharing tombstones. Those rows are detached by setting `player_id` to null; raw account bindings and retained raw `appTransactionId` values are removed, and linkable spend/family/account references use domain-separated HMAC-SHA-256 pseudonyms under the private retention key. Ordinary earned allocation history is deleted, except an earned credit already used to settle an outstanding App Store refund is retained only as pseudonymized settlement provenance.

This retained evidence has no nickname, Google digest, public player UUID, active session mapping, or live StoreKit account binding. It exists only so later Apple refund/reversal traffic can conserve prior paid value and so a deleted Family Sharing identity cannot be replayed onto another profile. A later reversal updates detached evidence but never recreates, rebinds, or publishes the deleted account.

## API contract

All private and personalized responses are JSON with `Cache-Control: no-store`. The public `GET /api/top-scores` response permits a short five-second browser cache and ten-second shared cache. Mutations accept same-origin JSON and require the `X-SpeedyTapper-CSRF` token returned by the session endpoint. Authentication uses a secure, HTTP-only, SameSite=Lax PHP session cookie. Google Identity Services supplies the `credential` ID token, which is verified server-side by `google/apiclient` against the configured Web client ID. Login regenerates the cookie session ID and rotates CSRF state. Ranked attempts can only be issued after sign-in and nickname confirmation; a signed-out run is local practice and cannot be promoted later. PHP session locks are released before ranked proof or leaderboard database work.

### `GET /api/session`

Always public. The Google client ID is intentionally public configuration.

```json
{
  "authenticated": true,
  "csrfToken": "public-session-mutation-token",
  "googleClientId": "...apps.googleusercontent.com",
  "season": { "id": "season-1", "name": "Season 1" },
  "profile": {
    "id": "internal-uuid",
    "nickname": "Player",
    "nicknameConfirmed": true,
    "coins": 75,
    "ownedThemeIds": ["classic", "disco", "light"],
    "selectedThemeId": "light",
    "createdAt": "2026-07-13T12:00:00.000Z",
    "updatedAt": "2026-07-13T12:00:00.000Z"
  },
  "ranks": {
    "normal": { "rank": 12, "totalEntries": 250, "topPercent": 5 },
    "zen": { "rank": null, "totalEntries": 180, "topPercent": null }
    }
```

The response also includes `achievementSnapshot`, allowing the menu to render claim status without a second API request. When signed out, `authenticated` is false and `profile` and `ranks` are null.

### `POST /api/auth/google`

Body: `{ "credential": "GOOGLE_ID_TOKEN" }`. Finds or creates the internal UUID profile, regenerates the session ID, and returns the same body as `GET /api/session`. No email or Google display name is persisted. A new profile receives a neutral placeholder and `nicknameConfirmed: false`; the player must explicitly save a public nickname before a result can be submitted.

### `POST /api/logout`

Clears and expires the server session. Returns the signed-out session shape.

### `GET` or `PATCH /api/profile`

Authentication required. `PATCH` body: `{ "nickname": "Public name" }`. Saving it sets `nicknameConfirmed: true`. The response contains `profile`, `ranks`, and `leaderboard`; use `?mode=normal` or `?mode=zen` to choose the ±2 context shown in `leaderboard`.

### `GET /api/leaderboard?mode=normal|zen`

Returns the top five and, when signed in and ranked, the player's best result with up to two positions on each side. `playerRank` and `topPercent` always describe that best result and are null when signed out or unranked. Percentages are percentiles of ranked results, not distinct profiles. `contextRank`, `contextTopPercent`, and `contextEntryId` identify the result used for the returned nearby window; on a normal read this is the same best result.

```json
{
  "season": { "id": "season-1", "name": "Season 1" },
  "mode": "normal",
  "entries": [
    {
      "id": "entry-uuid",
      "rank": 1,
      "name": "Player",
      "mode": "normal",
      "score": 12345,
      "survivalMs": 91000,
      "fastestReactionMs": 178,
      "averageReactionMs": 331,
      "hits": 24,
      "dodges": 7,
      "speedRatings": { "godlike": 2, "perfect": 6, "great": 8, "good": 8 },
      "createdAt": "2026-07-13T12:00:00.000Z",
      "isCurrentPlayer": true,
      "isContextResult": true
    }
  ],
  "totalEntries": 250,
  "playerRank": 1,
  "topPercent": 1,
  "contextRank": 1,
  "contextTopPercent": 1,
  "contextEntryId": "entry-uuid"
}
```

### `GET /api/top-scores?mode=normal|zen`

Returns only the public top-five entries and never opens a PHP session or reads a player profile. The mode-specific ordered query is index-bounded, and the response is cacheable for five seconds in a browser and ten seconds in a shared cache. Gameplay uses this endpoint only to seed the HUD's best score; the full leaderboard view continues to use the personalized endpoint above.

### `POST /api/runs`

Starts a ranked Arcade run before the first board presentation. Authentication and a confirmed public nickname are required. Body: `{ "mode": "normal", "buildId": "20260718-1" }`. The server returns a one-time `runId`, mode, build, `ruleset`, and `proofVersion`. The attempt is bound to the player and current browser session; issuing a new attempt abandons that player's older unsubmitted attempt. `mode: "zen"` is rejected because Zen is always endless local practice. A failed Arcade request may still start a local practice game, but that result is never rankable and never earns coins.

### `POST /api/runs/abandon`

Body: `{ "runId": "server-run-uuid" }`. Closes an issued run after restart, menu navigation, page backgrounding, or a discarded result. Completed and already-closed runs are unchanged.

### `POST /api/runs/finish`

Authentication and a confirmed public nickname are required. The body contains the server run identity and compact proof events only; it never contains authoritative score, duration, rating totals, coins, a player name, email, or password.

```json
{
  "runId": "server-run-uuid",
  "mode": "normal",
  "buildId": "20260718-1",
  "ruleset": "reaction-proof-v2",
  "proofVersion": 1,
  "events": [
    [2, 100, 102, 0, 0],
    [2, 200, 202, 0, 0],
    [2, 300, 302, 0, 0],
    [5, 300, 302]
  ]
}
```

Event opcodes represent target presentation, accepted pointer input, misses, decoy creation, natural decoy expiry, an ignored decoy opportunity, and completion. PHP validates their lifecycle, independent timer windows, response windows, and streak rules, then derives the canonical score. Active ranked proofs are Arcade-only and require the third life loss. The server refuses both ranked Zen ticket creation and Zen result submission; Zen runs locally as endless no-decoy practice and never enter the proof, leaderboard, achievement, or coin paths. The validator retains support for historical three-minute Zen proofs only so existing audit data and deterministic legacy checks remain interpretable. The server clock must cover an Arcade proof's handled timeline without an unexplained submission gap. Every accepted run is inserted as an immutable result using `runId` as its entry ID, and a trace hash prevents the same event stream from being credited under a second run ID. A response has the leaderboard shape above plus `rank`, `submittedRank`, `submittedEntryId`, `improved`, `verificationStatus`, coin accounting, `verifiedResult`, and the current achievement snapshot. The browser patches its current profile/rank state from this response instead of immediately requesting session, achievements, and leaderboard again. Repeating the same run ID returns idempotently without another row or coin award; reusing its event trace under another ID is quarantined and revoked rather than sent to an approvable review queue. A `review` result is stored for audit but has no submitted rank and earns no coins unless an operator explicitly approves it.

### `GET /api/achievements` and `POST /api/achievements/claim`

Authentication is required. The read returns the five active catalog goals with per-player unlock/claim state. The former three-minute Zen goal is retired; historical rows remain database audit data but are not claimable through the active catalog. Claim body: `{ "achievementId": "stable_catalog_id" }`. Only protocol-verified, coin-eligible Arcade runs unlock gameplay goals. A claim is idempotent, pays any outstanding coin debt before increasing spendable coins, and records one immutable `achievement_reward` ledger event.

### `GET /api/pets`, `POST /api/pets/select`, and `PATCH /api/pets/selection`

Authentication and a confirmed nickname are required for selection. The public read returns the server catalog, owned IDs, remembered selection, visibility, shown/equipped pet, and spendable coin balance. Selection body: `{ "petId": "stable_catalog_id" }`. An owned pet is selected and shown free. A first purchase locks the player, debits the authoritative price, records ownership, unlocks **Buy a pet**, selects and shows the pet, and appends a negative `pet_purchase` ledger event in one transaction. Visibility body: `{ "petId": "stable_catalog_id", "visible": false }`; it can hide or show only that profile's current selection and never removes ownership.

### `GET /api/themes` and `POST /api/themes/select`

The read is public and returns the four-row server catalog plus the current profile, ownership/selection, and spendable balance when authenticated. Stable IDs and prices are `classic`/Default/free, `disco`/Disco/free, `light`/Light/50, and `pixel`/Pixel/100. Browser values are presentational only; the server never accepts a submitted price, balance, or ownership claim.

Selection requires authentication, same-origin CSRF, and body `{ "themeId": "stable_catalog_id" }`. Default and Disco are always owned. Selecting any owned theme updates the remembered choice without cost. Selecting an unowned paid theme locks the player row, verifies the authoritative catalog price and current balance, debits once, inserts `player_themes`, upserts `player_theme_selection`, and appends a generation-qualified negative `theme_purchase` ledger event in one transaction. A duplicate or retried selection cannot charge an existing owner again. The response returns the refreshed public profile, purchase flag, exact price paid, and resulting balance.

Legacy `POST /api/leaderboard` aggregate submission returns HTTP 410 and can never award a result.

### Leaderboard administrator API

Every route below requires a current Google-authenticated profile whose internal UUID has the database role `leaderboard_admin`. The profile and session payload expose only the derived `isAdmin` boolean; the browser cannot grant the role. Migration `011` bootstraps the initial administrator only when the exact production result IDs `d4e98497-9212-475e-8664-283171ce3910` and `82ee646d-28d9-43f8-9e38-e4e234a02db1` still belong to the same player. No score, nickname, rank, email, or client flag is consulted after that migration.

- `GET /api/admin/leaderboard?view=all|scan&mode=all|normal|zen&status=all|legacy|verified|review|quarantined|deleted&offset=0&limit=100` returns one bounded page. The default `status=all` view omits logically deleted rows; only explicit `status=deleted` returns them. `hasMore` drives explicit pagination; a scan page additionally returns its scanned and flagged counts.
- `GET /api/admin/leaderboard/entries/{entryUuid}` returns the exact result, conservative scan flags, linked run metadata, and moderation history while withholding browser/session and proof hash material.
- `POST /api/admin/leaderboard/entries/{entryUuid}/quarantine` accepts `{ "reason": "...", "expectedStatus": "verified", "confirm": true }` and performs one exact-result quarantine.
- `POST /api/admin/leaderboard/entries/{entryUuid}/delete-reset` accepts `{ "reason": "...", "expectedStatus": "quarantined", "confirm": true, "confirmPlayerId": "exact-player-uuid-from-the-selected-row" }`. It refuses any result that was not reviewed and quarantined first, or if the selected row and confirmed account no longer match.

Both mutations require same-origin CSRF protection and a Google login verified within the preceding 15 minutes. An authorized administrator may moderate any exact result, including their own or another administrator's; exact target confirmation, quarantine-before-delete, reason, expected status, and immutable audit requirements still apply. Delete-and-reset is one transaction: it logically deletes the result, revokes its strictly linked run when present, abandons any outstanding issued attempt, and resets only earned progression. It sets earned coins and earned debt to zero, clears the sub-minute remainder plus current collected/play totals, advances the economy generation, and removes only pets/themes whose active purchase event has no active purchased-lot allocation. A cosmetic with even one active purchased allocation is retained, so both fully paid and mixed earned/purchased cosmetics, including their current selection, survive. Purchased coins/lots, IAP and refund history, active paid entitlement sources, and `refundDebt` are never cleared; invalidating an earned credit that previously settled refund debt reopens that exact obligation. Removed earned-only/test-grant cosmetic IDs, moderation, and the reward reset are audited. Achievements and immutable proof/run/ledger/moderation history remain, and repeating the same reset UUID returns its recorded result without forfeiting later earnings.

## Security and limitations

- The session cookie, same-origin mutation guard, and per-session CSRF token prevent common cross-site mutations, while Google verifies account ownership.
- The Google subject is irreversibly digested before storage. Raw tokens, email claims, and passwords are never stored.
- PHP issues the run ID, binds it to one confirmed player and browser session, permits only one issued attempt per player, bounds elapsed time with its own clock, replays the chronological proof, derives all result fields, and consumes the run once. Start and completion limits are persisted by internal player UUID, so re-login does not clear them.
- Requests are capped at 256 KiB and 10,000 proof events. An authenticated per-session finish limit is consumed before proof JSON is parsed, while persisted per-minute and daily player limits run before replay or proof persistence. Rejected proofs retain hashes and compact audit metadata rather than attacker-controlled event JSON. The bounded maintenance command removes stale unranked attempts. Shared-hosting or edge-level IP throttling remains recommended for broader availability protection.
- This is protocol verification, not proof of human input. A sufficiently modified browser, scripted client, or computer-vision bot can still create plausible real-time play. High-risk distributions can be held for manual review; never describe the board as bot-proof.
- Existing aggregate rows are `legacy` because they cannot be retrospectively verified. `server/bin/leaderboard-admin.php` can list all records or filter suspected/non-ranked states and supports exact-ID `approve`, `reject`, `quarantine`, `restore`, and logical `delete`; mutations are dry-run by default. Quarantine is reversible. Coin reconciliation recomputes only earned eligibility, earned cosmetic allocations, and achievement rewards while preserving purchased balances/lots, IAP/refund history, active paid entitlements, paid/mixed-funded cosmetics, and refund debt. Revoked earned value becomes earned debt when spent, or reopens its exact StoreKit refund-debt settlement when that is where the credit went.
- Browser administration is deliberately narrower than the maintenance CLI: it exposes bounded reads, quarantine, and the explicit quarantined-result delete-and-reset sanction. It never exposes arbitrary actor text, generic approval/restore/reconcile operations, or account-role editing.
- PHP's default file-session store is appropriate for this single shared-hosting deployment. A multi-node deployment would need shared session storage or signed/revocable session tokens.
- Configure the Google OAuth Web client with the final HTTPS origin before sign-in can be production-tested.
