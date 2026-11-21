# Local Development Setup (Cross-OS)

**Authority:** ADR-007 / PO-P1-ADR-007; Phase 1 WS-1  
**Stack:** Laravel 13, PHP 8.5, React + Inertia, PostgreSQL, Redis (phpredis)

## Prerequisites

| Tool           | Notes                                                                                                  |
| -------------- | ------------------------------------------------------------------------------------------------------ |
| PHP 8.5+       | With `pgsql`, `redis` (phpredis), `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath` |
| Composer 2     | —                                                                                                      |
| Node.js 22+    | npm included                                                                                           |
| PostgreSQL 16+ | Create databases below                                                                                 |
| Redis 7+       | Default `127.0.0.1:6379`                                                                               |

Works on macOS, Linux and Windows (WSL2 recommended on Windows for PHP extensions and Redis).

## Databases

Create two databases owned by the same role (names match `.env.example` / `phpunit.xml`):

```sql
CREATE USER nexus_user WITH PASSWORD 'change-me';
CREATE DATABASE nexus OWNER nexus_user;
CREATE DATABASE nexus_test OWNER nexus_user;
GRANT ALL PRIVILEGES ON DATABASE nexus TO nexus_user;
GRANT ALL PRIVILEGES ON DATABASE nexus_test TO nexus_user;
```

On PostgreSQL 15+, also grant schema usage on `public` for the role as required by your server policy.

## Bootstrap

```bash
cp .env.example .env
# Edit DB_* and REDIS_* credentials
composer install
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Or: `composer setup` after `.env` exists with valid PostgreSQL credentials (do not rely on SQLite).

## Configuration baseline (ADR-007)

| Concern                 | Local intent                                                    |
| ----------------------- | --------------------------------------------------------------- |
| `DB_CONNECTION`         | `pgsql`                                                         |
| Cache / queue / session | Redis via `phpredis`                                            |
| Tests (`phpunit.xml`)   | PostgreSQL `nexus_test`; array cache; sync queue; array session |

## Quality checks

```bash
composer test          # Pint, Larastan, Pest on PostgreSQL
npm run types:check
npm run build
```

## Notes

- Registration remains disabled (Fortify features).
- Self-service account deletion and self-service email change are removed (DEC-003 / DEC-006 / DEC-009).
- Audit foundation (WS-4): see `docs/engineering/AUDIT_FOUNDATION.md`. Migrations create `audit_entries` and the isolated `audit_harness_records` test harness table.
- Private files (WS-5): disk `private_files` under `storage/app/private/files` (never publicly served). Optional `FILES_SCANNER=pass|fake|reject` — see `docs/engineering/FILE_STORAGE_FOUNDATION.md`. No real scanner dependency without PO-P1-DEPS approval.
- Before TypeScript checks in a clean checkout: `php artisan wayfinder:generate --with-form`.
- Do not commit `.env` or credentials.
