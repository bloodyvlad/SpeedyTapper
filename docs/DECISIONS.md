# Effective backend decisions

This file contains only decisions that govern the current PHP/MariaDB source.
Replace a decision when it changes; do not append supersession chronology.
Removed combined-repository material is recoverable from Git history and the
`aug-2026-archive` branch at
`ab7059f91b70139a4529591878013abb1be23421`.

## D-001 — Treat source and deployed state as separate facts

Git commits define source and release contents. Hostinger artifacts, live
database migration state, background workers, TestFlight, App Store, and Apple
service state must each be verified directly. Documentation, tags, branch
names, and local databases are not deployment proof.

The maintained repository boundary is the PHP API, Composer metadata,
MariaDB/MySQL migrations, operator tooling, tests, public trust certificates,
and current backend documentation.

## D-002 — Use one minimum-build policy and exact protocols

Arcade and Multiplayer accept only exact `YYYYMMDD-N` build IDs whose valid
numeric `(date, sequence)` tuple is at least `(20260729, 1)`. Build IDs bind and
audit attempts/manifests; they do not choose rules.

Every accepted Arcade build uses `reaction-proof-v3`, proof `2`. Every ranked
Multiplayer build uses `multiplayer-own-color-v1`, protocol `1`, proof `1`.
Never infer new semantics from a higher build. Any event shape, replay rule,
ruleset, protocol, or proof change requires an explicit coordinated contract
release.

## D-003 — Derive ranked Arcade results on PHP

Ranked Arcade requires a Google- or Apple-authenticated internal profile with a
confirmed public name. PHP issues one player/session-bound run UUID, stores the
accepted build and exact proof contract, replays the chronological
color-bearing proof, derives all result fields, bounds it against server time,
and consumes it idempotently.

Every accepted result is immutable. Exact retries return the stored outcome.
Cloned traces and high-risk evidence are withheld. Call accepted results
protocol-verified, never human-verified or bot-proof.

Only mode `normal` can start or finish a run. Retained Zen rows remain readable
through leaderboard, profile, and administrator views; Zen has no backend write
path.

## D-004 — Settle Multiplayer from unanimous peer evidence

Ranked Multiplayer is 2–4 player own-color play. PHP owns authenticated lobbies,
seats/colors, roster agreement, immutable manifests, transcript replay,
settlement, ranking, and publication intent. PHP receives no live tap traffic.

Every participant submits the same bounded seat-only transcript. PHP ranks only
matching, timely, low-risk evidence that replays under the exact manifest.
Describe clean results as protocol-verified and `peer_consistent_v1`, not
server-authoritative, human-verified, bot-proof, or collusion-proof.
Multiplayer never awards coins or achievements.

## D-005 — Keep one internal profile authority

The internal UUID owns public profile state, sessions, wallet, StoreKit binding,
scores, achievements, cosmetics, entitlements, and publication state. Google
and Apple are primary authentication providers. Link a second provider only
from the intended recently authenticated profile; never merge on email, relay
email, nickname, device, Game Center, purchases, or score history.

Game Center is a verified link-only secondary identity. Current-profile-wins
assignment may move only the submitted team/game binding and publication
destination. It never moves profile-owned value. Store provider-domain-separated
subject digests and Game Center hashes, not raw identifiers, tokens, provider
names, emails, or passwords. The only reversible identity material is the
narrowly encrypted Apple refresh token required for deletion-time revocation
and the encrypted Game Center game ID required for server publication.

## D-006 — Make public names database-authoritative

Confirmed public names are NFKC-normalized Unicode of at most 20 characters,
contain no Unicode whitespace, and are unique under the database's
`utf8mb4_unicode_ci` comparison. Availability is advisory. Save-time conflict
handling and the unique database index are authoritative. Never import a
provider display name.

## D-007 — Use opaque revocable cookie sessions

The current iOS client uses the secure HTTP-only SameSite=Lax PHP cookie and a
per-session CSRF token. Native requests may omit `Origin`; explicit cross-site
signals are rejected. Login rotates session, authentication, CSRF, and run
binding material. MariaDB stores only the digest of the opaque authentication
ID. Logout and account deletion revoke mappings so surviving session files
fail closed.

