# Product Owner Decisions Required (Post Legacy Parity Audit)

**Date:** 24 November 2026  
**Trigger:** Legacy Broker/OwnFleet parity audits + Nexus-exclusive audit + merged matrix  
**Authority preserved:** Pending gates in `docs/governing/Phases.md` and Phase 3 EX-01…07 remain **unchanged**

Decisions below are **additive** to existing registers (`PHASE_1_TO_5_CONSOLIDATED_PO_DECISIONS.md`, `PHASE_2_PRODUCT_OWNER_DECISIONS.md`). Do not treat silence as approval.

---

## Decision summary

| ID         | Topic                                  | Urgency  | Blocks migration wave                |
| ---------- | -------------------------------------- | -------- | ------------------------------------ |
| PO-LGC-001 | Custom broker invoice (manual lines)   | **High** | W0 Broker (manual-invoice customers) |
| PO-LGC-002 | Broker invoice **edit** policy         | **High** | W1 Broker billing                    |
| PO-LGC-003 | Voucher **edit/void** policy           | **High** | W1 treasury/ops                      |
| PO-LGC-004 | Platform transporter master (M16)      | Medium   | Optional                             |
| PO-LGC-005 | Masters bulk import scope              | Medium   | W1 large master cutover              |
| PO-LGC-006 | Hire settlement PDF vs adjustment-only | Medium   | Transporter disputes                 |
| PO-LGC-007 | Direct hire PATCH vs adjustment-only   | Medium   | FH04 training/SOP                    |
| PO-LGC-008 | Driver advance / payroll (OwnFleet)    | Low      | Phase 10 hold                        |
| PO-LGC-009 | Production tax PDF activation          | **High** | Legal invoice dispatch               |
| PO-LGC-010 | AUD-CON-006 entitlement matrix         | Medium   | Restricted-mode UI                   |

---

## PO-LGC-001 — Custom broker invoice (BRK-IN06)

**Context:** Legacy `CustomInvoiceController` allows manual lines + LR lookup. Nexus broker invoices are LR/memo-linked.

| Option              | Description                                                          | Implication                                 |
| ------------------- | -------------------------------------------------------------------- | ------------------------------------------- |
| **A (recommended)** | **Reject port** — broker invoices must trace to delivered LRs/memoes | Migrate customers off manual invoices first |
| B                   | Port limited manual-line invoice with audit + permission             | New domain service + tests                  |

**Needed:** Single choice A or B before claiming Broker billing parity for affected customers.

---

## PO-LGC-002 — Broker invoice edit (BRK-IN03)

| Option | Description                                                   |
| ------ | ------------------------------------------------------------- |
| A      | **Cancel + reissue only** (current) — document as Intentional |
| B      | Allow header/line edit before first payment with audit        |
| C      | Allow edit until GST filing lock (future Phase 8 coupling)    |

---

## PO-LGC-003 — Voucher edit/void (BRK-VP03, VP04)

| Option | Description                                                  |
| ------ | ------------------------------------------------------------ |
| A      | Status workflow only — document SOP                          |
| B      | Edit unpaid vouchers; void paid with reversal + book balance |
| C      | Legacy-full edit/delete (not recommended — audit risk)       |

**Needed before W1** if treasury expects legacy `export-payout` + same-day corrections together.

---

## PO-LGC-004 — Platform transporter master (BRK-M16)

**Question:** Is cross-company transporter registry in scope for Phase 2 Internal admin, or **reject** as legacy-only?

| Option | Outcome                                                     |
| ------ | ----------------------------------------------------------- |
| A      | Defer / reject — transporters are tenant Party records only |
| B      | Build Internal admin registry with governance               |

---

## PO-LGC-005 — Masters bulk import scope (BRK-M17, SHR-13)

Confirm entity list for first bulk slice:

- Recommended minimum: **Parties (transporter/consignee), Vehicles, Stations**
- Optional: Goods types, Drivers-as-Party

Confirm analyze/preview/commit UX parity with legacy.

---

## PO-LGC-006 — Hire settlement statement (BRK-FH07)

| Option | Description                                                    |
| ------ | -------------------------------------------------------------- |
| A      | Printable settlement PDF at hire show (Phase 6)                |
| B      | Registers + voucher list sufficient — Defer to Phase 11 report |

---

## PO-LGC-007 — Hire field update (BRK-FH04)

| Option | Description                                                                     |
| ------ | ------------------------------------------------------------------------------- |
| A      | Adjustments + unloading recalc only (current) — Intentional                     |
| B      | Add guarded `PATCH lorry-hires/{id}` for rate/weight/transporter pre-settlement |

---

## PO-LGC-008 — Driver advance & payroll (OF-A11, F01…F08)

**Context:** Not in Nexus PRD §11–12 core. Legacy attendance/payroll is large.

**Question:** Confirm **Phase 10 only** or **permanent exclude** from Nexus (customers stay on legacy for payroll).

---

## PO-LGC-009 — Production tax invoice (SHR-14, EX production PDF)

Unchanged from Phase 3 acceptance: **cannot** enable production tax PDF without issuer/GST PO approval. Impacts **E09/E12** migration messaging.

---

## PO-LGC-010 — AUD-CON-006 entitlement cells

Unchanged: exact trial/grace/restricted matrices. Engineering **must not** invent cells to close RBAC or dashboard gaps.

---

## PO-LGC-011…016 — Bilty UX / operational workspace (**ACCEPTED** 24 Nov 2026)

Explicit Product Owner authorization in the Bilty UI remediation assignment:

| ID         | Decision                                                                 | Status   |
| ---------- | ------------------------------------------------------------------------ | -------- |
| PO-LGC-011 | Optional Bilty No. (blank = auto); no Number Mode UI                     | Accepted |
| PO-LGC-012 | Loading Station / Unloading Station terminology                          | Accepted |
| PO-LGC-013 | Default freight rate type **Per MT** for new goods rows                  | Accepted |
| PO-LGC-014 | Wt./Unit client calculation assistance (Charged Weight independent)      | Accepted |
| PO-LGC-015 | Bilty Register is primary OwnFleet/Broker ops workspace                  | Accepted |
| PO-LGC-016 | Remove standalone OwnFleet Trips nav; keep Trip domain + historical URLs | Accepted |

---

## Existing Pending gates (do not duplicate decisions)

| Gate                         | Status                                                       |
| ---------------------------- | ------------------------------------------------------------ |
| PO-P4-CNV-CARDINALITY        | Pending in Phases.md — conversion exists; formal cardinality |
| ADR-010 accounting           | Pending — all BRK-A* deferred                                |
| PO-P3-PAY-PROD live Razorpay | Pending                                                      |
| PO-P7 Partload               | Not authorized                                               |

---

## Recommended PO response format

```text
PO-LGC-001: A | B
PO-LGC-002: A | B | C
PO-LGC-003: A | B | C
PO-LGC-004: A | B
PO-LGC-005: <entity list confirm>
PO-LGC-006: A | B
PO-LGC-007: A | B
PO-LGC-008: Phase 10 | Exclude
PO-LGC-009: (reference existing EX decision)
PO-LGC-010: (reference AUD-CON-006 process)
```

---

_After decisions: update `Memory.md`, gap register, and migration wave criteria in `LEGACY_PARITY_BUSINESS_IMPACT_REPORT.md`._
