# Backend-only current-iOS slice design

Date: 2026-08-16

## Objective

Turn the combined SpeedyTapper browser/PHP repository into a clean, sale-ready
PHP API and MariaDB/MySQL backend. Preserve the former combined source at the
pre-cleanup commit on `aug-2026-archive`, while making `main` contain only the
current backend, its tests, operations tooling, and concise current
documentation.

The active client is iOS only. No launched client or production user requires
the frozen browser, Vercel generation, legacy Arcade proof generations, or
transitional tickets.

## Sources of truth

- Pre-cleanup outer-repository commit: `ab7059f91b70139a4529591878013abb1be23421`.
- Latest audited iOS candidate: commit
  `c20fcbeb7f053e7b0f50cac1be8942854909e82a`, app version `1.02 (22)`.
- The iOS app build number is independent of its network build ID.
- Current iOS network contract:
  - minimum build ID `20260729-1`;
  - Arcade ruleset `reaction-proof-v3`, proof version 2;
  - Multiplayer ruleset `multiplayer-own-color-v1`, protocol/proof version 1.
- PHP implementation and deterministic tests are authoritative for backend
  behavior. Git history is authoritative for removed historical material.

## Repository boundary

Keep:

- `.gitignore`, `.htaccess`, `AGENTS.md`, `README.md`, and Composer metadata;
- `api/index.php`;
- all `server/src`, `server/bin`, `server/certs`, `server/migrations`, the
  autoloader, local router, and configuration example;
- migrations `001` through `022` unchanged as the upgrade/bootstrap history;
- PHP tests, MariaDB harnesses, SQL fixtures, and certificate fixtures;
- a compact documentation set for the current contract, decisions,
  multiplayer protocol, and operations.

Remove:

- `index.html`, `styles.css`, `manifest.webmanifest`, and `sw.js`;
- root `src`, browser/PWA assets, asset generators, provenance tied only to
  removed assets, and historical visual QA;
- the Vercel handler/model/configuration and local Node server;
- `package.json`, `package-lock.json`, all JavaScript tests, and Node-only
  ignored outputs;
- superseded handoffs, historical decisions, duplicate contracts, and native
  UI/latency analysis that belongs in the iOS repository.

The external iOS repositories, crash symbols, private backend configuration,
Apple keys, and other secrets are outside this cleanup and must not be copied,
staged, archived, logged, or deleted.

## Build and protocol compatibility

Add one shared release-ID parser/comparator for IDs in exact `YYYYMMDD-N`
format. Arcade and Multiplayer accept a build ID when it is well formed and its
numeric `(date, sequence)` tuple is at least `(20260729, 1)`.

Build IDs are audit metadata and ticket/manifest bindings; they do not select
gameplay semantics. A higher iOS release using the same exact ruleset and proof
versions needs no PHP deployment. A ruleset, event tuple, replay rule, protocol,
or proof-version change requires an explicit coordinated backend release.

Reject malformed and below-minimum IDs. Require exact current protocol
identifiers. Never infer a new protocol from a higher build number.

## Runtime cleanup

Collapse Arcade parsing and replay to the current color-bearing v3 tuples and
persistent-decoy rules. Remove v2/colorless parsing, legacy decoy timing,
legacy difficulty paths, transitional stored-ticket handling, and Zen proof
submission/replay. Retain Zen leaderboard/profile reads because the current iOS
client still consumes them; Zen remains locally played and never writes a run.

Constrain Multiplayer to the shared minimum-build policy and exact v1 contract.
The server keeps the creator's accepted build ID in the match manifest as audit
metadata, but protocol compatibility comes only from the exact ruleset and
protocol/proof versions. The currently audited iOS client still compares the
manifest build ID to `20260729-1`; before distributing a client that sends a
higher network build ID, its manifest validation must adopt this same
minimum-build policy. No PHP change is then needed while the protocol remains
unchanged.

Retain current iOS routes plus backend-operated health, Apple notification,
administration, moderation, worker, and migration surfaces. Remove only obsolete
web compatibility routes and aliases:

- `GET /api/top-scores`;
- unversioned `POST /api/storekit/transactions`;
- `DELETE /api/account` and `DELETE /api/mobile/v1/account`;
- retired `POST /api/leaderboard` tombstone.

Require explicit Google authentication intent and strict current request field
sets, including account deletion. Remove raw legacy PHP-session upgrade logic.
Keep cookie sessions and CSRF because the current native client uses them and
sends no browser `Origin` header.

Make the development router API-only and make Apache deny or return `404` for
non-API paths while continuing to protect `server`, `vendor`, Git metadata,
configuration, and secrets.

## Tooling and dependencies

Replace npm wrappers with Composer-facing commands:

- `composer dev` for the API-only PHP development router;
- `composer test` for the deterministic PHP suite;
- `composer check` for Composer validation/audit, PHP lint, and all PHP tests;
- explicit Composer aliases for the disposable MariaDB harnesses.

Update the locked Guzzle packages to patched compatible releases so
`composer audit --locked --no-dev` is clean. Do not broaden dependency updates.

## Documentation slice

The final documentation must describe only current backend truth:

- `README.md`: purpose, requirements, minimal setup, repository map, checks,
  and links;
- `docs/CURRENT_VERSION.md`: short supported-contract/schema snapshot without
  deployment claims;
- `docs/PHP_BACKEND.md`: exact HTTP contract plus configuration and operations;
- `docs/MULTIPLAYER.md`: exact PHP lobby, manifest, transcript, settlement, and
  trust contract, without native latency forensics;
- `docs/DECISIONS.md`: only effective backend decisions, with Git/archive
  history replacing supersession chronology;
- co-located certificate and fixture READMEs.

Examples must match code, including achievement claim `{ "id": ... }`.
Documentation must distinguish source state from deployed Hostinger, database,
TestFlight, and App Store state.

## Local artifact cleanup

After tracked tooling no longer needs them, remove confirmed disposable local
outputs such as `.vercel`, `node_modules`, empty `.data`, `.DS_Store` files, and
empty preview/output directories inside the outer checkout. No SpeedyTapper
Hostinger ZIP or tar archive was found during the audit.

Keep generated `vendor` until final verification completes; it is ignored and
excluded from the source slice, while production staging installs it from the
lockfile. Do not inspect, move, or delete ignored secret files as part of this
cleanup.

## Verification

Add or update deterministic tests before changing runtime behavior. Cover:

- minimum, higher-date, higher-sequence, malformed, and older build IDs;
- exact v3/proof-2 Arcade tuple shapes and rejection of v2/Zen proofs;
- persistent-decoy and current difficulty behavior only;
- exact Multiplayer v1 protocol acceptance across minimum and higher builds;
- removal of obsolete aliases/routes and explicit Google intent;
- strict account-deletion fields;
- API-only development and Apache routing;
- all retained identity, StoreKit, economy, moderation, account deletion,
  Game Center, and Multiplayer behavior.

Run `composer check`, relevant targeted tests, all available MariaDB harnesses,
`git diff --check`, a stale-reference scan, and an allowlisted source/artifact
manifest review. Do not deploy; production deployment remains a separate,
explicitly authorized action.

## Delivery and recovery

Create `aug-2026-archive` at the exact pre-cleanup commit. Implement and commit
the backend-only slice on `main`, preserving the already-authorized uncommitted
documentation baseline as input but correcting it against code. Report the
archive branch, final commit, checks, any unavailable MariaDB/live smoke tests,
and preserved unrelated/secret state.
