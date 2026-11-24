# Legacy → Nexus Functional Parity Matrix (Merged)

**Audit date:** 24 November 2026  
**Sources merged:** `LEGACY_BROKER_FUNCTIONAL_PARITY.md` (116 rows) · `LEGACY_OWNFLEET_FUNCTIONAL_PARITY.md` (138 rows) · shared cross-app rows (14 rows)  
**Nexus HEAD:** `3aa5bfe5970721d3fff5632e6a1c62b23c456784`  
**Phase authority:** `docs/governing/Phases.md`  
**ID scheme:** `BRK-*` Broker · `OF-*` OwnFleet · `SHR-*` Shared (deduplicated once)

---

## Classification legend

| Classification         | Meaning                                      |
| ---------------------- | -------------------------------------------- |
| Equivalent             | Same business outcome                        |
| Improved               | Stricter or better Nexus design              |
| Partial                | Core exists; material sub-feature missing    |
| Missing Current Scope  | Approved current-phase scope not implemented |
| Deferred Future Phase  | Phase 7–11 / ADR / explicit deferral         |
| Intentional Difference | Approved redesign; not a defect              |
| Deprecated             | Must not port                                |
| PO Decision            | Policy gate open                             |
| Not Verified           | Not re-tested this pass                      |

---

## Reconciliation adjustments (this merge)

Peer Broker audit marked **R19**, **R20** as Missing Current Scope (Phase 11 report exports). Per `Phases.md` §11, operational/financial **report reproduction** is Phase 11 — reclassified **Deferred Future Phase** in this matrix (does not change Broker spine counts in source doc).

| Original ID | Peer classification   | **Merged classification** | Rationale                                       |
| ----------- | --------------------- | ------------------------- | ----------------------------------------------- |
| BRK-R19     | Missing Current Scope | **Deferred Future Phase** | Transporter master **export report** — Phase 11 |
| BRK-R20     | Missing Current Scope | **Deferred Future Phase** | Voucher receipt export — Phase 11               |

**Adjusted Broker Missing Current Scope:** 9 → **7** (M16, M17, FH07, VP03, VP04, VP09, IN03).

---

## A. Shared cross-app capabilities (`SHR-*`)

| ID     | Capability                                  | Legacy apps               | Nexus                                   | Classification        | Sev | Phase |
| ------ | ------------------------------------------- | ------------------------- | --------------------------------------- | --------------------- | --- | ----- |
| SHR-01 | Single company workspace (no module picker) | 3 apps                    | Unified Inertia app                     | Improved              | P3  | 1–2   |
| SHR-02 | Unified Party master + roles                | Per-app masters           | `PartyController`                       | Improved              | P3  | 3     |
| SHR-03 | Unified LR (workflow context)               | Broker bilty; OF embedded | `LorryReceiptController` + context enum | Improved              | P2  | 4–6   |
| SHR-04 | Station / goods / truck type catalogues     | Duplicated masters        | Owner + Branch catalogues               | Equivalent            | P3  | 3     |
| SHR-05 | Document upload / replace / audit           | Per-app attachments       | `DocumentAttachmentService`             | Improved              | P2  | 3/5/6 |
| SHR-06 | Platform subscription / entitlement         | Per-app Razorpay gate     | Owner subscription + resolver           | Improved              | P2  | 3     |
| SHR-07 | Branch tenant isolation                     | Per-app company_id        | `TenantContext` + policies              | Improved              | P1  | 1–2   |
| SHR-08 | Decimal-safe money (bcmath)                 | Float helpers             | Domain services                         | Improved              | P1  | 5–6   |
| SHR-09 | E-way bill on consignment                   | Split across apps         | LR `ewayBills` primary                  | Improved              | P3  | 4     |
| SHR-10 | Unified customer invoice list               | N/A (isolated)            | `UnifiedCustomerInvoiceReadFacade`      | Improved              | P3  | 5–6   |
| SHR-11 | Internal CRM (leads)                        | `truckbill_admin`         | Nexus `/admin/crm/*`                    | Improved              | P2  | 2A    |
| SHR-12 | Permission Master (not legacy role names)   | `role:moderator` etc.     | Permission codes                        | Improved              | P3  | 2     |
| SHR-13 | Masters bulk CSV import                     | All three apps            | Scope gated — **PO-LGC-005**            | PO Decision           | P2  | 3     |
| SHR-14 | Production tax invoice PDF                  | Legacy PDF tests          | Sample only; EX pending                 | Deferred Future Phase | P1  | 3/11  |

---

## B. Broker capabilities (`BRK-*`)

_Full row text and evidence: `LEGACY_BROKER_FUNCTIONAL_PARITY.md`. IDs preserved with `BRK-` prefix._

| Domain                               | ID range      | Row count                         |
| ------------------------------------ | ------------- | --------------------------------- |
| Masters                              | BRK-M01…M20   | 20                                |
| LR                                   | BRK-LR01…LR12 | 12                                |
| Hire                                 | BRK-FH01…FH15 | 15                                |
| Vouchers                             | BRK-VP01…VP12 | 12                                |
| Memo/POD                             | BRK-MP01…MP06 | 6                                 |
| Invoices                             | BRK-IN01…IN10 | 10                                |
| Linked bilty                         | BRK-LB01…LB05 | 5                                 |
| Reports                              | BRK-R01…R22   | 22                                |
| Accounting                           | BRK-A01…A14   | 14                                |
| Platform (counted in broker doc §10) | BRK-P01…P06   | 6 _(excluded from parity totals)_ |

**Broker §1–9 classification totals (peer audit, Missing adjusted):**

