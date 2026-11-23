# RBAC Security Certification (Interim)

**Date:** 23 November 2026  
**Local tip at this write:** `b0c90ac029d2dff3b48db86b171d8026dd22b4f3`  
**Verdict:** **PARTIAL — NOT FINAL CERTIFIED**

This record is honest about remaining gaps. It must not be read as Phase 1–5 complete RBAC or UI certification.

## Mandatory summary

| Metric                                       | Count / Status                                                                                                   |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Default Customer roles tested (matrix)       | 5                                                                                                                |
| Default Internal roles exercised             | 3 + Super Admin                                                                                                  |
| Active Customer permission codes registered  | 41                                                                                                               |
| Codes with authorizer grant/deny/revoke loop | 41                                                                                                               |
| Protected named routes audited (inventory)   | 208 named (classified)                                                                                           |
| Routes with positive authorization tests     | Partial (see matrix)                                                                                             |
| Routes with negative authorization tests     | Expanding (`tests/Feature/Rbac/*` + No-Freight)                                                                  |
| Routes with tenant tests                     | Partial (`TenantIsolationTest`, Owner cross-company)                                                             |
| Routes with browser tests                    | Limited (6 product e2e + viewer smoke stub)                                                                      |
| Missing tests                                | Full HTTP matrix per remaining codes; Playwright personas; full subscription×role; remaining No-Freight surfaces |
| Security defects found this batch            | AUD-001 (CI lint); AUD-011 (Trip/Invoice UI without OwnFleet module)                                             |
| Fixed security defects this batch            | AUD-001; AUD-011 on `TripController` + `TripInvoiceController`                                                   |

## Controls verified

1. Super Admin bypasses Internal PM codes; cannot act as Company Owner via Gate.
2. Ordinary Internal Admin cannot create Internal users / escalate to Super Admin.
3. Customer domains cannot receive Internal roles and vice versa.
4. Owner Panel access ≠ operational permission codes.
5. Owner without branch role cannot load Trip operational pages.
6. Permission deactivation and revocation take effect (cache invalidation).
7. Branch permission caches do not leak across branches.
8. Spoofed company/branch query/body/headers rejected.
9. Broker-only subscription cannot open OwnFleet Trip / Invoice HTTP surfaces.
10. No-Freight redaction covers Trip, Invoice show, LR show, Invoice print, Payments index (Inertia props).

## Explicit non-claims

- Not every protected route has dedicated Allowed + Denied + Cross-tenant + Browser IDs.
- UI redesign is **not** complete across all 88 screens; structural IA landed on Trip, LR, Enquiry, Party form, Trip Invoice, CRM dashboard/lead, Owner company create — **browser screenshot matrix incomplete**.
- Playwright role suite is **not** complete.
- Full PostgreSQL+MySQL CI-equivalent suite for this tip not claimed beyond focused RBAC/OwnFleet Pest slices and frontend `npm run check`.

## Required for FINAL certification

Complete remaining Batches D–J screen-by-screen with browser evidence, Batch K Playwright personas, Batch L full PG/MySQL/CI certification.
