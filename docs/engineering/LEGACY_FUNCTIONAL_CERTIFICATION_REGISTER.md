# Legacy Functional Certification Register

**Assignment:** Legacy test case execution & functional parity certification  
**Initial HEAD:** `a61a308`  
**Register updated HEAD:** _(see git HEAD after certification commit)_  
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

**Batch totals executed (Nexus):** **70** tests, **70 PASS**, **0 FAIL** (MySQL isolated `nexus_test`, 2026-11-28).

---

## Module certification status (honest)

| Stage           |        Legacy cases (est.) |                            Mapped | Executed (Nexus) | Certified                                                   |
| --------------- | -------------------------: | --------------------------------: | ---------------: | ----------------------------------------------------------- |
| 1 Admin / Owner |                       224+ |                                45 |              15+ | **Partial** — owner/workspace batch PASS; CRM depth pending |
| 2 Masters       |                       ~200 |                                30 |                0 | **Not started** (this session)                              |
| 3 Broker        |                        418 |                               120 |              40+ | **Partial** — core financial/voucher/delivery PASS          |
| 4 OwnFleet      |                        613 |                                80 |                0 | **Not started**                                             |
| 5 Partload      |                        233 |                                60 |                0 | **Blocked** — Phase 7 authorization                         |
| 6 Accounting    | legacy PL accounting tests | Nexus `tests/Feature/Accounting/` |   0 this session | **Partial** — separate Nexus suite exists                   |

**Overall assignment status:** **In progress.** Inventory complete; execution **~4.7%** of estimated legacy cases (70 Nexus tests against 1,488 legacy-estimated cases). Remaining batches must continue module order.

---

## Consolidated result counts (cumulative this assignment)

| Status           |                                                               Count |
| ---------------- | ------------------------------------------------------------------: |
| PASS             |                                                                  70 |
| FAIL             |                                                                   0 |
| FIXED AND PASSED |                1 (P0 adjustment migration — prior commit `a61a308`) |
| BLOCKED          | Phase 5 Partload, full legacy browser replay, PostgreSQL local auth |
| SUPERSEDED       |                                                     See NX-01…NX-08 |
| NOT APPLICABLE   |               Legacy-only repair commands, multi-app tenancy probes |

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

Discrepancies requiring dedicated LR customer-freight batch: legacy `LorryReceiptCalculationServiceTest` uses legacy min(loading, unloading) customer rules — Nexus customer freight path uses canonical KG/MT services; **not certified identical** until `LegacyBrokerCustomerFreightParityTest` batch lands.

---

## Browser verification

| Journey                           | Status                        | Limitation                               |
| --------------------------------- | ----------------------------- | ---------------------------------------- |
| Legacy broker operational flows   | **Not executed** this session | Requires live app + `E2E_REQUIRE_DEMO=1` |
| Nexus operational-completion spec | **Available, not run**        | No demo claim                            |

---

## Genuine blockers

1. **Scale:** 1,488 legacy-estimated cases require continued module batches (not full Nexus suite).
2. **Partload / Phase 7:** Legacy tests exist; Nexus authorization gate.
3. **Local PostgreSQL tests:** `nexus_test` @5432 password (MySQL used for batches).
4. **Legacy runtime:** Legacy PHPUnit suites are not executed inside legacy apps (different stack); adaptation only.

---

## Next batch order (mandated)

1. Complete Stage 1 CRM/subscription legacy → `tests/Feature/Subscription/`, admin CRM equivalents.
2. Stage 2 Masters — map `TransporterMasterTest`, `TruckTest`, `MasterFieldsTest`.
3. Stage 3 — adapt `LorryReceiptTest`, `InvoiceTest`, `PartyLedgerReportTest`, Playwright voucher/register journeys.
4. Stage 4+ per dependency order.

---

## Safe database note for PO

Broker adjustment migration: `php artisan migrate` on MySQL dev after `a61a308`. Use read-only duplicate diagnostic from prior engineering report before migrate. Do not run `migrate:fresh` on development data.
