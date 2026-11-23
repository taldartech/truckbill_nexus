# Phase 1–5 Consolidated Product Owner Decision Package

**Status:** **AWAITING PRODUCT OWNER DECISIONS** — engineering must not invent Approvals.  
**Date:** 23 November 2026  
**Baseline:** `06ead4bd09730741e3c406ce90e128b8aeccbae7`  
**Purpose:** Single sheet of **material unresolved** items blocking Phases 1–5 completeness requested in the master implementation prompt. Do not reopen already resolved decisions.

---

## 0. Already resolved (do not reopen)

| ID                                                  | Status                   | Note                                |
| --------------------------------------------------- | ------------------------ | ----------------------------------- |
| PO-APPROVE-001 / Phase 0                            | Approved                 | Governing baseline                  |
| PO-P1-AUTH                                          | Approved                 | Foundation                          |
| ADR-006                                             | Accepted web-only        | **No Sanctum**                      |
| PO-P2-FINAL-ACCEPT                                  | Approved WITH EXCEPTIONS | Phase 2 closed                      |
| PO-P3-FINAL-ACCEPT Option A                         | Approved WITH EXCEPTIONS | EX-01…07 preserved                  |
| PO-P3-PAY-TEST                                      | Approved                 | Test Mode only                      |
| PO-P4-AUTH + BATCH-B-AUTH                           | Approved                 | Backend Batches A–B                 |
| PO-P4-BROADCAST / CONVERT / PERM / NUMBER / WEBHOOK | Approved V1 / Option A   | See ADR-013/014                     |
| DEC-003                                             | Accepted                 | Soft-delete; no restore/hard delete |
| DEC-005                                             | Accepted                 | Partload expense OOS                |
| Membership reactivation                             | Deferred                 | Do not implement                    |
| PO-P3-PAY-PROD                                      | Pending                  | No live Razorpay                    |

---

## 1. Decisions required now

### D1 — Company creation (PRD §7)

| Field              | Value                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| IDs                | `PO-P2-CO-CREATE-ACTOR`, `PO-P2-CO-CREATE-ENTRY`, `PO-P2-CO-LEGAL-SCHEMA`, `PO-P2-CO-GST-SCHEMA`, `PO-P2-CO-BANK-ON-CREATE`, `PO-P2-CO-VISIBILITY`, `PO-P2-CO-DIGITAL-ASSETS`, `PO-P2-CO-WITHOUT-BRANCH`, `PO-P2-CO-DUPLICATE-PAN`                                                                                                                                                          |
| Evidence           | PRD §7; `PHASE_2_COMPANY_CREATION_PO_DECISION_PACKAGE.md`; Phase 2 acceptance Pending legal/tax                                                                                                                                                                                                                                                                                             |
| Current            | Service creates **name + Owner membership + initial branch** only; no Customer create UI                                                                                                                                                                                                                                                                                                    |
| **Recommendation** | Approve PRD §7 field set as-is: actor **(C)** Customer self-serve additional company + Internal assist; entry Owner Panel + workspace; duplicate PAN **allowed**; default create **with** initial branch (override only if PO confirms no-branch); Data Visibility + numbering defaults per PRD; optional bank → create Bank Account when complete; logo/signature via private file service |
| Alternatives       | Defer subsets (blocks “Complete company management”)                                                                                                                                                                                                                                                                                                                                        |
| Blocks             | Company create/edit UI, migrations, trial messaging on create form                                                                                                                                                                                                                                                                                                                          |
| Independent work   | Branch/Owner membership UI (done); Phase 3 masters                                                                                                                                                                                                                                                                                                                                          |

### D2 — Internal lifecycle UI

| Field              | Value                                                                                                                        |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| IDs                | `PO-P2-INTERNAL-IA`, `PO-P2-INTERNAL-UI-AUTH`                                                                                |
| Evidence           | `INTERNAL_LIFECYCLE_UI_DESIGN_PACKAGE.md`; P2 design exception Internal OOS                                                  |
| Current            | HTTP lifecycle/transfer Complete; no Inertia                                                                                 |
| **Recommendation** | Approve design package surfaces (company/identity lifecycle, ownership transfer, minimal Internal users) — **not** Sales CRM |
| Blocks             | Internal management screens                                                                                                  |
| Independent work   | All Customer Owner/Branch authorized UI                                                                                      |

### D3 — Phase 4 product UI design

| Field              | Value                                                                                           |
| ------------------ | ----------------------------------------------------------------------------------------------- |
| ID                 | `PO-P4-DESIGN-FORMAL`                                                                           |
| Evidence           | `PHASE_4_UI_REVIEW.md`, design package, authorization record                                    |
| Current            | **Pending**; Batch B JSON API only                                                              |
| **Recommendation** | Approve formal Enquiry (+ subsequent Batch C–E surface specs) then authorize Batch C full-stack |
| Blocks             | All Phase 4 product UI                                                                          |
| Independent work   | Backend hardening; webhook; Pest                                                                |

### D4 — Conversion cardinality

