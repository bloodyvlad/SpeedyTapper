# Arcade v4 power-ups — local source candidate

Implemented for local integration on 2026-09-10. Not deployed or submitted to
TestFlight. The last separately verified PHP runtime remains source
`9fe555d179326cecd5e23f0a6a16788b4af0ba34`; it cannot accept v4. Never submit altered
gameplay as v3 or silently disable requested power-ups to obtain a ranked ticket.

## Explicit compatibility

`POST /api/runs` retains `{ "mode":"normal", "buildId":"YYYYMMDD-N" }` as v3/proof
2. New clients add exactly `"ruleset":"reaction-proof-v4", "proofVersion":3`.
Both fields must be present together with correct types and a supported pair.
The existing player/session-bound attempt records and echoes that pair; finish
must match it. Higher build IDs never choose semantics. V3 parser, replay,
three-loss finish and existing trace hashes remain unchanged.

V4 keeps integer opcodes 0–6 and their tuple shapes. It adds:

| Opcode | Tuple | Meaning |
| ---: | --- | --- |
| 7 | `[7, at, id, kind, cell, 3000]` | Pickup appears; kind 0 heart, 1 clock |
| 8 | `[8, inputAt, handledAt, id, cell]` | Original-contact pickup; its effect is derived |
| 9 | `[9, at, id]` | The one live pickup expires without score or a dodge |
| 10 | `[10, at]` | Placement opportunity blocked by deterministic board state |

All timestamps remain raw run-relative wall milliseconds. Keep the existing
10,000-event, 24-hour duration, handler-lag and bounded scheduler-lag limits. V4
trace hashing has a distinct ruleset/version namespace and retains meaningful
pickup kinds/times/cells; canonical proof hashes still bind the exact request.

## Pickup state

- One combined random-kind stream; first opportunity and each successful issuance
  sample a 12–20 second interval. Blocked opportunities retry after exactly 250ms.
- At most one live pickup, monotonically increasing IDs, fixed 3000ms lifetime.
  Grid must be at least 2x2, with at least two cells free before placement. Exclude
  target/decoy cells and reserve one cell for a target. Targets/decoys cannot reuse
  the pickup cell; decoy capacity accounts for that reservation.
- No placement or claim during the 1500ms life-loss recovery. A miss clears the
  pickup and resamples its next opportunity after recovery plus 12–20 seconds.
  A hit retains it; reset/game-over clears it.
- A claim must identify the one live pickup and its cell, with original contact
  in `[appearedAt, expiresAt)`. It cannot revive an eliminated player or resolve
  twice. An event 9 already committed cannot be undone by a later contact.
- A contact on the active pickup cell at or after its appearance cannot be encoded
  as an empty/wrong/late miss, including while expiry is pending. A target timeout
  with cell `-1` and original contacts before pickup appearance remain valid.
- The client may hide an expired pickup before committing event 9 while draining
  queued contacts. The cell remains reserved in that bounded interval; unrelated
  events may proceed within the existing 5-second scheduler ceiling. Expiry itself
  grants no life, score, streak, dodge or multiplier.
- A heart computes `lives = min(3, lives + 1)` without reducing `misses`. A heart at
  full health is consumed without benefit. Finish requires zero derived lives at
  the final miss, with `misses = 3 + actual restored lives`, not exactly three.

## Clock timing

A valid clock claim sets its anchor to `handledAt`. The rate is immediately 0.70,
then recovers linearly to 1.0 over ten seconds; a second clock resets that one
anchor rather than multiplying effects. Use exact integer arithmetic:

```text
elapsed = clamp(sampledAt - clockHandledAt, 0, 10000)
rateUnits = 70000 + 3 * elapsed       // normal rate is 100000
interval = ceil(baseInterval * 100000 / rateUnits)
```

Apply the rate when sampling a new target/decoy quiet interval and when activating
a new target's response window. Existing response windows, pending sampled
intervals and live decoy lifetimes remain immutable. Sample quiet intervals at
the scheduling time, not at a future recovery deadline. Base Arcade phases and
hit progression continue on ordinary elapsed time; score uses the frozen effective
response window, while reaction ratings use unscaled contact latency.

Recovery, pickup cadence/lifetime and no-phase retry timers are unscaled. Clock
recovery continues through a nonterminal life-loss pause. There is no client rate,
duration, lives or score field to trust.

## Persistence, verification and release boundaries

No schema migration is needed: existing ruleset/version columns fit v4/proof 3,
and `completed_runs.miss_count` is already `INT UNSIGNED`, without a three-miss
database constraint. Migration 024 is specific to multiplayer aggregates, not
Arcade. Existing rows and v3 proofs are not rewritten.

Wallet/achievement policy is unchanged: PHP derives results, records immutable
proofs and consumes attempts idempotently. Eligible credited time remains
`min(raw survivalMs, serverElapsedMs)`, not stretched clock time. Pickups mint no
separate reward. Existing leaderboards and Game Center select by mode/season, not
ruleset: a future v4 deployment therefore permits power-up scores in the same
Arcade competition unless a separately approved policy changes that. Record this
comparability choice before any release; do not reset existing results.

`composer check` includes deterministic parser, attempt-binding, pickup, tempo and
boundary tests. `composer test:mariadb:arcade-powerups` verifies exact legacy/v4
attempt persistence against the unchanged migration 006 table in an isolated
MariaDB container. The complete Swift-generated traces in
`test/fixtures/arcade-v4-powerups.json` originate from PimPoPomCore commit
`2982e6d7cec0fc7884c30b19f90fcb3af5de78c2`; both agree exactly with PHP, including an
eight-miss restored-life run and clock sampling/explicit expiry. Physical
contact/timing validation and coordinated
PHP/iOS deployment remain separate, unperformed release gates.
