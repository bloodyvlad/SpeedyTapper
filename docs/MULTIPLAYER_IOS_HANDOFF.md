# Multiplayer iOS handoff

> **Status (2026-07-29): backend release `20260729-2`.**
> The PHP/MySQL API is part of the Hostinger release. The iOS/GameKit UI and
> transport are not implemented here, and the App Store Connect multiplayer
> leaderboard has not been created. Installed iOS remains an explicitly
> supported multiplayer client build at `20260729-1`.

## Ownership boundary

- PHP owns authenticated lobby membership, stable seats/colors, the immutable
  start manifest, final transcript replay, settlement, and leaderboard rows.
- `GKMatch` owns reaction-critical peer-to-peer traffic. PHP is not a socket
  relay and must not be called for each tap or HUD change.
- Every participant submits the same final manifest hash and transcript. PHP
  derives every score, multiplier, rating, dodge, placement, and leaderboard
  field rather than trusting client aggregates.
- Multiplayer is own-color only, supports 2–4 players, awards no coins, and
  unlocks no achievements.

All private routes use the existing secure PHP cookie session. Every mutation
also requires the same-origin request rules and
`X-SpeedyTapper-CSRF: <csrfToken from GET /api/session>`. JSON errors have the
shape `{ "error": "..." }`.

## Required player state

Before presenting Multiplayer as available, require all of:

1. Google or Apple login to an existing internal PimPoPom UUID.
2. A confirmed, whitespace-free public nickname.
3. A linked Game Center identity with publication enabled.
4. A Game Center proof refreshed within the last 10 minutes before creating,
   joining, confirming a GameKit roster, or starting a match.

`GET /lobbies` checks the first three conditions but does not require freshness.
Start rechecks every participant. A stale connection returns `409` with
`Refresh the Game Center connection before multiplayer.`

## PHP routes

The base path is `/api/mobile/v1/multiplayer`.

| Method and path | Request | Success |
| --- | --- | --- |
| `GET /leaderboard` | none | Public top five; a signed-in session also adds the player's best result context |
| `GET /lobbies?limit=20` | `limit` is 1–50 | `{ "lobbies": [...] }` |
| `POST /matches` | `{ "mode": "own_color", "capacity": 2, "buildId": "20260729-1" }` | `201 { "match": ... }` |
| `GET /matches/{matchId}` | none | `{ "match": ... }`; non-members receive `404` |
| `POST /matches/{matchId}/join` | `{}` | `{ "match": ... }` |
| `POST /matches/{matchId}/leave` | `{}` | `{ "left": true, "matchCancelled": false }` |
| `PATCH /matches/{matchId}/readiness` | `{ "ready": true }` | `{ "match": ... }` |
| `POST /matches/{matchId}/gamekit-roster` | roster body below | confirmation counts |
| `POST /matches/{matchId}/start` | `{}` | immutable manifest plus participants |
| `POST /matches/{matchId}/submissions` | manifest hash plus transcript | collecting or settlement payload |
| `GET /matches/{matchId}/settlement` | none | current settlement payload |

Unknown request fields are rejected. A lobby lives for 10 minutes. Only the
creator can start it. A forming creator who leaves transfers ownership to the
first remaining seat; any participant leaving after start cancels the match.

A public lobby item is:

```json
{
  "matchId": "UUID",
  "mode": "own_color",
  "capacity": 4,
  "playerCount": 2,
  "host": { "name": "Player9551", "petId": "foka" },
  "createdAt": "2026-07-29T12:00:00.000Z",
  "expiresAt": "2026-07-29T12:10:00.000Z"
}
```

A private match returned by create, join, show, or readiness is:

```json
{
  "match": {
    "matchId": "UUID",
    "state": "forming",
    "mode": "own_color",
    "capacity": 4,
    "selfParticipantId": "UUID",
    "isCreator": true,
    "playerGroup": 123456789,
    "participants": [
      {
        "participantId": "UUID",
        "seat": 0,
        "colorIndex": 0,
        "name": "Player9551",
        "petId": "foka",
        "ready": false,
        "status": "joined",
        "isCurrentPlayer": true
      }
    ],
    "expiresAt": "2026-07-29T12:10:00.000Z"
  }
}
```

The public lobby list intentionally omits `playerGroup`; a participant receives
it only after create/join/show.

## PHP lobby to GameKit

1. Create or join the PHP lobby.
2. Put the returned positive 31-bit `playerGroup` into
   `GKMatchRequest.playerGroup`. PHP does not create or carry a `GKMatch`.
3. Use GameKit to connect exactly the PHP lobby's 2–4 participants.
4. Elect one coordinator identically on every device. A deterministic rule such
   as the lexicographically smallest persistent `gamePlayerID` is recommended.
5. After `GKMatch` exposes the complete live roster, every participant posts:

```json
{
  "localGamePlayerId": "G:local",
  "observedGamePlayerIds": ["G:remote-1", "G:remote-2"],
  "coordinatorGamePlayerId": "G:local"
}
```

