# Premium UI · Transporter TDS · Dual-DB — Certification Report

**Date:** 23 November 2026  
**Initial HEAD:** `47f86a975c36c67fa6f2911cab60b62f67aa82f8`  
**Remote match:** Verified against `origin/main` at start of wave  
**GitHub Actions #80:** In progress at review time (not re-claimed here)

## A. Initial / final HEAD

|       | SHA                                           |
| ----- | --------------------------------------------- |
| Start | `47f86a975c36c67fa6f2911cab60b62f67aa82f8`    |
| Final | _(recorded after local commits in this wave)_ |

## B. Premium design changes

- Poppins + brand green `#2FB36F` / blue `#2D8CFF` tokens in `app.css`
- Party Master: `FormSection` grouping (Common → Roles → Role panels → Actions)
- Role selectable cards with primary accent when selected
- Compact TDS panel with live preview region

## C. Shared button / color system

- `Button` variants: `primary`, `info`, `warning`, `destructive`, `secondary`, `ghost`, …
- CSS tokens: `--info`, `--warning`, `--primary`
- `AppIconAction` passes semantic variants + tooltip / `aria-label`

## D. Interactive form improvements

- Debounced server TDS preview (`useHttp` → `/parties/tds-preview`)
- Dynamic transporter/driver panels by role
- Override remark required when override enabled
- Processing-disabled primary Save

**Gap:** Async searchable selects for large masters not fully rolled out across all screens.

## E. Party / TDS UI screenshots

Not captured in this agent session (no browser QA pass completed). Playwright spec: `tests/e2e/branch/party-tds.spec.ts` (gated on `E2E_REQUIRE_COMPANY_CREATE=1`).

## F. TDS calculation architecture

See `docs/engineering/TRANSPORTER_TDS_CALCULATION_SPEC.md`.  
Key types: `TransporterTdsCalculationService`, `TransporterTdsCalculationResult`, `TdsRuleEvaluator`.

## G. TDS rule scenarios (Pest)

`tests/Feature/Phase3/TransporterTdsCalculationTest.php` + `TaxMasterTest` — covering PAN classes, declaration/vehicles, agency, override, April 1 reset, no-match error. **Passed on PostgreSQL and MySQL.**

## H. April 1 declaration reset

- Migration `transporter_declaration_resets`
- Service + Artisan command + scheduled yearly Asia/Kolkata
- Idempotency tested

## I. PostgreSQL migration results

Existing local/CI PostgreSQL path remains green (SoftDeleteUniqueIndex pgsql branch). Focused Pest 37/37 on default pgsql connection.

## J. MySQL migration results

Docker MySQL 8.4 (`127.0.0.1:3307`): **full migrate succeeded**.  
Host brew MySQL 26 blocked by datadir upgrade lineage — documented, not used.

## K. Database compatibility fixes

- `SoftDeleteUniqueIndex` (partial indexes / generated columns / CHECK / scope keys)
- Short MySQL identifier names
- Composite FK `restrictOnDelete` for NOT NULL `company_id` composites
- Generated column syntax order for MySQL

See `docs/engineering/DATABASE_DUAL_ENGINE_COMPATIBILITY.md`.

## L. Database test matrix

| Engine     | Migrate          | Focused Pest | Full Pest                   | Seed                       |
| ---------- | ---------------- | ------------ | --------------------------- | -------------------------- |
| PostgreSQL | Yes (CI + local) | 37 passed    | Pending this wave final run | Demo seeder test passed    |
| MySQL 8.4  | Yes (Docker)     | 24 passed    | Not full suite yet          | CI seeds reference + fresh |

## M. Pest results (this wave)

- PostgreSQL focused: **37 passed**
- MySQL focused: **24 passed**

## N. Playwright

- Spec added: `party-tds.spec.ts`
- Full browser journeys / viewport matrix / screenshots: **not completed** this wave (requires e2e seed + entitled company)

## O. k6

Not re-run this wave.

## P. CI

- PostgreSQL job unchanged (full suite)
- **New** `mysql` job: migrate + focused Pest + seed

## Q. Security / tenant isolation

- TDS preview authorized via existing Party controller / workspace context
- Override permission gates retained
- Cross-company scenarios covered in Pest suite
- No secrets committed; `.env` not overwritten

## R. Remaining gaps

1. Full MySQL Pest suite not yet CI-mandatory
2. Searchable async selects not global
3. Global screen-by-screen premium polish incomplete
4. Playwright/k6 evidence incomplete
5. Lorry Hire / invoice TDS amounts deferred to operational modules
6. Statutory tax legal validation **not** claimed
7. OwnFleet Trip→Invoice→Payment still not implemented

## S. Local commits

Listed in agent final report after commit step (no push).

## T. Certification verdict

**PARTIAL CERTIFICATION — PRODUCTION-QUALITY FOR AUTHORIZED SLICES**

| Slice                                       | Verdict                                                |
| ------------------------------------------- | ------------------------------------------------------ |
| Dual-DB fresh migrate                       | **Pass** (PG + MySQL 8.4 proven)                       |
| Transporter TDS rate engine + Party preview | **Pass** (server-authoritative)                        |
| April 1 reset                               | **Pass** (scheduled + idempotent)                      |
| Premium Party Master UI                     | **Pass with gaps** (usable; not every screen polished) |
| Full ERP premium UX                         | **Not complete**                                       |
| Full dual-DB Pest + Playwright + k6         | **Not complete**                                       |

Honest: looks and behaves substantially better than skeleton on Party/TDS; dual-DB schema path proven; remaining UX/CI breadth must continue.
