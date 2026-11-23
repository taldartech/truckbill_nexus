# Phase 1–5 Consolidated Product Owner Decision Package

**Status:** **RESOLVED — APPROVED** via `PO-P1-P5-FULLSTACK-AUTH` (23 November 2026)  
**Authority record:** `docs/implementation/PHASE_1_TO_5_PRODUCT_OWNER_FINAL_AUTHORIZATION.md`  
**Baseline at authorization:** `448d85fc7f697d8124eb7c1874d2e12a49058bee`  
**Purpose:** Historical decision sheet. D1–D8 are no longer Pending for implementation authorization. Do not re-request Approvals already granted.

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
| **PO-P1-P5-FULLSTACK-AUTH**                         | **Approved**             | D1–D8 resolved below                |

---

## 1. D1–D8 — APPROVED (23 November 2026)

### D1 — Company creation (PRD §7) — APPROVED

| Sub-decision            | Approval                                              |
| ----------------------- | ----------------------------------------------------- |
| PO-P2-CO-CREATE-ACTOR   | Option C — authenticated Customer + Internal-assisted |
| PO-P2-CO-CREATE-ENTRY   | Owner Panel + authenticated Workspace                 |
| PO-P2-CO-LEGAL-SCHEMA   | Complete PRD §7 legal/contact/address                 |
| PO-P2-CO-GST-SCHEMA     | Complete GST fields + conditional validation          |
| PO-P2-CO-BANK-ON-CREATE | First Bank Account when required fields valid         |
| PO-P2-CO-VISIBILITY     | Shared Data / Branch-wise Restricted                  |
| PO-P2-CO-DIGITAL-ASSETS | Private Logo / Signature                              |
| PO-P2-CO-WITHOUT-BRANCH | Company may exist without initial branch              |
| PO-P2-CO-DUPLICATE-PAN  | Same PAN permitted for multiple companies             |

Binding notes: no public self-registration; no credential overwrite; PAN/GSTIN format validation only; GST rate from active central config; timezone Asia/Kolkata; FY April–March; trial only on first eligible owned company; transactional + audited.

### D2 — Internal lifecycle UI — APPROVED

`PO-P2-INTERNAL-IA` + `PO-P2-INTERNAL-UI-AUTH`. Distinct from Sales CRM.

### D3 — Phase 4 product UI — APPROVED

`PO-P4-DESIGN-FORMAL` for Batch B Enquiry surfaces; Batches C–F design authority under Design.md with independent UX/QA review evidence (delegated nonmaterial refinements).

### D4 — Conversion cardinality — APPROVED Option C

`PO-P4-CNV-CARDINALITY`: N arranged vehicles → N Unified LRs with explicit allocation and reconciliation. No automatic Trips / Lorry Hires / Challans / GL postings.

### D5 — Phase 4 Batches C–F — APPROVED

Full-stack sequential; internal technical acceptance before advancing. Phase 9 communication delivery preserved.

### D6 — Phase 5 OwnFleet — APPROVED

`PO-P5-AUTH` + design authority for operational UI under Design.md. Requires certified Unified LR foundations.

### D7 — Playwright + k6 — APPROVED

`PO-P1-DEPS-E2E` (`@playwright/test`, Chromium, CI smoke) and `PO-P1-DEPS-K6` (Grafana k6 profiles). Pinned versions; non-production only.

### D8 — Phase 2A Internal CRM — APPROVED

`PO-P2A-AUTH` + `PO-P2A-DESIGN-FORMAL` plus:

| ID                     | Binding                                                                         |
| ---------------------- | ------------------------------------------------------------------------------- |
| PO-P2A-LEAD-STATUSES   | New → Contacted → Qualified → Converted; Disqualified/Lost with remarks         |
| PO-P2A-LEAD-ASSIGN     | Super Admin / Internal Admin assign; Sales Executives assigned-only             |
| PO-P2A-CONVERT         | Qualified only; identity reuse without credential overwrite; company via PRD §7 |
| PO-P2A-TICKET-STATUSES | Open → In Progress → On Hold → Resolved → Closed; reopen Resolved with reason   |
| PO-P2A-CRM-ACCESS      | PRD §6 matrix                                                                   |
| PO-P2A-DUPLICATE       | 30-day email/interest warning; override with remark + audit                     |

### PO-OWNER-GLOBAL-MANAGEMENT — APPROVED (23 November 2026)

Owner Panel is a **multi-company management workspace**. Global lists:

| Route                       | Purpose                                                |
| --------------------------- | ------------------------------------------------------ |
| `owner.companies.index`     | All companies owned by the authenticated Customer      |
| `owner.branches.all`        | Branches across owned companies                        |
| `owner.subscriptions.index` | Owned companies → company-specific subscription detail |

Login for owners enters the global Companies list (not forced single-company / MRU dashboard). Users / Settings / Audit remain company-specific. Company Switcher retained for operational context.

### PO-MASTER-COMPANY-VISIBILITY — APPROVED (23 November 2026)

Company `data_visibility` is the sole authority for Party, Vehicle, and Bank/Cash create scope. Client-submitted `scope` is ignored. Shared → `branch_id = null`; Branch-wise Restricted → current branch. Changing company visibility while operational masters exist is blocked until a migration policy is approved. Station / owner catalogues / central masters unchanged.

---

## 2. Preserved external gates (not waived)

- Phase 3 EX-01…EX-07
- PO-P3-PAY-PROD / live Razorpay
- Independent payment webhook e2e evidence
- Issuer/GST legal validation
- ADR-010 accounting posting
- Production PDF dependency deferral
- Paid renewal reminder deferral (Option D)
- Membership reactivation deferral
- Phase 9 WhatsApp/SMS/email delivery
- Production deployment authorization
- AUD-CON-006: implement only grounded entitlement cells; document unresolved cells

---

## 3. Engineering obligation

Implement authorized full-stack slices. Do **not** stop at documentation. Do **not** re-ask for D1–D8.
