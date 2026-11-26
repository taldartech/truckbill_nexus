# Phase 1–6 Consolidated Gap Register

**Date:** 24 November 2026  
**Nexus HEAD (register tip):** `3aa5bfe5970721d3fff5632e6a1c62b23c456784`  
**Authority:** `docs/governing/Phases.md` · PO Phase 1–6 authorizations  
**Inputs:** Legacy parity audits · merged matrix · Nexus-exclusive audit · `PHASE_5_6_FINAL_GAP_REGISTER.md`

## Purpose

Single engineering register for gaps affecting **Phases 1–6**, with mandatory challenge of misclassified "Missing Current Scope" items that belong to **Phase 8/10/11**, **PO policy**, or **intentional immutability**.

### Bucket definitions

| Bucket                                  | Meaning                                                    |
| --------------------------------------- | ---------------------------------------------------------- |
| **A — Missing Current Scope (fix now)** | Approved Phase 1–6; P0/P1; implement before migration wave |
| **B — Deferred**                        | Later phase or EX/ADR gate                                 |
| **C — Intentional**                     | Approved redesign; document/train only                     |
| **D — PO Decision**                     | Blocked on policy                                          |

---

## Reclassification log (critical review)

| Item                                     | Was classified          | **Register bucket**     | Reason                                                                  |
| ---------------------------------------- | ----------------------- | ----------------------- | ----------------------------------------------------------------------- |
| BRK-R19 Transporter master export report | Missing Current Scope   | **B Deferred (P11)**    | Report surface; Phases.md §11                                           |
| BRK-R20 Voucher receipt export           | Missing Current Scope   | **B Deferred (P11)**    | Report/export library                                                   |
| BRK-A01…A13 GL/Tally                     | Missing in legacy sense | **B Deferred (P8)**     | ADR-010 Pending                                                         |
| BRK-IN10 Production tax invoice          | Deferred in peer        | **B Deferred (EX)**     | Phase 3 exception                                                       |
| OF-F01…F12 Payroll                       | Missing                 | **B Deferred (P10/PO)** | Not PRD §11–12                                                          |
| OF-G,H,I fleet assets                    | Missing                 | **B Deferred (P10)**    | Phases.md §10                                                           |
| OF-L*, K* fleet reports                  | Missing                 | **B Deferred (P11)**    | Reports after workflows                                                 |
| OF-E08 Payment destroy                   | Missing                 | **C Intentional**       | PRD payment immutability                                                |
| BRK-IN06 Custom invoice                  | PO Decision             | **D PO**                | Policy branch                                                           |
| BRK-M16 Platform transporter master      | Missing                 | **D PO**                | Cross-tenant scope unclear                                              |
| NX-67 / OF-E08                           | N/A                     | **C Intentional**       | No payment delete by design                                             |
| AUD-CON-006                              | Partial entitlements    | **D PO**                | Do not invent matrix                                                    |
| **OF-C06** expense bulk import           | Missing Current Scope   | **B Deferred**          | Not in Phase 5 exit criteria; import pack post-spine                    |
| **BRK-VP09** bank payout export          | Missing Current Scope   | **B Deferred (P11)**    | Export workbook — Phase 11; daily payments + vouchers cover W0 treasury |
| **BRK-VP03 / VP04** voucher edit/void    | Missing Current Scope   | **D PO**                | PO-LGC-003; do not restore unsafe mutation                              |
| **BRK-IN03** invoice edit                | Missing Current Scope   | **D PO**                | PO-LGC-002; cancel+reissue is current                                   |
| **BRK-M17 / SHR-13** masters bulk CSV    | Missing Current Scope   | **D PO**                | PO-LGC-005 entity list before build                                     |
| **BRK-FH07** hire settlement PDF         | Missing Current Scope   | **D PO**                | PO-LGC-006 print vs Phase 11                                            |
| **OF-B15** vehicle active-trip guard     | Missing Current Scope   | **Closed → Improved**   | `3aa5bfe` Rules §9 one active trip                                      |
| **OF-B06** pickup/drop remove            | Partial                 | **Closed → Equivalent** | `3aa5bfe` DELETE + cascade; re-add for field correction                 |

**Net Missing Current Scope after challenge + remediation:** **0** unique open behaviours in Bucket A.

---

## Bucket A — Fix now (P0 / P1)

