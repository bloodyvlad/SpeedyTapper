# Arcade v5 — 4x4-only pickups (local candidate)

Prepared 2026-09-11; not deployed by this implementation task. The last separately
verified PHP source is `0a94f5cfe2a36ae89f0d26db1c72bf7cfe4d683c` (v4), documented
in the private `SpeedyTapper-release-artifacts/20260910-arcade-v4.fgRKTb/RELEASE.md`.

## Exact compatibility

| Issued pair | Pickup eligibility |
| --- | --- |
| `reaction-proof-v3` / proof 2 | No pickups; legacy omission still selects this pair |
| `reaction-proof-v4` / proof 3 | Retained build 27 behavior: visible grid at least 2x2 |
| `reaction-proof-v5` / proof 3 | New explicit selection: visible grid at least 4x4 |

New clients request v5/proof 3 on `POST /api/runs` and require that exact returned
pair before gameplay. Finish must match the stored player/session-bound attempt.
Higher build IDs never select semantics; partial, wrong-version and cross-ruleset
requests fail. Old v3/v4 requests, outstanding attempts, rules and hashes remain
unchanged. V5 gets its own ruleset-based trace-hash namespace.

Only the minimum pickup dimension changes. Proof 3 opcodes 0–10, tuple lengths,
12–20-second opportunity cadence, 250ms blocked retry, 3-second lifetime, no-recovery
placement, single live pickup and free-cell reservation remain as in [ARCADE_V4](ARCADE_V4.md).
Before 4x4, the client continues emitting legitimate blocked-opportunity opcode 10;
it must not omit the stream until 40 seconds. A frozen active 2x2 target can span the
40-second phase boundary: eligibility follows the visible/frozen grid, not wall
time alone. The first due opportunity on an actual 4x4 board may appear immediately;
there is no extra 12–20-second reset at that boundary.

PHP chooses the threshold from the exact issued ruleset, never a client-authored
grid or capability flag. V5 rejects any 1x1/2x2 spawn. V4 still accepts 2x2 spawns
and rejects an unjustified blocked tick there. Hearts, cumulative life losses,
clock rate and immutable announced windows are unchanged. Clock artwork is
presentation only and does not alter proof semantics.

## Verification and delivery

`composer check` includes exact-pair, threshold, cadence, reservation/recovery,
clock arithmetic and complete Swift/PHP golden parity tests. Retained v4 goldens
are unchanged. `composer test:mariadb:arcade-powerups` retains v4 admission tests.
The v5 JSON fixtures are byte-identical to Swift core commit
`162edc2aef5f9d9f7ada7d54d1b1f305175fc1f0`; both complete traces match PHP replay.
`composer test:mariadb:arcade-v5` applies actual migrations 001–024 only to a fresh
disposable database, then tests real attempt admission, contract mismatch,
persisted v4/v5 goldens, eligible v3/v4/v5 rewards and idempotent retries. Synthetic
robotic goldens may correctly be held for review; eligible controls cover rewards.

No schema, catalog, StoreKit, account/economy, season or Multiplayer PHP changes
are included. Existing columns fit the new ruleset and wire version. Deployment
must precede the new iOS upload; preserve private configuration and existing
workers, with no pending migration marker. Rollback to 0a94f5c retains build 27/v4
support but cannot issue or finish v5: coordinate the new build/outstanding
attempts, and never downgrade the database or restore player data for code rollback.
