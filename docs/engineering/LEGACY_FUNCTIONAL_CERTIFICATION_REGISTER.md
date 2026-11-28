# Legacy Functional Certification Register

**Assignment:** Legacy test case execution & functional parity certification  
**Initial HEAD (assignment):** `3d87e815ce371a6cabffacdf8dded559933b5e6f`  
**Prior certification HEAD:** `a61a308` (adjustment migration batch)  
**Register updated HEAD:** _(see git after broker cancellation / UI batch)_  
**Scenario catalog:** `docs/engineering/LEGACY_SCENARIO_CATALOG.json` (**1,490** scenarios)  
**Scenario outcomes (explicit):** `docs/engineering/LEGACY_SCENARIO_EXECUTION_REGISTER.json` → sync via `php scripts/sync-legacy-scenario-execution.php`  
**Batch runner log:** `docs/engineering/LEGACY_CERTIFICATION_EXECUTION.json`  
**Authoritative legacy paths (read-only):** `legacy/truckbill_{broker,ownfleet,partload,admin}`  
**Machine inventory:** `docs/engineering/LEGACY_TEST_INVENTORY.json` (generated via `php scripts/inventory-legacy-tests.php`)

---

## Discovery summary

| Legacy application | Test PHP files | Estimated cases | Additional QA references                                   |
| ------------------ | -------------: | --------------: | ---------------------------------------------------------- |
| Broker             |             49 |             418 | `docs/Truckbill (Broker) - Calculations.md`                |
| OwnFleet           |             70 |             613 | `MODEL_DOCUMENTATION.md` (where present)                   |
| Partload           |             65 |             233 | Accounting feature suite under `tests/Feature/Accounting/` |
| Admin              |             35 |             224 | Pest E2E `CompleteUserJourneysUatTest.php`                 |
| **Total**          |        **219** |       **1,488** | Parity matrices in `docs/audit/LEGACY_*`                   |

**Browser / manual legacy cases:** No standalone Playwright suites were found in legacy trees. Legacy UI behaviour is implied by Feature tests and calculation docs. Nexus Playwright journeys under `tests/e2e/` provide adapted browser coverage where noted below.

**Invented cases:** None. Counts derive only from legacy PHP test files and documented calculation specs.

---

## Classification key

| Class                                    | Meaning                                                                                  |
| ---------------------------------------- | ---------------------------------------------------------------------------------------- |
| **Applicable**                           | Same business scenario applies to Nexus; must pass adapted test.                         |
| **Applicable with technical adaptation** | Scenario applies; stack/routes/models differ (unified Party, branch isolation, Inertia). |
| **Superseded**                           | PO-approved Nexus rule replaces legacy expectation (documented).                         |
| **Not applicable**                       | Legacy-only surface (separate apps, Tally bridge, deprecated modules).                   |
| **Blocked**                              | Cannot execute (environment, Phase gate, missing Nexus surface).                         |

---

## Approved Nexus deviations (do not fail toward legacy)

| ID    | Legacy expectation                         | Nexus authority                                    | Register class                  |
| ----- | ------------------------------------------ | -------------------------------------------------- | ------------------------------- |
| NX-01 | Separate consignee/customer masters        | Unified Party Master                               | Superseded                      |
| NX-02 | Separate Broker Bilty DB                   | Unified Bilty / Lorry Receipt                      | Superseded                      |
| NX-03 | Branch context without confirmation        | Branch entry confirmation (Rules §43)              | Superseded                      |
| NX-04 | Voucher amount prefilled                   | Blank initial voucher amount (PO)                  | Superseded                      |
| NX-05 | Fixed adjustments on hire details          | Delivery-gated Lorry Hire Edit only (Rules §41)    | Superseded                      |
| NX-06 | Multiple active fixed adjustments          | One active row per fixed type (migration `104545`) | Superseded                      |
| NX-07 | Combined voucher legacy correction command | Nexus voucher reservation model                    | Not applicable (legacy console) |
| NX-08 | Phase 11 reporting suite                   | Deferred Future Phase per parity matrix            | Blocked                         |

---

## Execution batches (this session)

### Module Nexus batches (MySQL `nexus_test`, 2026-11-28)