Sensitive identity, publication, deletion, and administrator changes require a
Google or Apple authentication within the configured recent-authentication
window. Apple and Game Center challenges are session-bound, single-use, and
short-lived.

## D-008 — Keep economy and paid value server-owned

Only eligible protocol-verified Arcade runs mint gameplay time and unlock
gameplay achievements. Award one earned coin per cumulative verified Arcade
minute and carry the sub-minute remainder. Achievement claims are idempotent
positive ledger events; pet/theme purchases are atomic negative events.

Spend earned coins before purchased lots and purchased lots FIFO, retaining
exact allocation provenance. Apple-signed product, environment, bundle,
transaction, ownership, quantity, and account binding determine StoreKit
grants. Never trust client price, balance, ownership, entitlement, quantity, or
grant claims.

Refunds revoke only affected value and funded cosmetics; reversals restore the
exact prior state. Future credits settle earned or refund debt before becoming
spendable. An earned-progression reset preserves purchased value, StoreKit and
refund evidence, paid entitlements, refund debt, and every paid or mixed-funded
cosmetic.

## D-009 — Delete live identity, retain necessary settlement evidence

Account deletion requires exact confirmation, CSRF, and recent primary
authentication. Revoke Apple authorization when linked, erase the live player
and ordinary gameplay/profile state, and revoke every session.

Retain only detached pseudonymized StoreKit transaction, notification,
allocation, and refund/reversal evidence needed to prevent duplicate credit and
finish later Apple settlement. Retained evidence must never recreate or rebind
a deleted account.

## D-010 — Moderate exact immutable evidence

Existing `legacy` rows cannot be retrospectively verified. New Arcade and
Multiplayer results retain immutable evidence and verification/risk status.
Moderation targets exact result UUIDs, records actors and reasons, and
quarantines before logical deletion. Quarantine is reversible.

Coin reconciliation derives eligible earned play and immutable economy events.
If revoked earned value has been spent, it becomes nonnegative debt; purchased
value and its allocation history remain separate.

## D-011 — Mirror current authority through a fenced outbox

PimPoPom remains authoritative for eligible scores and achievements. Game
Center is an optional mirror through a revisioned, leased, retryable outbox.
Use a dedicated App Store Connect key and an explicit prerelease/production
lane. Revalidate authority before delivery; newer revisions fence stale work.
Publication failure never rolls back PimPoPom state.

Disabling publication or changing its destination cancels future writes. It
cannot erase history already accepted by Apple.

## D-012 — Release exact commits with controlled migrations

Production deployment requires explicit authorization and one clean, verified
Git commit. Build an allowlisted artifact from that commit, install locked
Composer dependencies in staging, inject no secrets into source, and record the
commit and artifact hash.

Migrations `001` through `024` remain the ordered bootstrap/upgrade history and
run under a shared advisory lock. The artifact-only pending marker may trigger
ordinary migration bootstrap on the first request. Migration `020` is a
destructive internal-alpha reset; its first use against data requires explicit
maintenance authorization, verified backup, API fencing, and paused Game
Center/StoreKit workers.

## D-013 — Isolate the unreleased Multiplayer v2 bridge

Local v2 alpha uses explicit `multiplayer-shared-arcade-v2`, protocol `2`.
PHP issues single-use 60-second tickets from existing cookie/CSRF authentication
and confirmed names, without requiring Game Center. Only ticket and connection
digests are retained; the existing session registry revokes them on logout,
rotation, expiry or deletion. The realtime service revalidates bindings at least
every 15 seconds and on reconnect; there is no claim of instantaneous push revocation.

Only the three exact internal redeem, validate, and result routes use an independent
service Bearer secret instead of cookies/CSRF. All v2 routes default to disabled.
Service-reported final aggregates are immutable, idempotent, explicitly unranked,
and excluded from v1 tables, progression, wallets, moderation/public ranking and
Game Center publication. Account deletion removes shared v2 alpha results.
Persistent hosting, full service integration, admission validation, observability,
retention/cleanup scheduling and production cutover remain separate release gates.
