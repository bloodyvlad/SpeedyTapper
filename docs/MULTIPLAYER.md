# Multiplayer backend contract

This document defines the current PHP lobby, manifest, transcript, replay, and
settlement contract. It does not define the live peer transport or iOS
presentation behavior.

## Fixed contract

| Field | Value |
| --- | --- |
| Mode | `own_color` |
| Players | 2–4 |
| Minimum build ID | `20260729-1` under the shared numeric build policy |
| Ruleset | `multiplayer-own-color-v1` |
| Protocol version | `1` |
| Proof version | `1` |
| Board | 16 cells, numbered 0–15 |
| Starting lives | 3 per participant |
| Maximum transcript | 2,500 events |
| Maximum logical duration | 900,000 ms |
| Verification method | `peer_consistent_v1` |
| Economy | No coins or achievements |

Build IDs must have exact `YYYYMMDD-N` form with a real date and positive
sequence. Numeric values at or above `(20260729, 1)` are accepted. The build ID
is audit/manifest metadata; only the exact ruleset and protocol/proof versions
select semantics.

PHP is not on the live tap path. It owns authenticated lobby membership,
stable seats/colors, roster agreement, immutable start manifests, bounded
post-match evidence, replay, results, the Multiplayer leaderboard, and Game
Center publication intent.

## Eligibility and lobby lifecycle

Every route requires an authenticated profile. The profile must have a
confirmed public name and an enabled Game Center binding. Lobby listing does
not require a freshly refreshed proof; create, join, roster confirmation, and
start require the binding to have been verified within ten minutes.

Forming lobbies expire after ten minutes. Creation is limited to five lobbies
per player per ten minutes. Seats are contiguous from zero, color indices equal
their assigned seats, and a forming-lobby departure compacts later seats. The
creator role transfers to the first remaining seat when its holder leaves.
Leaving after start cancels an active/collecting match; leaving a completed
match never mutates its results.

### Routes

All mutation bodies are strict JSON objects and require the session CSRF
header.

| Method and path | Body and result |
| --- | --- |
| `GET /api/mobile/v1/multiplayer/lobbies?limit=1..50` | Returns open forming lobbies; default limit 20 |
| `POST /api/mobile/v1/multiplayer/matches` | `{ "mode": "own_color", "capacity": 2|3|4, "buildId": "YYYYMMDD-N" }`; returns `201 { "match": ... }` |
| `GET /api/mobile/v1/multiplayer/matches/{uuid}` | Member-only private match state |
| `POST /api/mobile/v1/multiplayer/matches/{uuid}/join` | `{}`; joins the lowest free seat, idempotently returns current membership |
| `POST /api/mobile/v1/multiplayer/matches/{uuid}/leave` | `{}`; returns `left` and `matchCancelled` |
| `PATCH /api/mobile/v1/multiplayer/matches/{uuid}/readiness` | `{ "ready": true|false }`; returns refreshed match |
| `POST /api/mobile/v1/multiplayer/matches/{uuid}/gamekit-roster` | Roster body below; returns confirmation counts |
| `POST /api/mobile/v1/multiplayer/matches/{uuid}/start` | `{}`; creator-only, returns manifest and participants |
| `POST /api/mobile/v1/multiplayer/matches/{uuid}/submissions` | Submission body below; returns collecting or settlement state |
| `GET /api/mobile/v1/multiplayer/matches/{uuid}/settlement` | Member-only current settlement |

The private match payload contains `matchId`, `state`, `mode`, `capacity`,
`selfParticipantId`, `isCreator`, ordered public `participants`, `expiresAt`,
and positive integer `playerGroup`. Active/collecting matches also include the
frozen manifest. Public participant rows contain only `participantId`, `seat`,
`colorIndex`, confirmed `name`, optional visible `petId`, `ready`, `status`, and
`isCurrentPlayer`; internal player UUIDs and raw Game Center IDs are omitted.

## Roster agreement and start

Each participant submits exactly:

```json
{
  "localGamePlayerId": "local persistent ID",
  "observedGamePlayerIds": ["every other persistent ID"],
  "coordinatorGamePlayerId": "one roster member"
}
```

The combined roster must contain 2–4 unique identifiers, exactly match the PHP
participants' verified bindings, and identify the caller's local binding. Each
participant must submit the same roster and coordinator. PHP stores hashes,
not the submitted plaintext IDs.

Only the lobby creator can start. Start requires 2–4 participants, every
participant ready and still eligible, and unanimous roster/coordinator hashes.
PHP then freezes this canonical manifest:

