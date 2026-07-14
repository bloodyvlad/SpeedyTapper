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

The API automatically applies pending migrations before dispatch, serialized with a database-scoped advisory lock. The CLI uses the same runner for explicit maintenance. Migrations create a season, Google-backed internal player profiles, and immutable leaderboard results. Migration `004` historically deleted pre-multiplier leaderboard rows once; migration `005` preserves multiple results; `006` adds server-issued run proofs and moderation; `007` adds durable pets; `008` adds achievements; `009` adds debt-aware economy events; `010` keeps the selected pet while persisting whether it is shown; and `011` adds database roles, paginated web moderation, immutable account reward-reset audits, and economy generations. Only `SHA-256("google\\0" + sub)` is stored from the Google identity token; email claims and raw Google subject values are not stored.

Migration `012` extends that sequence with the authoritative theme catalog, paid ownership/selection, `theme_purchase` ledger events, and theme-aware reset audits. It does not clear leaderboard results.

## API contract

All responses are JSON with `Cache-Control: no-store`. Mutations accept same-origin JSON and require the `X-SpeedyTapper-CSRF` token returned by the session endpoint. Authentication uses a secure, HTTP-only, SameSite=Lax PHP session cookie. Google Identity Services supplies the `credential` ID token, which is verified server-side by `google/apiclient` against the configured Web client ID. Login regenerates the cookie session ID and rotates CSRF state. Ranked attempts can only be issued after sign-in and nickname confirmation; a signed-out run is local practice and cannot be promoted later.

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

When signed out, `authenticated` is false and `profile` and `ranks` are null.

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

### `POST /api/runs`

Starts a ranked Arcade run before the first board presentation. Authentication and a confirmed public nickname are required. Body: `{ "mode": "normal", "buildId": "20260715-1" }`. The server returns a one-time `runId`, mode, build, `ruleset`, and `proofVersion`. The attempt is bound to the player and current browser session; issuing a new attempt abandons that player's older unsubmitted attempt. `mode: "zen"` is rejected because Zen is always endless local practice. A failed Arcade request may still start a local practice game, but that result is never rankable and never earns coins.

### `POST /api/runs/abandon`

Body: `{ "runId": "server-run-uuid" }`. Closes an issued run after restart, menu navigation, page backgrounding, or a discarded result. Completed and already-closed runs are unchanged.

### `POST /api/runs/finish`

Authentication and a confirmed public nickname are required. The body contains the server run identity and compact proof events only; it never contains authoritative score, duration, rating totals, coins, a player name, email, or password.

```json
{
  "runId": "server-run-uuid",
  "mode": "normal",
  "buildId": "20260715-1",
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

Event opcodes represent target presentation, accepted pointer input, misses, decoy creation, natural decoy expiry, an ignored decoy opportunity, and completion. PHP validates their lifecycle, independent timer windows, response windows, and streak rules, then derives the canonical score. Active ranked proofs are Arcade-only and require the third life loss. The server refuses both ranked Zen ticket creation and Zen result submission; Zen runs locally as endless no-decoy practice and never enter the proof, leaderboard, achievement, or coin paths. The validator retains support for historical three-minute Zen proofs only so existing audit data and deterministic legacy checks remain interpretable. The server clock must cover an Arcade proof's handled timeline without an unexplained submission gap. Every accepted run is inserted as an immutable result using `runId` as its entry ID, and a trace hash prevents the same event stream from being credited under a second run ID. A response has the leaderboard shape above plus `rank`, `submittedRank`, `submittedEntryId`, `improved`, `verificationStatus`, coin accounting, and `verifiedResult`. Repeating the same run ID returns idempotently without another row or coin award; reusing its event trace under another ID is quarantined and revoked rather than sent to an approvable review queue. A `review` result is stored for audit but has no submitted rank and earns no coins unless an operator explicitly approves it.

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

Both mutations require same-origin CSRF protection and a Google login verified within the preceding 15 minutes. An authorized administrator may moderate any exact result, including their own or another administrator's; exact target confirmation, quarantine-before-delete, reason, expected status, and immutable audit requirements still apply. Delete-and-reset is one transaction: it logically deletes the result, revokes its strictly linked run when present, abandons any outstanding issued attempt, removes all selected/owned pets and paid themes, records removed cosmetic IDs, zeroes wallet/debt/remainder/current collected and play totals, advances the account economy generation, and appends both moderation and reward-reset audit records. Achievements remain. Old proofs, completed runs, moderation events, and coin-ledger rows remain immutable, but only the current generation contributes to later reconciliation or historical achievement eligibility. Repeating the same delete-reset UUID returns its recorded reset without forfeiting future rewards again.

## Security and limitations

- The session cookie, same-origin mutation guard, and per-session CSRF token prevent common cross-site mutations, while Google verifies account ownership.
- The Google subject is irreversibly digested before storage. Raw tokens, email claims, and passwords are never stored.
- PHP issues the run ID, binds it to one confirmed player and browser session, permits only one issued attempt per player, bounds elapsed time with its own clock, replays the chronological proof, derives all result fields, and consumes the run once. Start and completion limits are persisted by internal player UUID, so re-login does not clear them.
- Requests are capped at 256 KiB and 10,000 proof events. An authenticated per-session finish limit is consumed before proof JSON is parsed, while persisted per-minute and daily player limits run before replay or proof persistence. Rejected proofs retain hashes and compact audit metadata rather than attacker-controlled event JSON. The bounded maintenance command removes stale unranked attempts. Shared-hosting or edge-level IP throttling remains recommended for broader availability protection.
- This is protocol verification, not proof of human input. A sufficiently modified browser, scripted client, or computer-vision bot can still create plausible real-time play. High-risk distributions can be held for manual review; never describe the board as bot-proof.
- Existing aggregate rows are `legacy` because they cannot be retrospectively verified. `server/bin/leaderboard-admin.php` can list all records or filter suspected/non-ranked states and supports exact-ID `approve`, `reject`, `quarantine`, `restore`, and logical `delete`; mutations are dry-run by default. Quarantine is reversible. Coin reconciliation recomputes eligible play plus pet/theme spending and achievement rewards; if revoked earnings were already spent, `coin_debt` absorbs later credits before the spendable balance increases.
- Browser administration is deliberately narrower than the maintenance CLI: it exposes bounded reads, quarantine, and the explicit quarantined-result delete-and-reset sanction. It never exposes arbitrary actor text, generic approval/restore/reconcile operations, or account-role editing.
- PHP's default file-session store is appropriate for this single shared-hosting deployment. A multi-node deployment would need shared session storage or signed/revocable session tokens.
- Configure the Google OAuth Web client with the final HTTPS origin before sign-in can be production-tested.
