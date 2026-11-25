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

## Database seeding

### Production / fresh install (default)

```bash
# Set Internal Super Admin bootstrap credentials in .env first (never commit real values):
# INTERNAL_SUPER_ADMIN_NAME="Super Admin"
# INTERNAL_SUPER_ADMIN_EMAIL=you@example.com
# INTERNAL_SUPER_ADMIN_PASSWORD='a-strong-unique-secret'

php artisan migrate --seed
# Equivalent:
php artisan migrate
php artisan db:seed
```

`DatabaseSeeder` order (idempotent, production-safe):

1. `RbacSeeder` — permissions, Internal/Customer roles, default customer matrix
2. `IndiaGeographySeeder` — 36 States/UTs + cities + GST state codes
3. `PaymentModeSeeder` — Cash, Bank Transfer, UPI, Cheque, NEFT, RTGS, IMPS, Other
4. `GstRateSeeder` — GST 0/5/12/18/28%
5. `TdsReferenceRulesSeeder` — transporter TDS reference rules
6. `SubscriptionCatalogueSeeder` — plans and add-ons (Rules §15)
7. `InternalSuperAdminSeeder` — exactly one initial Internal Super Admin when none exists

Re-running `php artisan db:seed` does **not** reset Super Admin passwords, duplicate masters, or create demo companies.

Owner-specific catalogues (Goods Type, Truck Type, Tyre Layout, Package Type, Expense Categories, Document Type) are **not** global seeds — they belong to an Owner and are created through Owner/Company workflows (or the opt-in demo seeder).

### Demo workspace (local/testing only)

```bash
LOCAL_DEMO_PASSWORD='your-local-secret' \
php artisan db:seed --class=LocalDemoWorkspaceSeeder
```

Never wired into `DatabaseSeeder`. See `docs/engineering/LOCAL_DEMO_DATA_SETUP.md`.

### Secure credential management

- Keep `INTERNAL_SUPER_ADMIN_*` and `LOCAL_DEMO_PASSWORD` out of git.
- Rotate the initial Super Admin password after first login in shared environments.
- Do not print passwords in seeder output or logs.

## Notes

- Registration remains disabled (Fortify features).
- Self-service account deletion and self-service email change are removed (DEC-003 / DEC-006 / DEC-009).
- Audit foundation (WS-4): see `docs/engineering/AUDIT_FOUNDATION.md`. Migrations create `audit_entries` and the isolated `audit_harness_records` test harness table.
- Private files (WS-5): disk `private_files` under `storage/app/private/files` (never publicly served). Local `FILES_SCANNER=pass|fake|reject` only — production refuses those drivers and fails closed until an approved scanner is installed (PO-P1-DEPS). See `docs/engineering/FILE_STORAGE_FOUNDATION.md`.
- Outbox (WS-6): `php artisan outbox:dispatch` claims committed pending rows onto queue `outbox`. See `docs/engineering/OUTBOX_IDEMPOTENCY_FOUNDATION.md`.
- Before TypeScript checks in a clean checkout: `php artisan wayfinder:generate --with-form`.
- Do not commit `.env` or credentials.
