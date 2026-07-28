# iOS task — finish PimPoPom Game Center linking and server mirroring

## Outcome

Connect the existing native `GKLocalPlayer` authentication to the currently
authenticated PimPoPom PHP profile. The iOS app automatically supplies a fresh
GameKit identity proof with `publish: true`; PHP makes that active pair belong
to the current profile and remains the only component that decides leaderboard
scores and achievement unlocks. Do **not** call
`GKLeaderboard.submitScore` or `GKAchievement.report` from the app.

This task changes the separate iOS project only. The PHP contract described
below is implemented in the SpeedyTapper backend branch that contains migration
`019_game_center_server_publication.sql`.

## Trust and product rules

- A Google- or Apple-authenticated PimPoPom profile must already exist.
- Game Center is secondary and link-only. It cannot register, log in, recover,
  merge, or move a wallet.
- The GameKit player must be authenticated and report persistent scoped IDs
  before linking.
- PHP verifies Apple's signature over `teamPlayerID`, bundle ID, timestamp, and
  salt. Apple does not include the ordinary app's `gamePlayerID` in that signed
  tuple. The accompanying `gamePlayerID` is therefore client-asserted, then
  freshly encrypted by PHP for the current profile/team association.
- A valid current session wins a stale Game Center association. This does not
  merge profiles or move wallets, purchases, results, achievements, or cosmetics.
- Never send score, percentage, leaderboard ID, achievement ID, bundle ID, or
  `preReleased`; PHP derives/allowlists all of them.
- Server publication is eventually consistent. An accepted game result must
  not wait for Apple.

## Existing native foundation to reuse

The current `GameCenterService` already authenticates `GKLocalPlayer` and can
obtain:

- `teamPlayerID`
- `gamePlayerID`
- public-key URL
- signature bytes
- salt bytes
- timestamp

Keep that service and add a PHP-link coordinator/state model around it. Reuse
the app's existing authenticated PHP cookie/session and
`X-SpeedyTapper-CSRF` request pipeline used by profile/StoreKit calls.

## API flow

Perform this automatically after both the PimPoPom PHP session and Game Center
are authenticated. These two Game Center endpoints do not require another
recent Google/Apple reauthentication; all other sensitive reauthentication
rules remain unchanged.

1. Confirm `GKLocalPlayer.local.isAuthenticated`.
2. Confirm GameKit says scoped IDs are persistent. If not, show a nonfatal
   unavailable state and do not send identifiers.
3. Read `GET /api/session`.
4. Request a fresh challenge on initial coordination and whenever either scoped
   ID changes. Repeating the same pair is safe and reconciles missing work.
5. Send:

   ```http
   POST /api/profile/game-center/challenge
   Content-Type: application/json
   X-SpeedyTapper-CSRF: <session csrfToken>

   {}
   ```

   The challenge response is `201 Created`, not `200 OK`.

   Response:

   ```json
   {
     "gameCenter": {
       "challengeId": "<one-time value>",
       "expiresAt": "2026-07-25T12:00:00Z"
     }
   }
   ```

6. **After receiving the challenge**, call GameKit's identity-verification
   signature API so its timestamp cannot predate the challenge. Base64-encode
   signature and salt with standard Base64.
7. Submit exactly:

   ```http
   POST /api/profile/game-center
   Content-Type: application/json
   X-SpeedyTapper-CSRF: <session csrfToken>

   {
     "challengeId": "<one-time value>",
     "teamPlayerId": "<GKLocalPlayer.teamPlayerID>",
     "gamePlayerId": "<GKLocalPlayer.gamePlayerID>",
     "publish": true,
     "publicKeyUrl": "https://static.gc.apple.com/...",
     "signature": "<base64>",
     "salt": "<base64>",
     "timestamp": 1234567890123
   }
   ```

8. Treat HTTP 200 as linked. Persist no server truth in UserDefaults; refresh
   `GET /api/session` after app/account changes.

Link response:

```json
{
  "identityBindings": {
    "google": true,
    "apple": true,
    "gameCenter": true
  },
  "gameCenter": {
    "serverPublicationAvailable": true,
    "preReleased": true,
    "identityLinked": true,
    "publicationEnabled": true,
    "mirrorReady": true,
    "pendingJobs": 6,
    "heldJobs": 0,
    "needsReset": false,
    "newlyLinked": true,
    "gamePlayerIdNewlyBound": true,
    "reassigned": false
  }
}
```

`pendingJobs` means PHP accepted desired state and Apple delivery is pending; it
is not a reason to retry the link. The Hostinger worker owns retries.
`mirrorReady` means server credentials and publication consent are ready; it
does not mean every queued Apple write has already completed.

