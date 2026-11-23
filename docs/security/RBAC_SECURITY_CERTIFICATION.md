# RBAC Security Certification (Interim)

**Date:** 23 November 2026  
**Verdict:** **PARTIAL — NOT FINAL CERTIFIED**

This record is honest about remaining gaps. It must not be read as Phase 1–5 complete RBAC certification.

## Mandatory summary

| Metric                                       | Count / Status                                                                                                           |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Default Customer roles tested (matrix)       | 5                                                                                                                        |
| Default Internal roles exercised             | 3 + Super Admin                                                                                                          |
| Active Customer permission codes registered  | 41                                                                                                                       |
| Codes with authorizer grant/deny/revoke loop | 41                                                                                                                       |
| Protected named routes audited (inventory)   | 208 named (classified)                                                                                                   |
| Routes with positive authorization tests     | Partial (see matrix)                                                                                                     |
| Routes with negative authorization tests     | Partial                                                                                                                  |
| Routes with tenant tests                     | Partial (`TenantIsolationTest`, Owner cross-company)                                                                     |
| Routes with browser tests                    | Limited (6 e2e specs)                                                                                                    |
| Missing tests                                | Module HTTP matrix per code; Internal CRM grant/absent; Playwright personas; subscription×role; full No-Freight surfaces |
| Security defects found this batch            | AUD-001 fixed; AUD-003 Owner masters OR documented; AUD-005/006/007 open                                                 |
| Fixed security defects this batch            | CI lint unused imports (not auth)                                                                                        |

## Controls verified

1. Super Admin bypasses Internal PM codes; cannot act as Company Owner via Gate.
2. Ordinary Internal Admin cannot create Internal users / escalate to Super Admin.
3. Customer domains cannot receive Internal roles and vice versa.
4. Owner Panel access ≠ operational permission codes.
5. Owner without branch role cannot load Trip operational pages.
6. Permission deactivation and revocation take effect (cache invalidation).
7. Branch permission caches do not leak across branches.
8. Spoofed company/branch query/body/headers rejected.

## Explicit non-claims

- Not every protected route has dedicated Allowed + Denied + Cross-tenant + Browser IDs.
- UI redesign is **not** certified complete; Trip Detail tab IA is a structural start.
- Playwright role suite is **not** complete.
- MySQL full suite for this batch not re-run yet after CI #86 fix (MySQL job passed on Actions #86 before frontend failure).

## Required for FINAL certification

Complete Batches B–C and K–L of the PO directive with measured metrics and browser evidence index.
