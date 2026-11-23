# Database Dual-Engine Compatibility (PostgreSQL + MySQL)

**Status:** Implemented for schema bootstrap + focused Pest matrix  
**Date:** 23 November 2026  
**Supported engines:** PostgreSQL 18 (CI service) · MySQL 8.4 (CI service / local Docker)

## Decision

TruckBill Nexus must migrate and operate on both PostgreSQL and MySQL 8.x.
Compatibility is not claimed merely because Laravel supports both drivers.

## Compatibility layer

`App\Support\Database\SoftDeleteUniqueIndex` provides driver-aware operations:

| Concern                          | PostgreSQL                                        | MySQL 8                                                              |
| -------------------------------- | ------------------------------------------------- | -------------------------------------------------------------------- |
| Soft-delete uniqueness           | Partial `UNIQUE INDEX … WHERE deleted_at IS NULL` | `VIRTUAL` generated column that is NULL when inactive + unique index |
| Nullable branch scope uniqueness | Expression `COALESCE(branch_id::text, '')`        | Generated `scope_key` via `CONCAT_WS` + `IFNULL`                     |
| CHECK constraints                | `ADD/DROP CONSTRAINT`                             | `ADD CONSTRAINT` / `DROP CHECK`                                      |
| Index drop                       | `DROP INDEX IF EXISTS`                            | `DROP INDEX … ON table` (+ drop generated columns)                   |

Unsupported drivers throw; required constraints are never silently skipped.

## Migration adjustments (fresh installs)

1. **Identifier length (MySQL 64)** — explicit short names for long composite FKs/indexes.
2. **Composite FK `ON DELETE SET NULL`** — MySQL rejects SET NULL when any column in the composite is NOT NULL (`company_id`). Fresh installs use `restrictOnDelete()` for `(company_id, …)` composites (tenant integrity preserved).
3. **`jsonb` → portable JSON** — Laravel maps `jsonb` on MySQL; broadcast channels use `json()`.
4. **Generated column syntax** — MySQL requires `TYPE GENERATED ALWAYS AS (expr) VIRTUAL NULL` (NULL after VIRTUAL).

Existing PostgreSQL deployments retain already-applied constraint names/behaviors; these edits affect fresh MySQL/PostgreSQL bootstraps without destructive forward migrations.

## Local MySQL verification (2026-11-23)

- Engine: Docker `mysql:8.4` on `127.0.0.1:3307`
- Host Homebrew MySQL 26.x datadir is **not** usable (upgrade lineage blocked from 9.6 → 26) — do not use for Nexus CI
- Result: **full `php artisan migrate` succeeded**
- Pest (MySQL): `DatabaseDialectCompatibilityTest`, `TransporterTdsCalculationTest`, `TaxMasterTest` — **24 passed**

## CI

`.github/workflows/tests.yml` job `mysql`:

- Service `mysql:8.4`
- Fresh migrate
- Focused Pest matrix + `migrate:fresh --seed` + `TdsReferenceRulesSeeder`

PostgreSQL job remains the full Pest + Playwright smoke gate.

## Gaps (honest)

- Full Pest suite not yet mandatory on MySQL CI (focused matrix first)
- Runtime query audit for remaining raw dialect usage is ongoing
- Host brew MySQL upgrade path is an operator concern, not an app defect