| Stage           | Nexus tests | Result       | Runner                                                    |
| --------------- | ----------: | ------------ | --------------------------------------------------------- |
| 1 Admin / Owner |         139 | **139 PASS** | `run-legacy-certification-batch.php 1-admin-owner`        |
| 2 Masters       |          76 | **76 PASS**  | `2-masters`                                               |
| 3 Broker        |         155 | **155 PASS** | `3-broker`                                                |
| 4 OwnFleet      |          59 | **59 PASS**  | `4-ownfleet`                                              |
| 5 Partload      |           9 | **9 PASS**   | `5-partload` (PO-authorized; not blanket Phase 7 blocked) |
| 6 Accounting    |          25 | **25 PASS**  | `6-accounting`                                            |
| **Total**       |     **463** | **463 PASS** | Focused batches only — not full Nexus suite               |

**Defects fixed this batch:** invalid test vehicle registrations (`tests/Support/ValidVehicleRegistration.php`); enquiry party selector fields; broker entitlement matrix plate; **Lorry Hire edit + unloading weight** requires delivered LR before fixed-adjustment sync (`LorryHireEditTest`).

**P0 (2026-11-28):** Lorry Hire cancellation transferred full **outstanding** to Transporter Book — corrected to **eligible paid voucher amounts only** (`LorryHireCancellationService`, `LorryHireCalculationService::cancellationSettlement`). Pending/In Progress reservations released on cancel (`TransporterVoucherService::releaseUnpaidReservationsForCancelledHire`).

### Batch A — Database upgrade blocker

| Test Case ID  | Legacy source                 | Nexus reference                                     | Status   | Notes                                            |
| ------------- | ----------------------------- | --------------------------------------------------- | -------- | ------------------------------------------------ |
| LGC-DB-ADJ-01 | _(PO migration failure)_      | `BrokerAdjustmentActiveUniquenessMigrationTest` (5) | **PASS** | MySQL `nexus_test`; soft-delete-aware uniqueness |
| LGC-DB-ADJ-02 | Duplicate `misc_expense` rows | Reconcile keeps latest active                       | **PASS** | Financial snapshot uses single active row        |

### Batch B — Stage 1 Admin / Owner (adapted)

| Test Case ID | Legacy source               | Nexus reference                 | Status   |
| ------------ | --------------------------- | ------------------------------- | -------- |
| LGC-ADM-01   | `CompanyEnterExitTest` (14) | `SessionWorkspaceRoutingTest`   | **PASS** |
| LGC-ADM-02   | `CompanyBranchTest` (21)    | `OwnerBranchAdministrationTest` | **PASS** |
| LGC-ADM-03   | Admin CRM / onboarding      | `OwnerGlobalPanelTest`          | **PASS** |

### Batch C — Stage 3 Broker financial & voucher (adapted)

| Test Case ID | Legacy source                                             | Nexus reference                                                               | Status                                                                                    |
| ------------ | --------------------------------------------------------- | ----------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| LGC-BRK-01   | `FreightBookingTest` / `FreightBookingAmountVerifierTest` | `LegacyBrokerHireFinancialScenariosTest` (3) + `FinancialInvariantsTest`      | **PASS**                                                                                  |
| LGC-BRK-02   | `VoucherPaymentTest` (9)                                  | `VoucherServiceTest`                                                          | **PASS**                                                                                  |
| LGC-BRK-03   | Delivery + adjustments                                    | `DeliveryAndAdjustmentTest`                                                   | **PASS**                                                                                  |
| LGC-BRK-04   | `LorryReceiptCalculationServiceTest` (6)                  | `Phase4/LorryReceiptServiceTest` _(partial — customer freight model differs)_ | **Applicable with adaptation** — mapped; full LR min-weight parity executed in next batch |

**Earlier micro-batch (pre-module runner):** **70** Nexus tests, **70 PASS** (see Batch A–C above).

---

## Module certification status (honest)

| Stage           | Legacy scenarios (catalog) | Nexus batch tests | Scenario-level PASS | Module status                                                       |
| --------------- | -------------------------: | ----------------: | ------------------: | ------------------------------------------------------------------- |
| 1 Admin / Owner |                        224 |               139 |                   0 | **IN PROGRESS** — batch PASS; CRM scenarios not individually closed |
| 2 Masters       |                       ~250 |                76 |                   0 | **IN PROGRESS**                                                     |
| 3 Broker        |                       ~500 |               155 |               **8** | **IN PROGRESS** — financial/voucher/LR calc seeds certified         |
| 4 OwnFleet      |                       ~613 |                59 |                   0 | **IN PROGRESS**                                                     |
| 5 Partload      |                        233 |                 9 |                   0 | **IN PROGRESS**                                                     |
| 6 Accounting    |                       ~150 |                25 |                   0 | **IN PROGRESS**                                                     |