`observedGamePlayerIds` excludes the local player. The combined list must contain
2–4 unique IDs, and all devices must submit the same roster and coordinator.
PHP verifies the local ID against that profile's linked Game Center binding and
the complete set against PHP lobby membership. It stores hashes, not these raw
IDs. Success is:

```json
{ "confirmed": true, "confirmedCount": 3, "participantCount": 3 }
```

Only after every participant is ready and has confirmed the identical live
roster may the creator call `/start`.

## Start manifest

`POST /start` returns:

```json
{
  "manifest": {
    "protocolVersion": 1,
    "ruleset": "multiplayer-own-color-v1",
    "proofVersion": 1,
    "matchId": "UUID",
    "buildId": "20260729-1",
    "seed": "unpadded-base64url-32-bytes",
    "startingLives": 3,
    "participants": [
      { "participantId": "UUID", "seat": 0, "colorIndex": 0 },
      { "participantId": "UUID", "seat": 1, "colorIndex": 1 }
    ],
    "manifestHash": "unpadded-base64url-sha256"
  },
  "participants": []
}
```

Preserve this tuple exactly. In protocol v1 the seed is an unpredictable
server nonce that binds this manifest; it is **not** a specified PRNG seed and
PHP does not infer the event schedule from it. The coordinator chooses events
within the validated timing, rotation, color, cell, and capacity bounds and
reliably replicates that one ordered stream to every peer. Never substitute
nicknames, pets, internal player UUIDs, or raw Game Center IDs into the
transcript.

## P2P live responsibilities

The iOS coordinator sequences gameplay events and sends them reliably through
`GKMatch`; every peer applies the same ordered events and builds the same compact
transcript. Version the live packet envelope and include match ID, monotonically
increasing packet sequence, event sequence, and logical match milliseconds.

P2P packets—not PHP requests—must carry enough state for every device to:

- render target and decoy activation/expiry;
- apply taps, misses, life loss, elimination, and finish;
- show every player's live points and multiplier in the HUD;
- show each participant's nickname, assigned color, and pet;
- move the crown whenever the deterministic current leader changes;
- play the next tone for every accepted tap, including another player's tap, so
  all devices maintain the same note sequence;
- preserve/recover the complete ordered transcript for final submission.

Do not accept a peer's reported aggregate score as authority. Derive the live
HUD from the same event stream PHP later replays. Define explicit snapshot and
acknowledgement packets for reconnect recovery; that protocol is not supplied
by PHP.

## Transcript tuple contract

Submit exactly:

```json
{
  "manifestHash": "unpadded-base64url-sha256",
  "transcript": {
    "matchId": "UUID",
    "buildId": "20260729-1",
    "ruleset": "multiplayer-own-color-v1",
    "protocolVersion": 1,
    "proofVersion": 1,
    "events": []
  }
}
```

Every event member is an integer, `seq` starts at 1 and is contiguous, and
logical time is nondecreasing:

| Event | Tuple |
| --- | --- |
| Target | `[0, seq, at, ownerSeat, targetId, cell, colorIndex]` |
| Hit | `[1, seq, inputAt, handledAt, seat, targetId, cell]` |
| Miss | `[2, seq, inputAt, handledAt, seat, reason, cell]` |
| Decoy activate | `[3, seq, at, ownerSeat, decoyId, cell, colorIndex, lifetimeMs]` |
| Decoy expire | `[4, seq, at, decoyId]` |
| Player out | `[5, seq, at, seat]` |
| Finish | `[6, seq, at]` |

Miss reasons are `0` empty, `1` wrong, and `2` late. A late timeout may use
`cell = -1`; board cells are otherwise `0...15`. The transcript is capped at
2,500 events and 15 minutes.

The replay enforces:

- fair target and dodge-owner rotation across living seats;
- 250–5,000 ms target scheduling intervals;
- one assigned target color per player and no decoy using any assigned color;
- decoys beginning at 10 seconds, lasting 1–3 seconds, and separated by at
  least 600 ms;
- one simultaneous decoy before 70 seconds, then up to
  `min(6, 2 + floor(totalHits / 20))`;
- correct hits preserve live decoys; a mistake clears them without dodge credit;
- three lives, 1.5-second recovery after a miss, and finish only after all
  players are out;
- 1,000 ms response windows before 20 seconds, 1,000→750 ms from 20–30
  seconds, 750 ms from 30–40, a 1,000 ms reset from 40–50, then a 5 ms
  reduction per owning-player hit to a 200 ms floor;
- Godlike `<250 ms`, Perfect `<350 ms`, Great `<450 ms`, otherwise Good;
  Godlike advances two streak steps, Perfect one, five steps raise the
  multiplier, and a miss resets it.

The live score shown by iOS must use the same integer derivation as PHP:

```text
remaining = clamp(1 - reactionMs / responseWindowMs, 0, 1)
base = round(100 + 900 × remaining²)
tap award = base × multiplierBeforeThisTap
```