Decode `gameCenter.preReleased` as `Bool?`: it is `null` when the backend has
not selected a publication lane. Treat all response objects as additive so the
existing app remains compatible when PHP adds nonbreaking status fields.

## UI state model

Expose these states separately:

1. `gameCenterSignedOut` — GameKit is not authenticated.
2. `primaryProfileRequired` — GameKit is authenticated but PimPoPom is not.
3. `scopedIdsTransient` — GameKit authenticated, but persistent scoped IDs are
   unavailable.
4. `unlinked` — `identityBindings.gameCenter == false`.
5. `linkedIdentityOnly` — identity linked, publication disabled/unavailable.
6. `publicationQueued` — enabled with `pendingJobs > 0`.
7. `mirrorReady` — enabled and server credential available.
8. `publicationHeld` — `heldJobs > 0`; PHP progress is safe, but an operator
   must correct the Apple configuration/catalog problem and explicitly requeue.
9. `resetNeedsSupport` — `needsReset == true`; keep gameplay available and
    present a support/diagnostic message rather than claiming Apple is synced.

Do not describe `serverPublicationAvailable == false` as the player being
unlinked. It means backend credentials/worker are not currently ready.

An optional player-facing “Turn off Game Center publishing” action may send:

```http
DELETE /api/profile/game-center/publication
Content-Type: application/json
X-SpeedyTapper-CSRF: <session csrfToken>

{ "confirm": true }
```

This retains the verified identity link and cancels unsent publication. A fresh
challenge with the same scoped player ID re-enables it.

## Apple identifiers configured by the backend

The iOS app may use these only for Game Center dashboard presentation. It must
not submit them as PHP authority:

- Leaderboard:
  `com.otcsoftware.pimpopom.arcade.verified`
- Achievements:
  - `com.otcsoftware.pimpopom.achievement.complete_arcade`
  - `com.otcsoftware.pimpopom.achievement.godlike_speed`
  - `com.otcsoftware.pimpopom.achievement.collect_5_coins`
  - `com.otcsoftware.pimpopom.achievement.score_over_100k`
  - `com.otcsoftware.pimpopom.achievement.buy_a_pet`

PHP publishes the all-time best verified Arcade score. Zen, legacy, review,
quarantined, and deleted results never publish. Achievements publish at 100
percent when unlocked, regardless of coin-reward claim state.

## Error handling

- `400`: malformed/unsupported field or missing `publish: true`. Reject
  transient scoped IDs locally before sending. Fix the client payload; do not loop.
- `401`: expired/consumed challenge, signed proof failure, or signed-out profile.
  Fetch a new challenge after restoring the authenticated PHP session.
- `409`: assertion replay. Obtain a new challenge and fresh proof; never create
  or merge a profile automatically.
- `503`: Game Center trust, encrypted storage, or publication configuration is
  unavailable. Keep gameplay enabled and allow a later manual retry.

Never log the raw `teamPlayerID`, `gamePlayerID`, signature, salt, session
cookie, CSRF token, or complete server error body.

## Acceptance tests

1. A Google-primary profile links Game Center and receives `mirrorReady`.
2. An Apple-primary profile links the same way.
3. Game Center authentication alone cannot create/login to PimPoPom.
4. The request is refused when scoped IDs are not persistent.
5. A new challenge is fetched for every signature/link attempt.
6. Repeating a fresh proof for the same IDs is idempotent.
7. A pair previously linked to another internal profile is reassigned to the
   current profile while moving no wallet/profile state.
8. Existing verified Arcade best and unlocked achievements backfill after the
   first link.
9. A later verified personal best appears in the TestFlight Game Center
   leaderboard after the worker runs.
10. `buy_a_pet` appears after a successful server purchase, not after merely
    tapping Buy.
11. Claiming achievement coins neither creates nor suppresses Game Center
    progress.
12. Zen creates no Game Center state.
13. Offline/Apple outage does not lose the PHP result; delivery occurs later.
14. Turning publication off cancels unsent work but does not log out either
    primary identity or Game Center.
15. The client-asserted `gamePlayerID` limitation remains explicitly visible
    in security documentation; App Attest hardening is deferred and a conflict
    never triggers an automatic merge or account move.
16. Account deletion removes the PimPoPom binding; without an authenticated PHP
    session the native Game Center player cannot recreate it.

Run the live-link and dashboard checks on a physical TestFlight device. The PHP
test suite verifies its own signature/outbox/client behavior but cannot validate
GameKit account UI, persistent scoped IDs, or Apple's visible propagation time.