| Reg ID  | Source ID | Gap                                      | Phase | Sev | Status                                                                    |
| ------- | --------- | ---------------------------------------- | ----- | --- | ------------------------------------------------------------------------- |
| G16-A01 | OF-B15    | Vehicle trip overlap / active-trip guard | 5     | P1  | **Closed** `3aa5bfe` — `TripService::assertVehicleHasNoActiveTrip` + Pest |
| G16-A09 | OF-B06    | Pickup/drop remove pre-dispatch          | 5     | P1  | **Closed** `3aa5bfe` — `removePickup` / `removeDrop` + UI + Pest          |
| G16-A02 | OF-C06    | Trip expense bulk import                 | 5     | P1  | **Reclassified → B**                                                      |
| G16-A03 | BRK-VP09  | Bank payout export file                  | 6     | P1  | **Reclassified → B (P11)**                                                |
| G16-A04 | BRK-VP03  | Voucher edit                             | 6     | P1  | **Reclassified → D** PO-LGC-003                                           |
| G16-A05 | BRK-VP04  | Voucher void                             | 6     | P1  | **Reclassified → D** PO-LGC-003                                           |
| G16-A06 | BRK-IN03  | Broker invoice edit                      | 6     | P1  | **Reclassified → D** PO-LGC-002                                           |
| G16-A07 | BRK-M17   | Masters bulk CSV                         | 3     | P2  | **Reclassified → D** PO-LGC-005                                           |
| G16-A08 | BRK-FH07  | Hire settlement PDF                      | 6     | P2  | **Reclassified → D** PO-LGC-006                                           |

**P0:** None open on inspected LR→money spine. Residual P0 risk = **external** (live Razorpay, production tax) → Bucket B.

**Open Bucket A items:** **none**.

---

## Bucket B — Deferred (do not schedule in Phase 1–6 closure)

### Phase 8

All BRK-A01…A13, party ledger financial report BRK-R04, auto-posting.

### Phase 9

Notification centre, document alert jobs, invoice due reminders (OF-J09, OF-D04, BRK-P04).

### Phase 10

OwnFleet attendance, tyres, batteries, maintenance, monthly overhead (67+ OF rows), driver license expiry list (OF-A09), compliance EMI/challan (OF-J04,J05).

### Phase 11

Broker reports BRK-R01…R21 (except hub substitutes), OF-L01…L08, tyre/maintenance reports, BRK-R19/R20, **BRK-VP09 bank payout export**, OF-C06 expense bulk import pack, quick search (OF-M05).

### Phase 3 EX / external

Production tax PDF (SHR-14), live Razorpay (PO-P3-PAY-PROD), ADR-010, k6 5k VU objective (NX-09).

### Phase 7

Entire Partload legacy surface — unauthorized.

---

## Bucket C — Intentional (no code port)

| Theme                                  | IDs (sample)                      |
| -------------------------------------- | --------------------------------- |
| Unified workspace                      | SHR-01, BRK-P02                   |
| Party/LR redesign                      | BRK-M08,M11, LR07; OF-A07,B01,B18 |
| Trip immutability                      | OF-B13 archive/restore            |
| Payment immutability                   | OF-E08, NX-67                     |
| No float / no legacy subscription      | OF-M08, BRK-M20                   |
| LR Delivered not auto on trip complete | NX-72                             |

**Action:** SOP + training only.

---

## Bucket D — PO Decision (blocked)

| Reg ID  | Source                | Question                                      |
| ------- | --------------------- | --------------------------------------------- |
| G16-D01 | BRK-IN06              | Custom manual broker invoice — port or reject |
| G16-D02 | BRK-M16               | Platform transporter master registry          |
| G16-D03 | AUD-CON-006           | Entitlement trial/grace/restricted cells      |
| G16-D04 | OF-A11,F08            | Payroll / driver advance in Nexus scope       |
| G16-D05 | PO-P4-CNV-CARDINALITY | Formal cardinality (implementation exists)    |
| G16-D06 | BRK-VP03/VP04         | Voucher edit/void policy (PO-LGC-003)         |
| G16-D07 | BRK-IN03              | Invoice edit vs cancel+reissue (PO-LGC-002)   |
| G16-D08 | BRK-M17 / SHR-13      | Masters bulk entity list (PO-LGC-005)         |
| G16-D09 | BRK-FH07              | Hire settlement PDF now vs P11 (PO-LGC-006)   |
| G16-D10 | BRK-FH04              | Direct hire PATCH (PO-LGC-007)                |

See `docs/implementation/PRODUCT_OWNER_DECISIONS_REQUIRED.md` PO-LGC-*.

---

## Bucket A — Partial (polish / gated)