| Classification         | Peer count | **Merged count** |
| ---------------------- | ---------: | ---------------: |
| Equivalent             |         33 |               33 |
| Improved               |         16 |               16 |
| Partial                |         29 |               29 |
| Missing Current Scope  |          9 |            **7** |
| Deferred Future Phase  |         18 |           **20** |
| Intentional Difference |          6 |                6 |
| Deprecated             |          1 |                1 |
| PO Decision            |          1 |                1 |
| Not Verified           |          2 |                2 |
| **Total**              |    **116** |          **116** |

---

## C. OwnFleet capabilities (`OF-*`)

_Full register: `LEGACY_OWNFLEET_FUNCTIONAL_PARITY.md`. IDs prefixed `OF-`._

| Section                | ID range   | Row count |
| ---------------------- | ---------- | --------- |
| Vehicle & Driver       | OF-A01…A14 | 14        |
| Trips & LR             | OF-B01…B22 | 22        |
| Trip expenses          | OF-C01…C09 | 9         |
| POD & docs             | OF-D01…D06 | 6         |
| Invoicing              | OF-E01…E14 | 14        |
| Attendance/payroll     | OF-F01…F12 | 12        |
| Tyres                  | OF-G01…G14 | 14        |
| Batteries              | OF-H01…H08 | 8         |
| Maintenance            | OF-I01…I08 | 8         |
| Compliance             | OF-J01…J10 | 10        |
| Monthly fleet expenses | OF-K01…K05 | 5         |
| Operational reports    | OF-L01…L08 | 8         |
| Platform/shell         | OF-M01…M08 | 8         |

**OwnFleet classification totals (post-`3aa5bfe` + C06 reclass):**

| Classification         |   Count |
| ---------------------- | ------: |
| Equivalent             |      33 |
| Improved               |      10 |
| Partial                |      15 |
| Missing Current Scope  |       0 |
| Deferred Future Phase  |      68 |
| Intentional Difference |       6 |
| Deprecated             |       2 |
| PO Decision            |       2 |
| Not Verified           |       2 |
| **Total**              | **138** |

---

## D. Grand totals (268 rows) — post-remediation + challenge

Broker Missing rows reclassified: VP03/VP04/IN03/M17 → **PO Decision**; VP09/FH07 → **Deferred (P11 / PO-LGC-006)**; M16 already PO. SHR-13 → PO. OF B06/B15 closed; C06 → Deferred.

| Classification         | BRK §1–9 |      OF |    SHR | **Grand total** |
| ---------------------- | -------: | ------: | -----: | --------------: |
| Equivalent             |       33 |      33 |      1 |          **67** |
| Improved               |       16 |      10 |     11 |          **37** |
| Partial                |       29 |      15 |      0 |          **44** |
| Missing Current Scope  |        0 |       0 |      0 |           **0** |
| Deferred Future Phase  |       21 |      68 |      1 |          **90** |
| Intentional Difference |        6 |       6 |      0 |          **12** |
| Deprecated             |        1 |       2 |      0 |           **3** |
| PO Decision            |        7 |       2 |      1 |          **10** |
| Not Verified           |        2 |       2 |      0 |           **4** |
| **Subtotal**           |  **116** | **138** | **14** |         **268** |

_Exclude BRK-P01…P06 (6) from migration parity math — same treatment as peer Broker §10._

**Migration-relevant total (268 − 6 deprecated/platform broker context):** **262** capability rows for customer impact planning.

---

## E. Phase mapping rollup (legacy presence → Nexus phase)

| Phase      | BRK rows (indicative) | OF rows (indicative) | SHR | Notes                            |
| ---------- | --------------------: | -------------------: | --: | -------------------------------- |
| 3          |                    12 |                    8 |   4 | Masters, bulk import gap         |
| 4          |                     8 |                    4 |   3 | Unified LR                       |
| 5          |                     2 |                   52 |   2 | OF core + deferred fleet modules |
| 6          |                    78 |                    4 |   2 | Broker spine                     |
| 8          |                    13 |                    0 |   0 | GL / accounting defer            |
| 9          |                     1 |                    2 |   0 | Notifications                    |
| 10         |                     1 |                   58 |   0 | Assets / payroll                 |
| 11         |                    24 |                   54 |   1 | Reports                          |
| PO / mixed |                     2 |                    8 |   2 | Decisions                        |

---

## F. Former P1 Missing Current Scope — disposition

| ID       | Capability                           | Disposition                       |
| -------- | ------------------------------------ | --------------------------------- |
| OF-B15   | Vehicle active-trip guard            | **Closed** Improved — `3aa5bfe`   |
| OF-B06   | Pickup/drop remove                   | **Closed** Equivalent — `3aa5bfe` |
| OF-C06   | Trip expense bulk import             | **Deferred** Phase 11 import pack |
| BRK-VP09 | Bank payout export                   | **Deferred** Phase 11             |
| BRK-VP03 | Voucher edit                         | **PO-LGC-003**                    |
| BRK-VP04 | Voucher delete/void                  | **PO-LGC-003**                    |
| BRK-IN03 | Broker invoice edit                  | **PO-LGC-002**                    |
| BRK-FH07 | Hire settlement statement PDF        | **PO-LGC-006**                    |
| BRK-M17  | Masters bulk CSV                     | **PO-LGC-005**                    |
| SHR-13   | Masters bulk CSV (cross-app)         | **PO-LGC-005** (same)             |
| BRK-M16  | Platform transporter master registry | **PO-LGC-004**                    |

**Open Missing Current Scope (Phases 1–6):** **none**.

---

## G. Index pointer

Broker CSV-style index: peer doc § Capability index.  
OwnFleet register: peer doc § Capability register.  
Nexus-only requirements: `NEXUS_EXCLUSIVE_REQUIREMENTS_AUDIT.md`.

---

_Merged matrix for Phase 6 residual planning and Phase 11 report backlog. Does not supersede PO acceptance records._
