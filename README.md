# SpeedyTapper PHP backend

This repository is the PHP 8.2 API and MariaDB/MySQL persistence service for
the PimPoPom iOS game. It owns identity, sessions, ranked Arcade verification,
leaderboards, progression, StoreKit value, Game Center publication,
multiplayer coordination and settlement, moderation, and account deletion.

Repository source describes a deployable backend candidate. It does not prove
which commit, artifact, schema, TestFlight build, or App Store build is active.

## Requirements

- PHP 8.2 or newer with cURL, Intl, JSON, mbstring, OpenSSL, PDO, and PDO MySQL
- Composer
- MariaDB/MySQL with InnoDB, `utf8mb4`, window functions, and advisory locks

## Setup

```bash
composer install
cp server/config.local.example.php server/config.local.php
```

Fill the ignored local configuration, then create or upgrade the database:

```bash
php server/bin/migrate.php
```

Production prefers `~/.config/speedytapper/config.php`. Set
`SPEEDYTAPPER_CONFIG_PATH` to select another private file; environment
variables override file values. `server/config.local.example.php` documents
the supported keys.

Start the API-only development server on port 4173:

```bash
composer dev
```

Run the complete deterministic PHP verification suite:

```bash
composer check
```

## Repository map

| Path | Purpose |
| --- | --- |
| `api/index.php` | JSON HTTP boundary for extensionless `/api/*` routes |
| `server/src/` | Domain services, validation, persistence, and integrations |
| `server/migrations/` | Ordered MariaDB/MySQL migrations `001` through `025` |
| `server/bin/` | Migration, worker, reconciliation, cleanup, and moderation commands |
| `server/certs/` | Reviewed public Apple and DigiCert trust anchors |
| `test/` | Deterministic PHP tests, SQL fixtures, and disposable MariaDB harnesses |
| `.htaccess` | API-only Apache routing and denial of private source/configuration paths |

## Security

Never commit private configuration, database credentials, OAuth or Apple
keys, identity tokens, session material, signed transactions, or production
exports. Build deployments from an exact clean commit, install locked
dependencies in staging, and keep runtime configuration outside the web root
whenever possible.

## Backend documentation

- [Current source contract](docs/CURRENT_VERSION.md)
- [HTTP and operations contract](docs/PHP_BACKEND.md)
- [Retained Arcade v4 power-up contract](docs/ARCADE_V4.md)
- [Local Arcade v5 4x4 pickup contract](docs/ARCADE_V5.md)
- [Multiplayer contract](docs/MULTIPLAYER.md)
- [Multiplayer v2 bridge and verified PHP deployment](docs/MULTIPLAYER_V2.md)
- [Local v2 earned rewards and fresh leaderboard](docs/MULTIPLAYER_V2_REWARDS.md)
- [Effective backend decisions](docs/DECISIONS.md)