| Field              | Value                                                                               |
| ------------------ | ----------------------------------------------------------------------------------- |
| ID                 | `PO-P4-CNV-CARDINALITY`                                                             |
| Evidence           | `PHASE_4_CONVERSION_CARDINALITY_DECISION_PROPOSAL.md`                               |
| Current            | **Pending**; schema cardinality-neutral                                             |
| **Recommendation** | **Option C** (N LRs with split allocation + reconciliation) or **D** with C default |
| Blocks             | Batch F conversion behaviour                                                        |
| Independent work   | Batches C–E (after their gates) without inventing conversion rules                  |

### D5 — Phase 4 Batch C+ implementation authorization

| Field              | Value                                                                                   |
| ------------------ | --------------------------------------------------------------------------------------- |
| ID                 | `PO-P4-BATCH-C-AUTH` (and D/E/F as applicable)                                          |
| Evidence           | Execution plan: do not auto-start Batch C                                               |
| **Recommendation** | After D3, authorize Batch C (assignment/follow-ups) full-stack; then D/E/F sequentially |
| Blocks             | Quotation, LR ops, conversion product delivery                                          |

### D6 — Phase 5 OwnFleet authorization + design

| Field              | Value                                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| IDs                | `PO-P5-AUTH`, `PO-P5-DESIGN-FORMAL`                                                                                                         |
| Evidence           | `Phases.md` Phase 5; no Phase 5 auth record exists                                                                                          |
| Current            | **Not authorized**                                                                                                                          |
| **Recommendation** | Authorize Phase 5 only after Phase 4 conversion cardinality + LR foundations usable; design Trip/multi-point/expense/invoice surfaces first |
| Blocks             | All OwnFleet ops/UI/tests                                                                                                                   |

### D7 — Playwright + k6 dependency / CI

| Field              | Value                                                                                                                                  |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| IDs                | `PO-P1-DEPS-E2E` (`@playwright/test`), `PO-P1-DEPS-K6` (k6 binary/docs; not npm if preferred)                                          |
| Evidence           | Project rule: no dependency change without approval; package.json has neither                                                          |
| Current            | Pest only; GHA without Playwright/k6                                                                                                   |
| **Recommendation** | Approve adding `@playwright/test` (Chromium CI smoke) and documenting k6 CLI for scheduled/manual performance workflows (not every PR) |
| Blocks             | Mandatory E2E/perf certification in this prompt                                                                                        |
| Independent work   | Pest expansion; Stage 0 READMEs under `tests/e2e` and `tests/performance/k6`                                                           |

### D8 — Phase 2A Internal CRM (new)

| Field              | Value                                                                                                 |
| ------------------ | ----------------------------------------------------------------------------------------------------- |
| IDs                | `PO-P2A-AUTH`, `PO-P2A-DESIGN-FORMAL`, plus lifecycle IDs in `PHASE_2A_AUTHORIZATION_REQUEST.md`      |
| Evidence           | `Phases.md` Phase 2A (**Pending** auth); PRD §5–§6; `docs/design/phase-2a/PHASE_2A_DESIGN_PACKAGE.md` |
| Current            | **Not authorized** — no CRM tables/UI                                                                 |
| **Recommendation** | Approve AUTH + DESIGN-FORMAL together with lead/ticket status and conversion rules (see request)      |
| Blocks             | All Lead / Sales / Onboarding / Support product delivery                                              |
| Independent work   | Phase 2 Owner/Branch (done); Phase 3 EX preserve; DEC-003 `lr_goods_rows` active unique (shipped)     |
| Hard gate          | Must **not** bypass D1 company §7 during onboarding                                                   |

### D9 — Preserved Phase 3 financial gates (unchanged)

| ID                              | Status   | Note                       |
| ------------------------------- | -------- | -------------------------- |
| AUD-CON-006 / TB-SUB-STATE-001  | Pending  | Entitlement matrix         |
| G-PAY webhook e2e evidence      | Open     | Dashboard evidence         |
| PO-P3-PAY-PROD                  | Pending  | No live charges            |
| Issuer/GST production invoice   | Pending  | Sample ≠ tax invoice       |
| ADR-010                         | Pending  | No GL posting architecture |
| Paid renewal reminders Option D | Deferred | —                          |
| Production PDF package          | Deferred | —                          |

---

## 2. Safe parallel work while waiting

| Workstream                                       | Why safe                                                     |
| ------------------------------------------------ | ------------------------------------------------------------ |
| Stage 0 docs / cert honesty                      | No policy invention                                          |
| Pest for existing authorized surfaces            | Already approved                                             |
| Demo seeder within existing modules              | Local-only guard                                             |
| Branch/Owner polish without schema invention     | Already authorized                                           |
| `lr_goods_rows` active-position unique (DEC-003) | **Implemented** — Batch A schema hardening; no LR product UI |

---

## 3. Product Owner response template

For each ID above, reply:

`APPROVED` | `APPROVED WITH EXCEPTIONS` | `DEFERRED` | `REJECTED` + chosen option letter where applicable.
