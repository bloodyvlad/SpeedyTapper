# SpeedyTapper repository instructions

These rules apply to every task in this repository. A specific user request
takes precedence.

## Start safely

1. Run `git status --short` before editing.
2. Read the relevant parts of `README.md` and `docs/DECISIONS.md`.
3. Inspect current PHP, migrations, tests, Composer metadata, and routing as the
   authority for behavior.

Codex tasks share the filesystem but not transcripts. Treat unexplained dirty
files as another owner's work.

- Never reset, discard, overwrite, stash, stage, or commit unrelated changes.
- Stop if intended edits overlap unowned work.
- Use one editing task in the shared checkout. Isolate parallel implementation
  in separate worktrees and `codex/<task>` branches.
- Keep one concrete outcome per branch and commit only intentional files.

Removed combined-repository material is recoverable from Git history and
`aug-2026-archive` at
`ab7059f91b70139a4529591878013abb1be23421`.

## Sources of truth

| Concern | Authority |
| --- | --- |
| Source and release contents | Exact Git commit |
| Current PHP behavior | `server/src/`, `api/index.php`, migrations, and tests |
| Current contract snapshot | `docs/CURRENT_VERSION.md` |
| Effective architecture/product rules | `docs/DECISIONS.md` |
| Deployed artifact and schema | Direct host, artifact, and database verification |
| External Apple/iOS state | Direct verification in the relevant external system |

Do not describe documentation, a tag, a branch, or a local database as proof of
production, TestFlight, App Store, worker, or migration state. Keep proposals
clearly unreleased until accepted, implemented, verified, and committed.

## Setup and checks

- Require PHP 8.2+ and MariaDB/MySQL. Install exact PHP dependencies with
  `composer install`.
- Start the API-only local service with `composer dev` on port 4173.
- Add or update deterministic tests whenever behavior changes.
- Run `composer check` and `git diff --check` before handoff or commit.
- Run applicable disposable MariaDB harnesses for migration or
  database-specific behavior:
  - `composer test:mariadb:game-center`
  - `composer test:mariadb:nickname`
  - `composer test:mariadb:internal-alpha-reset`
  - `composer test:mariadb:multiplayer-rewards`
- If a new shipped PHP file is added outside the existing lint roots, extend
  `server/bin/check.php` so it is covered.

## Architecture boundaries

- `api/index.php`: construction and JSON HTTP boundary only.
- `server/src/App.php`: route dispatch, request field gates, authentication,
  and response composition.
- `server/src/ClientBuild.php`: shared minimum build-ID parser/comparator.
- `server/src/RunProof.php` and `RunProofValidator.php`: exact Arcade proof
  parsing and deterministic replay.
- `server/src/Multiplayer*`: lobby/manifest/transcript, replay, settlement, and
  Multiplayer leaderboard rules.
- Identity, session, StoreKit, economy, shop, moderation, deletion, and Game
  Center services own their respective domain and transaction boundaries.
- `server/migrations/`: ordered, reviewed MariaDB/MySQL schema and data history.
- `server/bin/`: private migration, moderation, worker, reconciliation, and
  cleanup entry points.
- `test/`: deterministic coverage mirroring every retained contract.

Keep route code thin, deterministic rules independent of HTTP, server catalogs
authoritative, and multi-row value changes atomic.

## Contract invariants

- Build IDs have exact `YYYYMMDD-N` form and are accepted only when their valid
  numeric tuple is at least `20260729-1`. Higher IDs do not select semantics.
- Ranked Arcade accepts only `normal` and an exact issued contract: retained
  `reaction-proof-v3`/proof `2`, explicitly requested `reaction-proof-v4`/proof `3`,
  or the local `reaction-proof-v5`/proof `3` candidate. V4 pickups begin on 2x2;
  v5 requires 4x4. Omitted capabilities retain v3; never
  reinterpret its events or infer rules from a build ID. PHP derives every result
  through replay and consumes the player/session-bound attempt idempotently.
  Proofs contain at most 10,000 events. See `docs/ARCADE_V4.md` and `docs/ARCADE_V5.md`.
- Zen has retained leaderboard/profile/administrator reads only. Never issue a
  Zen attempt or accept a Zen proof, result, coin, or achievement write.
- Retained v1 ranked Multiplayer accepts only `own_color`, 2–4 players,
  `multiplayer-own-color-v1`, protocol `1`, proof `1`. Transcripts contain at
  most 2,500 events and 900,000 logical milliseconds. PHP settles only matching
  replayed peer evidence and receives no live taps.
- Describe clean v1 Multiplayer results as protocol-verified and
  `peer_consistent_v1`, never server-authoritative, human-verified, bot-proof,
  or collusion-proof. V1 awards no coins or achievements.
