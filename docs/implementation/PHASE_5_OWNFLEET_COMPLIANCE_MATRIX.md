# Phase 5 OwnFleet — Requirement-to-Code Compliance Matrix

**Date:** 23 November 2026  
**Authority:** `PO-P5-AUTH` / PRD §11–12 / Rules §9–10 / Phases.md Phase 5  
**Baseline HEAD at matrix authoring:** see certification report / git tip after Batch commit

---

## Executive verdict

| Area                                              | Status                                                                                                |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Authorization                                     | **APPROVED** (`PO-P5-AUTH`)                                                                           |
| Unified LR operational prerequisite (Phase 4 D–F) | **Incomplete** — schema/enums/guards only; no product LR create/lifecycle UI or Trip-capable services |
| OwnFleet Trip → Payment product slice             | **Not implemented**                                                                                   |
| Safe to claim Phase 5 Complete                    | **No**                                                                                                |

**Do not fabricate OwnFleet Trips, invoices, or payments without real OwnFleet LRs and approved allocation/dispatch services.**

---

## Prerequisite gate

| Gate                                           | Required                                    | Evidence in codebase                                                            | Status                         |
| ---------------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------ |
| Phase 4 Batch E Unified LR foundations         | Schema + numbering + soft-delete rules      | `lorry_receipts` migration; `LorryReceipt` model; `LrWorkflowContext::OwnFleet` | **Partial** (foundations only) |
| Phase 4 Batch D–F LR ops / conversion          | Create/edit/cancel/print/convert Enquiry→LR | No Consignment application services beyond dependency guards/enums              | **Missing**                    |
| Design package + independent review per screen | Phase 5 design formal                       | Not started for Trip screens                                                    | **Pending**                    |

---

## Field / workflow matrix

| #   | Requirement                                                                                           | Governing source                   | Implementation                                                    | Gap / action                                                                       |
| --- | ----------------------------------------------------------------------------------------------------- | ---------------------------------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| 1   | One OwnFleet LR = One Trip; no independent Trip create form                                           | PRD §11; Rules §9                  | No `Trip` model/table/routes/UI                                   | Implement only after OwnFleet LR create path exists; Trip create must be LR-linked |
| 2   | Trip detail sections: Overview, Pickups & Drops, Dispatch, Expenses & Diesel, POD, Financial, History | Phase 5 auth                       | Absent                                                            | Full-stack Trip detail after domain services                                       |
| 3   | One vehicle per Trip; primary driver from vehicle; substitute trip-only                               | Rules §9                           | Absent                                                            | Domain + UI + audit                                                                |
| 4   | Multi-point pickup fields (station, consignor, goods, weight, pickup date)                            | PRD §11                            | Absent                                                            | Schema + server reconciliation                                                     |
| 5   | Multi-point drop fields + allocation weight/rate/freight                                              | PRD §11                            | Absent                                                            | Server-side allocation engine                                                      |
| 6   | Partial allocation OK Pending/In Progress; exact reconcile on Complete                                | Rules §9                           | Absent                                                            | Completion guard                                                                   |
| 7   | Dispatch: starting odometer; optional departure (default now)                                         | Rules §9                           | Absent                                                            | State transition service                                                           |
| 8   | Complete: ending > starting; arrival; distance = end−start; early/on-time/delayed vs expected         | Rules §9                           | Absent                                                            | Company timezone                                                                   |
| 9   | Trip expenses (category, date, amount, mode, bank/cash, remarks)                                      | PRD §11; DEC-005 OwnFleet in scope | Absent                                                            | Accounting boundary + audit                                                        |
| 10  | Diesel: litres × rate; planned fields per Rules; no invented mileage                                  | Rules §9                           | Absent                                                            | Formula in domain                                                                  |
| 11  | Profit = LR freight + additional − expenses; GST not revenue                                          | Rules §9                           | Absent                                                            | Persisted authoritative totals                                                     |
| 12  | POD optional; private storage; replace+remark; audit                                                  | PRD §11                            | Shared document patterns may exist elsewhere; OwnFleet POD absent | Wire to Trip/LR                                                                    |
| 13  | Cancel Pending/In Progress with reason; soft delete Pending only; no restore                          | Rules §9; ADR-004                  | Absent                                                            | Lifecycle + lists exclude deleted                                                  |
| 14  | Invoicing: single/multi LR; GST/TDS snapshot; numbering; cancel rules                                 | PRD §12                            | Subscription invoices only — not OwnFleet freight invoices        | New OwnFleet invoice domain                                                        |
| 15  | Payments: partials, TDS, deductions, immutable after save; no overpay                                 | PRD §12                            | Absent                                                            | Settlement engine                                                                  |
| 16  | Branch nav: Unified LR, OwnFleet Trips, Expenses, Invoices, Payments when entitled                    | IA                                 | Branch sidebar shows Dashboard/Masters/Enquiries/Settings only    | Add after modules exist; never 404 links                                           |
| 17  | Demo seeder OwnFleet fixtures                                                                         | Full-stack demo rule               | Not seeded (domain unsupported)                                   | Add with first Trip slice                                                          |

---

## Corrective sequencing (authorized)

1. Complete Phase 4 Batches **C → D → E → F** (assignment, LR ops, conversion Option C) with UI + Pest + Playwright + `LocalDemoWorkspaceSeeder`.
2. Produce Phase 5 design package; independent review before each screen.
3. Implement OwnFleet Trip domain (migration → services → routes → UI → tests → seeder) in vertical slices matching this matrix.
4. Certify only with functional proof (allocation reconcile, dispatch/complete, invoice/payment).

---

## Explicit non-claims

- No OwnFleet Trip UI exists to “fix into compliance.”
- Subscription SKU / CRM `InterestedProduct::OwnFleet` / `LrWorkflowContext::OwnFleet` enum values are **not** Phase 5 completion evidence.
- This matrix does **not** rewrite historic Phase 4/5 acceptance records.
