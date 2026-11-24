# Legacy Parity — Customer Migration Business Impact Report

**Date:** 24 November 2026  
**Inputs:** `LEGACY_TO_NEXUS_FUNCTIONAL_PARITY_MATRIX.md` · `NEXUS_EXCLUSIVE_REQUIREMENTS_AUDIT.md`  
**Audience:** Product Owner, migration planning, customer success  
**Phase authority:** `docs/governing/Phases.md`

---

## Executive summary

Nexus **replaces three legacy applications** with one tenant-safe workspace. For **day-one Broker and OwnFleet operations** (LR → money movement → customer billing), parity is **largely Equivalent or Improved** with **no P0 blockers** on the inspected spine.

Customer migration impact clusters into four bands:

| Band                                     |                              Approx. capabilities | Customer experience                                                     |
| ---------------------------------------- | ------------------------------------------------: | ----------------------------------------------------------------------- |
| **A — Ready or better**                  |             ~100 (Equivalent + Improved on spine) | Train on unified LR/trips; expect stricter integrity                    |
| **B — Workarounds exist**                |                                       ~45 Partial | Lists/filters substitute for reports; cancel/recreate substitutes edits |
| **C — PO / export before targeted wave** | **0 Missing Current Scope**; PO-LGC + P11 exports | Voucher/invoice edit policy, bulk masters, bank payout export           |
| **D — Planned later phases**             |                                      ~90 Deferred | GL, fleet assets, payroll, full report library, expense import          |

**Partload** customers: no parity claim in this report (Phase 7 unauthorized). Direct Partload migrators to **hold** until Phase 7.

---

## Who is affected

| Legacy user profile                       | Nexus module         | Migration readiness                                             |
| ----------------------------------------- | -------------------- | --------------------------------------------------------------- |
| Broker-only operator                      | Branch Broker routes | **High** — core hire/voucher/delivery/invoice delivered         |
| OwnFleet dispatcher                       | Branch trips/LR      | **High** — LR-linked trips; active-trip guard + movement remove |
| Broker + OwnFleet (two logins)            | Single workspace     | **High** — unified navigation; retraining on LR-first OwnFleet  |
| Heavy diesel log import (OwnFleet)        | Trip expenses        | **Medium** — manual entry until C06                             |
| Treasury / accounts (NEFT file)           | Broker vouchers      | **Medium** — VP09 missing; manual bank portal                   |
| Accountant (Tally / GL)                   | Phase 8              | **Low until Phase 8** — intentional defer (ADR-010)             |
| Fleet maintenance / tyres / payroll       | Phase 10             | **Not in Nexus ops yet** — keep legacy or defer migration       |
| Management reporting (22+ Broker reports) | Phase 11             | **Partial** — registers hub + list filters; not Excel parity    |

---

## Band A — Ready or better (low migration friction)

**Broker chain:** LR create/edit → hire → voucher (create, bulk, daily) → memo/POD → adjustments → broker invoice → payments → linked bilty group pay.

**OwnFleet chain:** OwnFleet LR → trip → dispatch/complete → expenses → trip invoice → payments (with charge edit pre-payment).

**Platform wins (reduce support tickets after training):**

- One login / company switcher vs three apps
- Immutable invoice payments (fewer reconciliation disputes)
- TDS snapshots on hire (audit-friendly)
- Book balance ledger vs opaque negative advance hacks
- Customer single-session security (Customer users)

**Training topics:** Linked bilty created **after** hire (not at LR create); trips **only** from LR; no mid-trip vehicle swap; voucher correction via status workflow not legacy edit screen.

---

## Band B — Partial (workarounds, change management)

| Area               | Legacy habit                   | Nexus today                          | Migration messaging                                    |
| ------------------ | ------------------------------ | ------------------------------------ | ------------------------------------------------------ |
| Reports            | Dedicated Excel/PDF per report | Filtered lists + `/broker/registers` | “Operational registers first; formal reports Phase 11” |
| Hire edits         | Full FR update form            | Adjustments + unloading recalc       | Train adjustment types; FH04 gap for direct rate edit  |
| Broker invoice fix | Edit issued invoice            | Cancel + reissue                     | Document SOP until IN03                                |
| Voucher fix        | Edit/delete voucher            | Status transitions only              | Document SOP until VP03–VP04                           |
| Pickup/drop fix    | Full trip graph edit           | Re-enter or work around              | OF-B06; prioritize for dispatch-heavy fleets           |
| Pump vendor        | Dedicated pump master UI       | Party role + voucher reference       | Accept unified party model                             |
| Print/PDF          | Production tax PDF             | Sample print views                   | EX production PDF; legal dispatch blocked              |