The tap's rating advances the meter only for subsequent taps. Godlike adds two
steps, Perfect one, and Great/Good add none while preserving the existing
meter. Every five steps raises the multiplier by one, overflow carries into the
next tier, and the maximum is 5×. A miss resets both multiplier and progress.
A natural decoy expiry awards exactly 550 points to its rotating owner, never
multiplied. No coin or full-round bonus is added.

Placement is score descending, then hits descending, average reaction ascending,
then seat ascending.

## Submission and settlement

Every participant submits the exact same manifest hash and transcript. Until all
have submitted:

```json
{
  "duplicate": false,
  "state": "collecting",
  "submittedCount": 1,
  "participantCount": 3,
  "leaderboardEligible": false
}
```

Poll `GET /settlement` or retry the exact stored submission idempotently,
including after the deadline. A new or conflicting submission after the
deadline cannot settle normally. A clean final settlement is:

```json
{
  "state": "settled",
  "leaderboardEligible": true,
  "verification": "peer_consistent_v1",
  "reviewReason": null,
  "results": [
    {
      "resultId": "UUID",
      "participantId": "UUID",
      "place": 1,
      "playerCount": 3,
      "name": "Player9551",
      "petId": "foka",
      "score": 42000,
      "survivalMs": 95000,
      "hits": 31,
      "misses": 3,
      "dodges": 8,
      "fastestReactionMs": 173,
      "averageReactionMs": 328,
      "maxMultiplier": 3,
      "speedRatings": {
        "godlike": 2,
        "perfect": 6,
        "great": 12,
        "good": 11
      },
      "isCurrentPlayer": true
    }
  ]
}
```

The match-end screen should rank every participant, crown place 1, retain pets
and names, and distinguish `collecting`, `settled`, and `review`. A reviewed
match is not leaderboard-eligible.

## Multiplayer leaderboard

`GET /api/mobile/v1/multiplayer/leaderboard` uses the normal leaderboard window:
signed-out clients receive the top five; authenticated clients also receive
their best result and up to two neighboring ranks.

```json
{
  "season": { "id": "season-1", "name": "Season 1" },
  "mode": "multiplayer",
  "entries": [],
  "totalEntries": 0,
  "playerRank": null,
  "topPercent": null
}
```

Each verified participant result is an immutable ranked row; the board is not
deduplicated per player. Ordering is score, match placement, duration, hits,
achieved time, then result ID. `GET /api/session` also adds
`ranks.multiplayer`.

## iOS UI tasks

- Add Multiplayer below Zen, disabled until login, confirmed nickname, and
  Game Center connection are available.
- Add available-games list, create capacity selection, join, and refresh.
- Build a waiting room showing readiness and draggable pets. Pet tap/drag
  animation remains local presentation and is not part of the proof.
- Bridge the PHP `playerGroup` to `GKMatchRequest`.
- Implement unanimous roster/coordinator confirmation and creator-only start.
- Implement deterministic coordinator election, coordinator-authored live
  scheduling within the PHP bounds, reliable P2P event/snapshot protocol,
  transcript replication, reconnect/forfeit behavior, and result submission.
- Add the live player strip below the speed bar. Width is 50%, 33%, or 25% for
  2, 3, or 4 players; show pet, points, multiplier, name, and leader crown.
- Add the dedicated match-end placement screen and Multiplayer leaderboard UI.

## App Store Connect task

Create a score leaderboard with the exact vendor identifier:

`com.otcsoftware.pimpopom.multiplayer.verified`

The backend contains a separate allowlisted publication lane that queues each
participant's verified personal-best multiplayer score.
Creating/configuring the App Store Connect component, localizations, review
association, and iOS Game Center presentation remain external tasks.

## Trust and current limitations

Matching submissions stop one lone coordinator from silently rewriting a match,
but colluding or modified clients can still manufacture a structurally valid,
matching transcript. Describe accepted rows as **protocol-verified,
peer-consistent**, never human-verified or bot-proof.

The server-wide trace claim prevents copying the same retained gameplay stream
into a fresh match. The product's account-deletion contract erases ordinary
gameplay proofs and trace claims rather than retaining pseudonymous gameplay
history; clone detection therefore cannot recognize a trace whose original
claim was erased by an account deletion. The manifest seed is only a unique
server nonce in protocol v1, not a server-replayed random schedule.

Current backend limitations:

- no PHP websocket, live score/multiplier endpoint, or relay;
- no implemented iOS/GameKit transport, lobby UI, reconnect, forfeit, or
  post-start host-migration protocol;
- a new submission after the stored deadline moves the match to review, but one
  absent submission can leave a match collecting until another participant
  submits or a future bounded cleanup task processes it; an exact already
  stored retry remains a no-op;
- the coordinator is validated but is not returned in later match payloads;
- multiplayer review/quarantine has no browser moderation UI;
- App Store Connect and iOS work are not implemented by this backend change.
