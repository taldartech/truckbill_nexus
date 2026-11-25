# Owner Panel Reconstruction, Location Masters & Operational Integration

**Date:** 25 November 2026  
**Starting HEAD (this closure batch):** `68538800ab68b142f8f245535fe779a6d1373dd7`  
**Prior foundation:** `8bc41e3` (Owner Panel / geography / Branch GST), `6853880` (OwnFleet print snapshot GSTIN)  
**Status:** Integration & certification closure (local) — no push / no deploy

## Geographic catalogue

| Check                      | Result                                                                                          |
| -------------------------- | ----------------------------------------------------------------------------------------------- |
| Official States + UTs      | **36** (`IndiaGeographyData::STATES`)                                                           |
| City/district rows         | **727** after seeder (JSON + UT supplements)                                                    |
| GST state codes            | Present on every State row                                                                      |
| UT rename / alias handling | Delhi, Chandigarh, DNH+DD, Ladakh split, Pondicherry→Puducherry, Orissa→Odisha, etc.            |
| Historical link report     | `storage/app/geography-migration-report.json` — live `nexus`: matched=0 unmatched=0 ambiguous=0 |
| Platform Locations         | Internal `/admin/locations` City maintenance (no deploy required for new cities)                |

## Geographic selector consumers

`StateCityFields` + `/api/geography/states|cities` wired into:

- Company Create / Owner Settings
- Branch Create / Edit (Owner Panel)
- Party Create / Edit (all party roles via shared form)
- Station Create / Edit (Station Master retained; City Master is address only)
- Inline / quick-create Party sheet (LR and searchable selectors)

Backend: `ResolvesAddressGeography` + `GeographyLookupService::resolveAddressFields` rejects mismatched City↔State IDs. Party/Station services persist `state_id` / `city_id` with denormalized names.

**Preserved:** Broker route from Consignor/Consignee; OwnFleet route from Station Master.

## Effective GST consumers

| Consumer                        | Resolver / snapshot behavior                                       |
| ------------------------------- | ------------------------------------------------------------------ |
| Broker invoice create / preview | `EffectiveGstConfigurationResolver`                                |
| OwnFleet trip invoice create    | `EffectiveGstConfigurationResolver`                                |
| Broker / OwnFleet print         | Prefer issued `gst_rate_snapshot.supplier_gstin` over live company |
| Historical integrity Pest       | `EffectiveBranchGstInvoiceTest` — issued snapshot retained         |

## Owner Panel visual acceptance

Seven-viewport captures under `storage/visual-certification/owner-panel-closure/` for Owner home, Companies (incl. 390×844), Company create, Branch create (capacity-reached empty state for Alpha), Settings.

**Inspected:** sidebar, company cards, Create company CTA, searchable filters, Branch capacity messaging, no horizontal overflow on captured viewports.

### Intentional Nexus vs legacy differences

- Multi-company Owner Portal with company cards + attention rail (not single-company Blade admin shell).
- Session-independent management routes (`AuthorizesOwnerPanel`) — no WorkspaceSession mutation for admin.
- Branch GST inherit/independent modes.
- Subscription / branch capacity gates on Create Branch.
- React/Inertia design system density vs legacy Bootstrap tables.

## Regression evidence (this closure)

### Pest — disposable PostgreSQL `:5433` (`nexus_test`)

```text
php -d memory_limit=2048M -d xdebug.mode=off vendor/bin/pest --compact
→ exit 0 | tests 820 | passed 818 | skipped 2 | failed 0 | assertions 5865
```

Development DB `nexus` on `:5432` was **not** used for RefreshDatabase.

### Pest — disposable MySQL 8.4 `:3307` (`nexus_mysql_test`)

```text
→ exit 0 | tests 820 | passed 818 | skipped 2 | failed 0 | assertions 5865
```

### Focused geography / GST / OwnFleet expectation alignment

62/62 passed including `GeographyMasterTest` (36 states), company create/settings with `state_id`/`city_id`, bilty `manage_expenses` primary action, hire loading-weight inheritance.

### Playwright (browser)

| Suite                                                    | Result                                                                        |
| -------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Broker complete workflow                                 | **Passed**                                                                    |
| OwnFleet complete workflow (main + duplicate trip)       | **Passed**                                                                    |
| OwnFleet vehicle-lock edge (unified expenses redirect)   | **Passed** after expectation update                                           |
| Company-create A–C (management URLs + StateCityFields)   | **Still failing** — fixture/CTA/URL drift vs `/management` shell              |
| Owner global-panel / enter-branch / session-independence | **Still failing** — `Return to Owner Portal` / capacity / fixture assumptions |
| Persona matrix (earlier concurrent run)                  | Partial — login contention under load                                         |

Screenshots: `storage/visual-certification/owner-panel-closure/` (33 captures across 7 viewports for key Owner screens).

### Frontend

- `npm run types:check` — pass
- `npm run build` — pass
- `npx vp check --fix` on touched UI — pass
- Larastan: project-wide findings remain (pre-existing baseline); geography list return types tightened

## Remaining genuine blockers

1. Full Playwright A–J matrix not 100% green in a single concurrent run (login contention / long Broker UI journeys).
2. Larastan project-wide clean gate not achieved (pre-existing).
3. Demo Alpha branch capacity blocks Create Branch form in visual empty-state (product-correct).
4. Phase gates unchanged: `PO-P4-DESIGN-FORMAL`, `PO-P4-CNV-CARDINALITY`, live Razorpay, accounting ADR-010 — **Pending**.

## Safety

- Additive migrations / seeders only; live `nexus` not wiped
- Legacy apps untouched
- No push / no deploy
