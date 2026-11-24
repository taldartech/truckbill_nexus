# Phase 5 & 6 Final Gap Register

**Date:** 24 November 2026  
**Authority:** PO Phase 5/6 gap-closure and engineering certification directive  
**Tip at certification docs commit:** see `PHASE_5_6_FINAL_ACCEPTANCE.md`

| ID      | Area              | Finding                                   | Severity | Status                    | Evidence                                                                 |
| ------- | ----------------- | ----------------------------------------- | -------- | ------------------------- | ------------------------------------------------------------------------ |
| P56-001 | CI #89            | Branch dashboard 403 / route shell        | Critical | Fixed                     | `88bfb55`; `GITHUB_ACTIONS_89_REMEDIATION.md`                            |
| P56-002 | OwnFleet Trip     | Client vehicle substitution               | Critical | Fixed                     | `ed0c6c3`                                                                |
| P56-003 | LR update         | Financial fields editable with dependents | Critical | Fixed                     | `e0ff99a`                                                                |
| P56-004 | Broker vouchers   | Bulk transition partial commits           | High     | Fixed                     | `e0ff99a`                                                                |
| P56-005 | Unified invoices  | MySQL `ilike` + 500-row merge             | Critical | Fixed                     | `c3ae9f2`                                                                |
| P56-006 | Broker invoice    | No print/preview                          | High     | Fixed                     | `e0ff99a`                                                                |
| P56-007 | Documents/POD     | Route mismatch + missing UI/HTTP          | Critical | Fixed                     | `42e4caa`; `PHASE_5_6_DOCUMENT_POD_CERTIFICATION.md`                     |
| P56-008 | Playwright        | Full OwnFleet/Broker/Linked Bilty         | High     | Fixed                     | `bb5031a`, `c6ed866` — executed locally                                  |
| P56-009 | MySQL full Pest   | Beyond focused CI job                     | High     | Fixed (local)             | Disposable MySQL 8.4 cert slice; CI workflow expanded                    |
| P56-010 | k6 / concurrency  | Load + financial races                    | Medium   | Partial → Fixed for scope | Pest concurrency + k6 smoke thresholds met                               |
| P56-011 | LorryHire Active  | Unused Active enum                        | Low      | Deferred                  | No invented activation rule                                              |
| P56-012 | RBAC browser      | 11-persona matrix                         | Medium   | Partial                   | Pest forged-HTTP personas expanded (`bd0a244`); browser smokes env-gated |
| P56-013 | CI #90            | Pint EOF                                  | Critical | Fixed (local)             | `ac62b4c`; remote pending push                                           |
| P56-014 | Trip invoice HTTP | Auth exception → 500                      | High     | Fixed                     | `8d9d600` maps to 403                                                    |

## Remaining honest residuals

- Remote GitHub Actions green not verified until PO push.
- Eleven-persona **browser** matrix remains env-gated / partial vs Pest HTTP certification.
- k6 is smoke-level, not multi-thousand VU readiness.
- OwnFleet Playwright document **file upload** drawer automation is polish residual (HTTP Pest covers uploads).