| Reg ID  | Source     | Gap                              | Sev | Recommended                   |
| ------- | ---------- | -------------------------------- | --- | ----------------------------- |
| G16-P01 | BRK-FH04   | Direct hire PATCH                | P1  | PO-LGC-007 then guarded PATCH |
| G16-P02 | BRK-IN04   | Cancel vs destroy semantics      | P2  | Align copy + Rules            |
| G16-P03 | OF-E09,E12 | Sample invoice PDF / GST display | P1  | Blocked on PO-LGC-009         |
| G16-P04 | NX-08      | Playwright CI matrix             | P2  | Env-gated expansion (P56-012) |
| G16-P05 | NX-42      | Entitlement resolver edge cells  | P2  | After AUD-CON-006             |

---

## Classification totals (engineering view)

| Bucket                     |        Count (trackable items) |
| -------------------------- | -----------------------------: |
| A — Missing / fix now      |                     **0 open** |
| A — Closed this pass       |                          **2** |
| A-partial — Partial polish |                          **5** |
| B — Deferred               | **90+** parity rows + EX gates |
| C — Intentional            |   **18** parity rows (+ NX-72) |
| D — PO Decision            |           **10** register rows |

**Merged parity grand totals after remediation delta (268 rows):** see `LEGACY_TO_NEXUS_FUNCTIONAL_PARITY_MATRIX.md` §D (post-`3aa5bfe`).

---

## Certification residuals (honest)

| ID      | Residual                                          | Status                                                                             |
| ------- | ------------------------------------------------- | ---------------------------------------------------------------------------------- |
| P56-009 | Full MySQL Feature Pest beyond CI slice           | Local disposable cert executed this program                                        |
| P56-010 | k6 beyond smoke / 5k VU                           | **Not certified** — staging plan required                                          |
| P56-011 | Lorry Hire Active status                          | **Deferred** — no activation rule                                                  |
| P56-012 | Eleven-persona **browser** matrix                 | **Partial** — Pest HTTP strong; browser env                                        |
| P56-013 | GitHub Actions green on tip                       | Local Larastan fixed `56da1b8`; **remote CI still red on `efe341d` until PO push** |
| —       | Role Playwright login for non-owner demo personas | Open polish                                                                        |

---

## Workstreams A–E Delivery Update (27 November 2026)

Authorized autonomous delivery closed the following cross-cutting and operational gaps cleanly on top of the Phase 1–6 baseline:

| Workstream                                                 | Delivered & Verified Scope                                                                                                                                                                                                                                                                                                                                                                                                          | Status     |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| **A — Simplified Subscription Purchase**                   | Unified explicit-Company single-screen purchase/upgrade/renewal (`owner.subscription.purchase` / `owner.companies.subscription.*`), live backend quote JSON, auto draft/checkout/verification pipeline, obsolete `catalogue`/`drafts`/`draft-edit` UI removed.                                                                                                                                                                      | **Closed** |
| **B — Platform Billing & Subscription Invoices**           | Authorized Admin `PlatformBillingProfile` (`admin.billing-profile`), automatic idempotent GST tax invoice (`CGST+SGST` / `IGST`) + credit-note immutable snapshots, Owner & Admin invoice preview/download/register, separated from Customer transport books.                                                                                                                                                                       | **Closed** |
| **C — Centralized RBAC Matrix**                            | Domain-isolated Internal Team & Customer Roles UI (`/admin/roles`), diff preview, non-escalation guard, last Super Admin guard, mandatory edit remarks, immediate cache invalidation, No-Freight View enforcement across UI/APIs/Accounting/reports.                                                                                                                                                                                | **Closed** |
| **D — Enquiry / Broker / OwnFleet / Partload Gap Closure** | Multi-branch company-wide numbering consistency for Partload & Broker, Partload transshipment Challan line support (`2026_11_27_140000`), Main Challan + Hub shortage/damage + Delivery Receipt + `PartloadInvoiceService` billing/receipts, OwnFleet `VendorExpensePaymentService` & `DriverAdvanceService` (`company_id`-scoped Parties), null-safe `PaymentMode` GL posting, `LocalDemoWorkspaceSeeder` geography/tax bootstrap. | **Closed** |
| **E — Accounting Optional Add-On**                         | Paid optional `accounting` add-on enforcement across routes/UI/services, `AccountingActivationLifecycleService` cutover dry-run/posting/expiry-reversal/renewal catch-up, `AccountingReconciliationService` operational-vs-GL & FQCN source drill-down, balanced idempotent postings across all modules.                                                                                                                            | **Closed** |

_Remaining genuine external/PO gates preserved:_ live Razorpay production credentials (`PO-P3-PAY-PROD` Pending) and high-scale staging k6 certification (`P56-010`).

---

_Update this register when PO-LGC decisions land or parity rows are re-tested._
