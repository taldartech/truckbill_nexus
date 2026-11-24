# Legacy → Nexus Functional Parity Matrix (Merged)

**Audit date:** 24 November 2026 (expanded pass — all four legacy apps)  
**Sources merged:** `LEGACY_BROKER_FUNCTIONAL_PARITY.md` (116) · `LEGACY_OWNFLEET_FUNCTIONAL_PARITY.md` (138) · `LEGACY_*_WORKFLOW_ALIGNMENT.md` · `LEGACY_PARTLOAD_WORKFLOW_ANALYSIS.md` (PLD-20) · `LEGACY_ADMIN_WORKFLOW_ALIGNMENT.md` (ADM-18) · SHR (14)  
**Legacy read-only paths (Nexus repo):** `legacy/truckbill_broker` · `legacy/truckbill_ownfleet` · `legacy/truckbill_admin` · `legacy/truckbill_partload`  
**Legacy SHAs:** broker `6a90e955…` (stable) · ownfleet `9423293e…` (development) · admin `b951db33…` (main) · partload `fb9b955e…` (account)  
**Nexus HEAD:** `e05d1d6f505ac076963ede60b86e4ebe062e2edb`  
**Phase authority:** `docs/governing/Phases.md`  
**ID scheme:** `BRK-*` · `OF-*` · `SHR-*` · `ADM-*` · `PLD-*`  
**Weight standard:** `docs/architecture/OPERATIONAL_WEIGHT_UNIT_STANDARD.md`

### Operational-flow parity (PO-LGC-017 — 24 November 2026)

**PO-LGC-017 (Accepted):** Legacy broker is the primary reference for **operator interaction and operational flow** when implementing or certifying Broker UX. Nexus domain, security, and financial rules remain authoritative.

This matrix still classifies **capabilities** (BRK-* / SHR-* rows). It does **not** assign an overall “% parity” score. Operational-flow alignment is tracked separately in:

- `docs/design/BROKER_OPERATIONAL_FLOW_STANDARD.md`
- `docs/design/LEGACY_BROKER_SCREEN_MAPPING.md`
- `docs/engineering/BATCH_2_BROKER_GAP_REGISTER.md` (B2-DOC-01)

Batch 2 UX landings under PO-LGC-017 are **implementation evidence**, not a change to peer BRK classification totals in this file unless a future audit pass reclassifies specific BRK rows.

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

### Prior broker report deferrals

Peer Broker audit marked **R19**, **R20** as Missing Current Scope (Phase 11 report exports). Per `Phases.md` §11, operational/financial **report reproduction** is Phase 11 — reclassified **Deferred Future Phase** in this matrix (does not change Broker spine counts in source doc).

| Original ID | Peer classification   | **Merged classification** | Rationale                                       |
| ----------- | --------------------- | ------------------------- | ----------------------------------------------- |
| BRK-R19     | Missing Current Scope | **Deferred Future Phase** | Transporter master **export report** — Phase 11 |
| BRK-R20     | Missing Current Scope | **Deferred Future Phase** | Voucher receipt export — Phase 11               |

**Adjusted Broker Missing Current Scope:** 9 → **7** (M16, M17, FH07, VP03, VP04, VP09, IN03).

### Overstated **Equivalent** corrections (24 Nov expanded pass)

Workflow alignment inspection (`legacy/*` under Nexus repo) downgraded rows where domain logic exists but **operator workflow** (party pickers, weight UX, hire edit depth, Partload catalogues) is not yet comparable.

| ID       | Was        | **Now**      | Evidence                                                               |
| -------- | ---------- | ------------ | ---------------------------------------------------------------------- |
| SHR-04   | Equivalent | **Partial**  | Partload `PackageType`, `Group`, `Agent` masters absent in Nexus       |
| SHR-11   | Improved   | **Partial**  | Legacy admin onboarding/offline subscription desk not in Nexus CRM     |
| BRK-LR02 | Equivalent | **Improved** | Batch 1: role-filtered searchable parties + MT weight UX on create     |
| BRK-LR03 | Equivalent | **Partial**  | MT/searchable on edit shipped; financial freeze vs legacy edit remains |
| BRK-FH02 | Equivalent | **Partial**  | No `FreightBookingController::update` parity                           |
| BRK-M09  | Equivalent | **Partial**  | Vehicle CRUD OK; driver register bulk absent                           |
| OF-B02   | Equivalent | **Partial**  | Legacy `TripFormRedesigned.vue` multi-leg UX deeper                    |
| OF-B18   | Equivalent | **Partial**  | Legacy `weight_ton` leg calc vs Nexus allocations                      |
| OF-E05   | Equivalent | **Partial**  | Trip invoice path — Not Verified E2E                                   |

