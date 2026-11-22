# Phase 1–5 Test Strategy

**Date:** 23 November 2026  
**Baseline:** `06ead4bd09730741e3c406ce90e128b8aeccbae7`

---

## 1. Layers

| Layer          | Tool                                        | Status            | Gate             |
| -------------- | ------------------------------------------- | ----------------- | ---------------- |
| Domain/Feature | Pest 5 + PostgreSQL                         | **Active** (CI)   | —                |
| Static         | Pint, Larastan, `npm run check/types/build` | **Active**        | —                |
| Browser E2E    | Playwright                                  | **Not installed** | `PO-P1-DEPS-E2E` |
| Performance    | Grafana k6                                  | **Not installed** | `PO-P1-DEPS-K6`  |

---

## 2. Pest (authoritative today)

Cover every **implemented** requirement with:

- Authorization / tenant isolation negatives
- State transitions + remarks/audit
- Validation + financial decimal safety
- Idempotency / concurrency (real DB locks)
- Document access negatives

Critical suites already present (non-exhaustive): CompanyBranchMembership, OwnerBranchAdministration, OwnerPanelManagement, DeactivationLifecycle, OwnershipTransfer, Phase3/4 Batch tests, LocalDemoWorkspaceSeeder.

**Expand Pest immediately** when authorized modules ship; do not wait for Playwright.

---

## 3. Playwright (after approval)

Proposed layout: `tests/e2e/` (see README).

Initial browser: Chromium.  
Fixtures: local demo personas (`LocalDemoWorkspaceSeeder`), never production accounts.  
CI: smoke job on PR; full suite on main/nightly.  
Evidence: screenshots, traces, HTML report artifacts.

Scenarios: auth, company/branch (after D1), masters, subscription overview, enquiry/LR/OwnFleet **only after those modules exist**.

---

## 4. k6 (after approval)

Proposed layout: `tests/performance/k6/` (see README).

Profiles: smoke → baseline → load → stress/spike/soak (scheduled, not every PR).  
Environment: dedicated non-production only.  
No real payments/comms.  
**Do not claim 5,000 concurrent users** without measured evidence on specified hardware.

---

## 5. Honesty rule

Never report Playwright/k6 as passed unless executed. Historical “browser QA” notes remain separate from automated E2E.