```json
{
  "protocolVersion": 1,
  "ruleset": "multiplayer-own-color-v1",
  "proofVersion": 1,
  "matchId": "uuid-v4",
  "buildId": "creator's accepted build ID",
  "seed": "base64url 32-byte nonce",
  "startingLives": 3,
  "participants": [
    { "participantId": "uuid-v4", "seat": 0, "colorIndex": 0 }
  ],
  "manifestHash": "unpadded base64url SHA-256"
}
```

Participants are ordered by seat. The `seed` is an opaque nonce; protocol 1
does not define a seed-derived schedule. The submission deadline is 15 minutes
plus a five-minute grace period from PHP start.

## Transcript

The submission body contains only `manifestHash` and `transcript`.
`manifestHash` is the manifest's unpadded base64url SHA-256. The transcript
contains only `matchId`, `buildId`, `ruleset`, `protocolVersion`,
`proofVersion`, and `events`; the first two must match the manifest, and the
three protocol fields are exactly `multiplayer-own-color-v1`, `1`, and `1`.

The actual `events` list must be non-empty, contain no more than 2,500 entries,
and consist only of exact integer tuples. Sequence numbers are contiguous and
one-based. Event logical times are nondecreasing and within 0–900,000 ms.

| Opcode | Exact tuple |
| ---: | --- |
| `0` | target `[0, sequence, at, ownerSeat, targetId, cell, colorIndex]` |
| `1` | hit `[1, sequence, inputAt, handledAt, seat, targetId, cell]` |
| `2` | miss `[2, sequence, inputAt, handledAt, seat, reason, cell]` |
| `3` | decoy activation `[3, sequence, at, ownerSeat, decoyId, cell, colorIndex, lifetimeMs]` |
| `4` | decoy expiry `[4, sequence, at, decoyId]` |
| `5` | player out `[5, sequence, at, seat]` |
| `6` | finish `[6, sequence, at]` |

Miss reasons are `0` empty, `1` wrong, and `2` late. Miss `cell` may be `-1`;
otherwise cells are 0–15. Input handling must not precede input and may lag by
at most 10,000 ms.

## Replay rules

- Targets use contiguous IDs and rotate fairly through living seats. Their
  color must equal the owner's manifest color, and they cannot occupy a live
  decoy cell.
- A target is scheduled 250–5,000 ms after the preceding handled input. The
  response window is 1,000 ms before 20 seconds, ramps to 750 ms over the next
  ten seconds, stays at 750 ms to 40 seconds, resets to 1,000 ms to 50 seconds,
  then falls 5 ms per owning-player challenge hit to a 200 ms floor.
- A valid hit must be by the target owner, target ID, and cell. Score is derived
  from reaction time and the owner's current multiplier.
- A miss removes one life, resets that player's multiplier, clears live decoys
  without dodge credit, and schedules the next target after the 1,500 ms
  recovery plus the normal delay. The third loss must be followed immediately
  by the matching player-out tuple.
- Decoys begin at 10 seconds, use contiguous IDs, rotate dodge ownership through
  living seats, avoid all assigned player colors and occupied cells, last
  1,000–3,000 ms, and are at least 600 ms apart. Only one may be active before
  70 seconds; afterward capacity is `min(6, 2 + floor(totalHits / 20))`.
  Natural expiry awards the owner 550 unmultiplied points.
- Finish must be the final event and is valid only after every participant is
  out. PHP derives each participant's survival time, score, reactions, ratings,
  hits, misses, dodges, maximum multiplier, and placement. Placement sorts by
  score, hits, lower average reaction, then lower seat.

## Submission and settlement

Every participant submits the same manifest hash and canonical transcript.
Each new stored submission counts toward a limit of 20 per player per hour;
an exact stored retry remains idempotent even after the limit or deadline.

Until all participants submit, the response has `state: collecting`, counts,
and `leaderboardEligible: false`. Conflicting evidence, a new late submission,
replay failure, or a timeline more than 1,000 ms ahead of PHP elapsed time moves
the match to `review`. Reusing the same ruleset/protocol/proof event trace in a
different match is quarantined. Structurally valid risk evidence is also held
for review.

A clean unanimous replay writes one immutable result per participant and sets
the match to `settled`. Settlement returns `state`, `leaderboardEligible`,
`verification`, optional `reviewReason`, and ordered result rows. Only
`settled` returns `verification: "peer_consistent_v1"` and ranks on the
Multiplayer leaderboard. The result is protocol-verified and peer-consistent;
it is not server-authoritative, proof of human input, bot-proof, or
collusion-proof.