---

## Band C — Fix before wave migration (business risk)

| Priority | Gap                               | Business risk if unmigrated customer lands       | Phase |
| -------- | --------------------------------- | ------------------------------------------------ | ----- |
| P1       | Bank payout file (BRK-VP09)       | Manual NEFT/RTGS entry; error-prone at volume    | 6     |
| P1       | Voucher amend/void (BRK-VP03/04)  | Ops cannot mirror legacy correction SOP          | 6     |
| P1       | Broker invoice edit (BRK-IN03)    | Billing team rework via cancel-only              | 6     |
| P1       | Trip overlap guard (OF-B15)       | Double-booked vehicle risk                       | 5     |
| P1       | Expense bulk import (OF-C06)      | Fleet admins blocked on diesel spreadsheets      | 5     |
| P2       | Masters bulk CSV (BRK-M17/SHR-13) | Slow cutover for large master datasets           | 3     |
| P2       | Hire settlement PDF (BRK-FH07)    | Transporter disputes without printable statement | 6     |
| PO       | Custom broker invoice (BRK-IN06)  | Customers using manual-line invoices need policy | 6     |

**Custom invoice (IN06):** If PO chooses **not** to port, migrate customers to strict LR-linked invoices before cutover.

---

## Band D — Defer (do not block Nexus go-live for ops spine)

| Module                               | Legacy capability volume      | Nexus phase | Customer expectation                        |
| ------------------------------------ | ----------------------------- | ----------- | ------------------------------------------- |
| GL / Tally / GSTR                    | BRK-A01…A13                   | 8           | “Accounting in Nexus Phase 8”               |
| Broker financial reports             | BRK-R02,R04,R10,R16 + exports | 11          | Keep legacy read-only or exports until P11  |
| OwnFleet tyres/batteries/maintenance | OF-G,H,I (~30)                | 10          | Continue legacy asset module or spreadsheet |
| Attendance / payroll                 | OF-F01…F12                    | 10 / PO     | Not PRD §11–12 core                         |
| Notifications centre                 | OF-M06, legacy jobs           | 9           | No in-app notification parity yet           |
| Partload                             | Not in this audit             | 7           | **No migration**                            |

Reclassified **BRK-R19/R20** (transporter dump, voucher receipt export) to **Band D** — not current-scope blockers.

---

## Migration waves (recommended)

| Wave                  | Profile                                     | Preconditions                                                    |
| --------------------- | ------------------------------------------- | ---------------------------------------------------------------- |
| **W0 — Pilot**        | Single-branch Broker, low report dependency | Band C Broker voucher/invoice fixes **or** signed workaround SOP |
| **W1 — Broker scale** | Multi-branch Broker, daily treasury         | **VP09** + VP03/04 + IN03; masters bulk for large party sets     |
| **W2 — OwnFleet ops** | LR/trip/diesel heavy                        | **B15** + **C06**; tax PDF exception acknowledged                |
| **W3 — Unified**      | Broker + OwnFleet same company              | W0–W2 + unified invoice list training                            |
| **Hold**              | Partload, payroll-heavy OF, GL-dependent    | Phase 7/8/10/11                                                  |

---

## Data migration note (Phase 11)

This report addresses **functional parity**, not ETL. Legacy migration per `Phases.md` §11 runs **only after** workflows certified. Band C fixes reduce **parallel running** duration.

---

## Metrics for PO tracking

| Metric                                    | Value |
| ----------------------------------------- | ----: |
| Grand parity rows                         |   268 |
| Equivalent + Improved (spine-weighted)    |   100 |
| Missing Current Scope (unique behaviours) |     9 |
| Deferred (intentional phase boundary)     |    88 |
| PO Decision rows                          |     3 |
| Intentional redesigns (training)          |    12 |

---

_For engineering remediation priorities see `docs/engineering/PHASE_1_6_CONSOLIDATED_GAP_REGISTER.md`._
