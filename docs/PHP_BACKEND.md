# PHP/MySQL backend integration

This document describes the backend target on the PHP branch. It does not describe the current Vercel production deployment until the release is integrated, committed, deployed, and verified.

## Runtime and setup

- PHP 8.2 or newer with PDO MySQL, JSON, mbstring, OpenSSL, and Intl.
- MySQL 8 or a current MariaDB release with window-function support.
- Composer dependencies installed with `composer install --no-dev --optimize-autoloader`.
- Apache `mod_rewrite` enabled so the repository `.htaccess` can route extensionless `/api/*` requests to `api/index.php`.

For production, `~/.config/speedytapper/config.php` under the private hosting-account home remains the preferred location. `SPEEDYTAPPER_CONFIG_PATH` can point to another private path, and individual environment variables with the same names take precedence. The MCP-only deployment cannot write outside the target document root, so its curated artifact may instead contain the ignored `server/config.local.php`. That exception is acceptable only when the root `.htaccess` is present, direct requests to case variants of `/server/config.local.php` are verified as 403/404 with no body leakage, the archive is built from an exact commit in temporary staging, and the secret-bearing file is never committed or copied into a general source archive.

```bash
composer install
php server/bin/migrate.php
npm run check:php
```

The API automatically applies pending migrations before dispatch, serialized with a database-scoped advisory lock. The CLI uses the same runner for explicit maintenance. Migrations create a season, Google-backed internal player profiles, and one best leaderboard row per player, mode, and season. The multiplier-scoring release deletes the existing leaderboard rows once through migration `004`; profiles and coin balances remain intact. Only `SHA-256("google\\0" + sub)` is stored from the Google identity token; email claims and raw Google subject values are not stored.

## API contract

All responses are JSON with `Cache-Control: no-store`. Mutations accept same-origin JSON. Authentication uses a secure, HTTP-only, SameSite=Lax PHP session cookie. Google Identity Services supplies the `credential` ID token, which is verified server-side by `google/apiclient` against the configured Web client ID.

### `GET /api/session`

Always public. The Google client ID is intentionally public configuration.

```json
{
  "authenticated": true,
  "googleClientId": "...apps.googleusercontent.com",
  "season": { "id": "season-1", "name": "Season 1" },
  "profile": {
    "id": "internal-uuid",
    "nickname": "Player",
    "nicknameConfirmed": true,
    "createdAt": "2026-07-13T12:00:00.000Z",
    "updatedAt": "2026-07-13T12:00:00.000Z"
  },
  "ranks": {
    "normal": { "rank": 12, "totalEntries": 250, "topPercent": 5 },
    "zen": { "rank": null, "totalEntries": 180, "topPercent": null }
  }
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

Returns the top five and, when signed in and ranked, the player's row with up to two positions on each side. Duplicate rows are removed. `playerRank` and `topPercent` are null when signed out or unranked.

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
      "isCurrentPlayer": true
    }
  ],
  "totalEntries": 250,
  "playerRank": 1,
  "topPercent": 1
}
```

### `POST /api/leaderboard`

Authentication and a confirmed public nickname are required. The server supplies identity, nickname, entry ID, season, and timestamps; the body never contains a player name.

```json
{
  "mode": "normal",
  "score": 12345,
  "hits": 24,
  "dodges": 7,
  "survivalMs": 91000,
  "fastestReactionMs": 178,
  "averageReactionMs": 331,
  "speedRatings": { "godlike": 2, "perfect": 6, "great": 8, "good": 8 }
}
```

Zen submissions use `mode: "zen"` and an exact `survivalMs` of `180000`. A response has the leaderboard shape above plus `rank` and `improved`. A lower run does not overwrite the profile's existing best row.

## Security and limitations

- The session cookie and same-origin mutation guard prevent the common cross-site cases, while Google verifies account ownership.
- The Google subject is irreversibly digested before storage. Raw tokens, email claims, and passwords are never stored.
- Submitted scores are consistency-validated but gameplay remains browser-authoritative. Google login provides identity, not anti-cheat integrity.
- Authenticated score submissions are limited to ten per session per minute; this limits accidental or simple request floods but is not an anti-cheat boundary.
- PHP's default file-session store is appropriate for this single shared-hosting deployment. A multi-node deployment would need shared session storage or signed/revocable session tokens.
- Configure the Google OAuth Web client with the final HTTPS origin before sign-in can be production-tested.