**Broker Equivalent count (peer §1–9):** 33 → **29** after LR02/LR03/FH02/M09 reclass (peer doc counts unchanged; merged matrix uses revised workflow truth).

**Batch 1 closed** (`03bdae1`…`0765acc`): party selector eligibility, LR searchable parties, MT weight UX, Pint legacy exclude — see `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`, `PARTY_SELECTOR_ELIGIBILITY_CERTIFICATION.md`.

---

## A. Shared cross-app capabilities (`SHR-*`)

| ID     | Capability                                  | Legacy apps               | Nexus                                   | Classification        | Sev | Phase |
| ------ | ------------------------------------------- | ------------------------- | --------------------------------------- | --------------------- | --- | ----- |
| SHR-01 | Single company workspace (no module picker) | 3 apps                    | Unified Inertia app                     | Improved              | P3  | 1–2   |
| SHR-02 | Unified Party master + roles                | Per-app masters           | `PartyController`                       | Improved              | P3  | 3     |
| SHR-03 | Unified LR (workflow context)               | Broker bilty; OF embedded | `LorryReceiptController` + context enum | Improved              | P2  | 4–6   |
| SHR-04 | Station / goods / truck type catalogues     | Duplicated masters        | Owner + Branch catalogues               | **Partial**           | P3  | 3/7   |
| SHR-05 | Document upload / replace / audit           | Per-app attachments       | `DocumentAttachmentService`             | Improved              | P2  | 3/5/6 |
| SHR-06 | Platform subscription / entitlement         | Per-app Razorpay gate     | Owner subscription + resolver           | Improved              | P2  | 3     |
| SHR-07 | Branch tenant isolation                     | Per-app company_id        | `TenantContext` + policies              | Improved              | P1  | 1–2   |
| SHR-08 | Decimal-safe money (bcmath)                 | Float helpers             | Domain services                         | Improved              | P1  | 5–6   |
| SHR-09 | E-way bill on consignment                   | Split across apps         | LR `ewayBills` primary                  | Improved              | P3  | 4     |
| SHR-10 | Unified customer invoice list               | N/A (isolated)            | `UnifiedCustomerInvoiceReadFacade`      | Improved              | P3  | 5–6   |
| SHR-11 | Internal CRM (leads)                        | `truckbill_admin`         | Nexus `/admin/crm/*`                    | Partial               | P2  | 2A    |
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

## D. Admin control plane (`ADM-*`)

_Full workflow: `LEGACY_ADMIN_WORKFLOW_ALIGNMENT.md`. Phase 2A / platform — not customer Broker/OF/Partload modules._

| Classification         |  Count |
| ---------------------- | -----: |
| Equivalent             |      0 |
| Improved               |      4 |
| Partial                |     10 |
| Missing                |      3 |
| Deferred Future Phase  |      0 |
| Intentional Difference |      2 |
| PO Decision Required   |      0 |
| Not Verified           |      1 |
| Deprecated             |      0 |
| **Total ADM**          | **18** |

---

## E. Partload (`PLD-*`) — Phase 7 unauthorized

_Full analysis: `LEGACY_PARTLOAD_WORKFLOW_ANALYSIS.md`. Readiness: `PHASE_7_LEGACY_PARTLOAD_READINESS.md`._

| Classification         |  Count |
| ---------------------- | -----: |
| Equivalent             |      0 |
| Improved               |      1 |
| Partial                |      6 |
| Missing                |     10 |
| Deferred Future Phase  |      2 |
| Intentional Difference |      1 |
| PO Decision Required   |      0 |
| Not Verified           |      1 |
| Deprecated             |      0 |
| **Total PLD**          | **20** |

---

## F. Grand totals (306 rows) — four-app merge

Broker/OF peer registers (254) + SHR (14) + ADM (18) + PLD (20). SHR-04/SHR-11 reclass applied in section A.

