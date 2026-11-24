# Phase 7 — Legacy Partload Readiness

**Date:** 24 November 2026  
**Status:** **Readiness analysis only** — Phase 7 coding **not authorized** (`PO-P7-AUTH` absent).  
**Legacy SHA:** `fb9b955e64f6370c662a3a157081a96596a33690` (`legacy/truckbill_partload/`, account)  
**Analysis:** `docs/audit/LEGACY_PARTLOAD_WORKFLOW_ANALYSIS.md`  
**Nexus HEAD:** `e05d1d6f505ac076963ede60b86e4ebe062e2edb`

---

## Readiness verdict

| Dimension               | Status                                                                          |
| ----------------------- | ------------------------------------------------------------------------------- |
| Legacy exploration      | **Complete** (routes, controllers, models, Vue bilty/challan, accounting tests) |
| Nexus foundation        | **Partial** — unified LR `partload` context + enquiries only                    |
| Design gate             | **Blocked** — `PO-P4-DESIGN-FORMAL` Pending for product UI                      |
| Phase 7 authorization   | **Blocked** — `PO-P7-AUTH` not issued                                           |
| Accounting              | **Blocked** — ADR-010 Pending                                                   |
| Phase 5/6 stabilization | **Open residuals** — see `PHASE_5_6_FINAL_GAP_REGISTER.md`                      |

**Recommendation:** Do **not** start Phase 7 implementation until PO issues `PO-P7-AUTH`, accepts Phase 5/6 certification exceptions or closes them, and approves Partload UI design.

---

## What Nexus can reuse (ready)

| Asset                                             | Evidence                                     |
| ------------------------------------------------- | -------------------------------------------- |
| Company / branch tenancy                          | Phase 1–2                                    |
| RBAC + `SubscriptionModule::Partload` entitlement | Catalogue + resolver                         |
| Unified LR + `LrWorkflowContext::Partload`        | `LorryReceiptService::assertPartloadFields`  |
| Party / station masters                           | Phase 3                                      |
| Document numbering / soft delete / audit          | Shared services                              |
| Enquiry → conversion (partial)                    | `EnquiryController`, domain enquiry services |
| Weight standard                                   | `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`        |
| Demo policy                                       | Extend `LocalDemoWorkspaceSeeder` only       |

---

## What legacy requires (not in Nexus)

Ordered by operational dependency:

1. **Package types, goods groups, agents, station-agent mapping** — legacy quick/bulk master routes.
2. **Bilty-specific business rules** — advance modes, charge minimums, GST exemption (tests listed in analysis doc).
3. **Inward register** — hub receiving before line-haul.
4. **Challan + main challan** — manifest + truck freight settlement (`MainChallanController`, Vue truck_freight).
5. **Delivery receipt** — `lookupGr` + print + accounting bridge.
6. **Partload invoice** — `pendingBilties` selection pattern.
7. **Vouchers** — `entityBalance` AJAX settlements.
8. **Quick search** — cross-document operator search.
9. **Registers / loading report** — Phase 11 or late Phase 7 batch.
10. **Full accounting COA** — defer to Phase 8 unless PO narrows scope.

**Nexus grep (24 Nov):** zero `Challan`, `MainChallan`, `InwardRegister`, `DeliveryReceipt` classes under `app/`.

---

## Batch 1 dependencies (before Phase 7 Batch A)

| Gap                         | Legacy                                    | Nexus Batch 1 target                                                    |
| --------------------------- | ----------------------------------------- | ----------------------------------------------------------------------- |
| Party selection on bilty/LR | Partload `PartyController` + quick create | `SearchablePartySelect` on `lr-form.tsx`                                |
| Weight entry                | Bilty detail charged/actual weights       | MT actual + KG Wt./Unit; `FreightCalculator::PerMt` ÷1000 on KG storage |

Phase 7 Batch A should **not** fork a separate bilty form until Batch 1 LR UX lands (avoids dual party/weight semantics).

---

## Suggested Phase 7 batches (non-binding — PO approval required)

| Batch | Scope                                                       | Legacy anchors                           |
| ----- | ----------------------------------------------------------- | ---------------------------------------- |
| **A** | Partload LR rules + permissions + demo fixtures             | `BiltyController`, `BiltyRequest`        |
| **B** | Inward register                                             | `InwardRegisterController`               |
| **C** | Challan                                                     | `ChallanController`                      |
| **D** | Main challan                                                | `MainChallanController`                  |
| **E** | Delivery receipt                                            | `DeliveryReceiptController::lookupGr`    |
| **F** | Invoice + voucher settlements                               | `InvoiceController`, `VoucherController` |
| **G** | Agents, package types, quick search, reports, certification | Remaining routes                         |

Cardinality: **do not assume** Broker linked-bilty or OwnFleet trip rules apply — `PO-P4-CNV-CARDINALITY` remains Pending.

---

## Open PO / ADR gates

| Gate                  | Impact on Phase 7                  |
| --------------------- | ---------------------------------- |
| PO-P7-AUTH            | Hard start gate                    |
| PO-P4-DESIGN-FORMAL   | UI implementation                  |
| PO-P4-CNV-CARDINALITY | Enquiry → bilty/challan conversion |
| ADR-010               | Accounting bridge scope            |
| PO-P3-PAY-PROD        | Live Razorpay (subscription only)  |

---

## Legacy reference paths

| Resource          | Location                                                                                 |
| ----------------- | ---------------------------------------------------------------------------------------- |
| Routes            | `legacy/truckbill_partload/routes/web.php`                                               |
| Bilty             | `app/Http/Controllers/BiltyController.php`, `app/Models/Bilty.php`                       |
| Challan chain     | `ChallanController.php`, `MainChallanController.php`                                     |
| Inward / delivery | `InwardRegisterController.php`, `DeliveryReceiptController.php`                          |
| Accounting        | `app/Http/Controllers/Accounting/*`                                                      |
| Prior handoff     | `PHASE_7_PARTLOAD_HANDOFF.md` (superseded for SHA path — now `legacy/` under Nexus repo) |

---

## Certification prerequisites (when authorized)

- Pest feature coverage mirroring legacy critical tests (GST exemption, advance modes, main challan bridge).
- Playwright: bilty → challan → delivery happy path.
- Tenant isolation on station-agent and branch settings.
- Update `LEGACY_TO_NEXUS_FUNCTIONAL_PARITY_MATRIX.md` PLD-* rows after each batch.

_Documentation only — no application code changed in this assignment._