**Overall assignment status:** **IN PROGRESS.**  
**Nexus adapted tests executed:** **463 PASS** (module batches).  
**Legacy scenarios with explicit outcome:** **9** recorded (**8 PASS**, **1 NOT APPLICABLE**); **1,481** remain **UNMAPPED** at scenario level (file/batch PASS ≠ scenario certified).

---

## Consolidated result counts (scenario register)

| Status           |                                                               Count |
| ---------------- | ------------------------------------------------------------------: |
| PASS             |                                                                   8 |
| FAIL             |                                                                   0 |
| FIXED AND PASSED |                         1 (LorryHireEdit unloading + delivery gate) |
| BLOCKED          |                               Browser replay, PostgreSQL local auth |
| SUPERSEDED       |                                                     See NX-01…NX-08 |
| NOT APPLICABLE   | 1 (`LEG-BRO-00070` legacy amount verifier) + legacy repair commands |
| UNMAPPED         |                                                               1,481 |

---

## Nexus test adaptation locations

| Purpose                                  | Path                                                                                                       |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Legacy financial scenarios (Broker hire) | `tests/LegacyParity/Broker/LegacyBrokerHireFinancialScenariosTest.php`                                     |
| Playwright (when `E2E_REQUIRE_DEMO=1`)   | `tests/e2e/broker/operational-flow-parity.spec.ts`, `operational-completion.spec.ts`                       |
| Existing parity matrices                 | `docs/audit/LEGACY_TO_NEXUS_FUNCTIONAL_PARITY_MATRIX.md`, `LEGACY_TO_NEXUS_COMPREHENSIVE_PARITY_MATRIX.md` |

---

## Financial reconciliation (Batch C)

Legacy `FreightBookingAmountVerifierTest` consistent scenario (rate × weight, TDS, shortage, detention, paid vouchers):

- Nexus `netPayable` with freight `41054.25`, TDS `445`, detention `500`, shortage `22050` → **19059.25** (matches legacy balance composition after unified formula).
- Paid + pending voucher reservation → **remaining payable** excludes pending reserve (`7264.00` in unit scenario).

**LEG-BRO-00021…00026** mapped to `LegacyBrokerCustomerFreightCalculationTest` + `BrokerInvoiceServiceTest`. Legacy customer variable with **actual &gt; charged** min-weight rule remains **open** (charged-weight rate types); documented in parity matrix §ApplicableWeight.

---

## Browser verification

| Journey                           | Status                        | Limitation                               |
| --------------------------------- | ----------------------------- | ---------------------------------------- |
| Legacy broker operational flows   | **Not executed** this session | Requires live app + `E2E_REQUIRE_DEMO=1` |
| Nexus operational-completion spec | **Available, not run**        | No demo claim                            |

---

## Genuine blockers

1. **Scale:** 1,488 legacy-estimated cases require continued module batches (not full Nexus suite).
2. **Volume / mapping:** 1,481 scenarios still UNMAPPED; continued catalog sync required.
3. **Local PostgreSQL tests:** `nexus_test` @5432 auth (MySQL used for certification batches — **PENDING** PG replay).
4. **Legacy runtime:** Legacy PHPUnit suites are not executed inside legacy apps (different stack); adaptation only.

---

## Next batch order (mandated)

1. Expand `LEGACY_SCENARIO_EXECUTION_REGISTER.json` for Admin CRM, Masters, Broker feature files (per legacy identifier).
2. Adapt legacy Broker reports / ledger / POD feature tests into consolidated Nexus files.
3. Playwright: `operational-flow-parity.spec.ts` with `E2E_REQUIRE_DEMO=1`.
4. Deep OwnFleet + Partload legacy Feature inventory → Nexus gaps (implement when applicable).

---

## Safe database note for PO

Broker adjustment migration: `php artisan migrate` on MySQL dev after `a61a308`. Use read-only duplicate diagnostic from prior engineering report before migrate. Do not run `migrate:fresh` on development data.