| Classification         | BRK §1–9† |     OF† |    SHR |    ADM |    PLD | **Grand total** |
| ---------------------- | --------: | ------: | -----: | -----: | -----: | --------------: |
| Equivalent             |    29→33‡ |  30→33‡ |      0 |      0 |      0 |      **62–67**‡ |
| Improved               |        16 |      10 |     10 |      4 |      1 |          **41** |
| Partial                |    33→29‡ |  18→15‡ |      2 |     10 |      6 |      **64–70**‡ |
| Missing                |         0 |       0 |      0 |      3 |     10 |          **13** |
| Missing Current Scope  |         0 |       0 |      0 |      0 |      0 |           **0** |
| Deferred Future Phase  |        21 |      68 |      1 |      0 |      2 |          **92** |
| Intentional Difference |         6 |       6 |      0 |      2 |      1 |          **15** |
| Deprecated             |         1 |       2 |      0 |      0 |      0 |           **3** |
| PO Decision            |         7 |       2 |      1 |      0 |      0 |          **10** |
| Not Verified           |         2 |       2 |      0 |      1 |      1 |           **6** |
| **Subtotal**           |   **116** | **138** | **14** | **18** | **20** |         **306** |

† BRK/OF columns show **workflow-adjusted Equivalent/Partial** (first number) vs **peer register** (second) where they differ.  
‡ Use **62 Equivalent / 70 Partial** for migration planning when applying workflow downgrades; peer BRK/OF CSV indexes remain authoritative for row text.

_Exclude BRK-P01…P06 (6) from migration parity math — same treatment as peer Broker §10._

**Migration-relevant total (306 − 6):** **300** capability rows (includes Partload as explicit Missing majority until PO-P7-AUTH).

---

## G. Phase mapping rollup (legacy presence → Nexus phase)

| Phase      | BRK |  OF | SHR | ADM | PLD | Notes                             |
| ---------- | --: | --: | --: | --: | --: | --------------------------------- |
| 2A / admin |   0 |   0 |   1 |  18 |   0 | CRM + subscription control plane  |
| 3          |  12 |   8 |   4 |   0 |   4 | Masters; Partload agents/packages |
| 4          |   8 |   4 |   2 |   0 |   2 | Unified LR; enquiry partload      |
| 5          |   2 |  52 |   2 |   0 |   0 | OF core + deferred fleet          |
| 6          |  78 |   4 |   1 |   0 |   0 | Broker spine                      |
| 7          |   0 |   0 |   0 |   0 |  20 | Partload challan/inward/delivery  |
| 8          |  13 |   0 |   0 |   0 |   2 | GL / accounting defer             |
| 9          |   1 |   2 |   0 |   0 |   0 | Notifications                     |
| 10         |   1 |  58 |   0 |   0 |   0 | Assets / payroll                  |
| 11         |  24 |  54 |   1 |   0 |   2 | Reports                           |
| PO / mixed |   2 |   8 |   1 |   0 |   0 | Decisions                         |

---

## H. Former P1 Missing Current Scope — disposition

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

## I. Index pointer

| App      | Workflow alignment                      | Row register                           |
| -------- | --------------------------------------- | -------------------------------------- |
| Broker   | `LEGACY_BROKER_WORKFLOW_ALIGNMENT.md`   | `LEGACY_BROKER_FUNCTIONAL_PARITY.md`   |
| OwnFleet | `LEGACY_OWNFLEET_WORKFLOW_ALIGNMENT.md` | `LEGACY_OWNFLEET_FUNCTIONAL_PARITY.md` |
| Admin    | `LEGACY_ADMIN_WORKFLOW_ALIGNMENT.md`    | ADM-* in this matrix §D                |
| Partload | `LEGACY_PARTLOAD_WORKFLOW_ANALYSIS.md`  | PLD-* in this matrix §E                |

Weight / freight: `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`.  
Phase 7 readiness: `PHASE_7_LEGACY_PARTLOAD_READINESS.md`.  
Nexus-only requirements: `NEXUS_EXCLUSIVE_REQUIREMENTS_AUDIT.md`.

---

_Merged matrix for Phase 6 residual planning and Phase 11 report backlog. Does not supersede PO acceptance records._