- New trusted v2 results require exact result revision `2`, gameplay revision
  `3`, and reward policy `multiplayer-alive-minute-v1`. Only completed competitive
  matches enter the fresh `server_reported_v2` board. Explicitly generation-bound
  alive/connected time awards two earned coins per cumulative multiplayer minute,
  with an independent carry. Legacy v2, tutorial and aborted results never earn;
  missing/stale generations never earn. No multiplayer achievements or Game Center
  publication. See `docs/MULTIPLAYER_V2_REWARDS.md`.
- Google and Apple are primary identities. Link a second provider only through
  explicit recent authentication. Game Center is link-only and cannot register,
  log in, merge profiles, or move account-owned data.
- Confirmed public names are NFKC-normalized Unicode of at most 20 characters,
  contain no Unicode whitespace, and are database-unique under
  `utf8mb4_unicode_ci`. Availability is advisory; save-time uniqueness is
  authoritative.
- Store only internal UUIDs, confirmed public names, provider-domain-separated
  subject digests, hashed Game Center identities, the narrowly encrypted Game
  Center publication ID, and the encrypted Apple refresh token needed for
  deletion. Never store raw subjects, identity tokens, plaintext Game Center
  IDs, provider display names, emails, or passwords.
- Mutations require the cookie session, same-origin guard, and CSRF except the
  Apple-signed notification receiver and the exact v2 internal redeem, session
  validation, and result routes, which require independent service Bearer
  authentication. Native requests may omit `Origin`.
  Logout and deletion revoke the database session mapping.
- Only eligible protocol-verified Arcade play mints Arcade progression.
  Award one Arcade coin per cumulative eligible minute and carry remainder;
  multiplayer uses its separately versioned trusted two-coin lane above. Claims
  and purchases use immutable, idempotent ledger events.
- Earned coins spend before purchased lots; purchased lots spend FIFO with
  exact allocation provenance. Refunds and reversals are idempotent. Future
  credits settle debt first.
- The server catalog owns prices, grants, balances, ownership, selections, and
  entitlements. Never trust client claims for these values. Achievement claims
  use `{ "id": "..." }`.
- An earned-progression reset preserves purchased value, StoreKit/refund
  evidence, paid entitlements, refund debt, and every paid or mixed-funded
  cosmetic.
- Account deletion requires exact confirmation and recent primary
  authentication, revokes Apple authorization when linked, deletes live account
  state and sessions, and retains only detached pseudonymized Apple settlement
  evidence required for duplicate prevention and later refund/reversal work.
- Moderation targets exact immutable result UUIDs, records actor/reason, and
  quarantines before logical deletion. Existing `legacy` rows cannot be
  retrospectively verified.
- Game Center publication mirrors current authority through a revisioned,
  leased, retryable, lane-specific outbox. Publication failure never rolls back
  backend state.

## Git and delivery

- Prefer a reviewed pull request when a remote is configured. Otherwise use
  reviewed local branches and commits; never claim an unavailable push or PR.
- `main` must remain a tested, deployable commit, not an integration scratchpad.
- Do not merge, release, or deploy another task's uncommitted work.

## Production deployment

Production deployment requires explicit user authorization.

1. Review the exact intended diff; run `composer check` and
   `git diff --check`.
2. Commit the exact release contents and confirm the deployment source tree is
   clean.
3. Build a root-flat allowlisted runtime artifact from `git archive` of that
   commit. Do not package the checkout, tests, docs, local files, or secrets.
4. Install locked production Composer dependencies in staging and inject
   runtime configuration only there. Prefer
   `~/.config/speedytapper/config.php`; keep any staged fallback untracked and
   prove direct private-path requests are denied.
5. Use the untracked `server/.maintenance` artifact marker when a separately
   controlled destructive operation must fence all API work.
6. Add `server/.migrations-pending` only to the artifact when ordinary pending
   migrations should run on first request. Verify it is consumed. Migration
   `020` requires separate destructive-operation authorization, backup,
   maintenance fencing, and paused publication/reconciliation workers before
   first application to data.
7. Deploy only to the explicitly authorized independent website and exact
   document root. Never infer a target from a parent site or old notes.
8. Smoke-test HTTPS API JSON, sessions/CSRF, identity, Arcade and Zen reads,
   StoreKit, Game Center, Multiplayer, administration, non-API `404`s, and
   denial of `server`, `vendor`, Git metadata, configuration, certificate, and
   key paths.
9. Record commit SHA, artifact SHA-256, host/path, migration/season state, and a
   tested rollback artifact.

Never deploy a dirty shared checkout.

## Handoff

Report:

- outcome and intentionally changed files;
- tests, checks, and MariaDB/live checks actually performed;
- unavailable verification and remaining risks;
- branch and commit SHA, when created;
- deployment target, artifact, migration/season, and rollback only when a
  deployment was explicitly authorized and performed;
- unrelated dirty files preserved.

Verification and an evidence-backed handoff are part of completion.
