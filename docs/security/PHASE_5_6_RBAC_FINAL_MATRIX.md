# Phase 5 & 6 RBAC Final Matrix (Stabilization Pass)

**Date:** 24 November 2026

## Preserved

- AUD-013: Branch dashboard requires Owner **or** `platform.operations.view` (`88bfb55`).
- Module permissions remain on branch roles; Owner designation does not auto-grant Trip/Broker action codes.
- No-Freight view continues to redact via `FreightAmountPresentation` on invoice facades.

## This pass

| Change                              | Security impact                     |
| ----------------------------------- | ----------------------------------- |
| Landing aligned with dashboard gate | Prevents assignment-only 403 bounce |
| Broker invoice print                | Gated by `broker.invoice.print`     |
| Trip vehicle lock                   | Server rejects forged vehicle_id    |

## Open

Full 11-persona forged-HTTP **browser** matrix remains scheduled (Stage O). Server-side certification expanded in `tests/Feature/Rbac/Phase5Phase6SensitiveMutationHttpTest.php` (personas: Owner, admin, operator, viewer, no-freight, custom). Playwright lightweight persona smokes under `tests/e2e/roles/customer/` require `E2E_REQUIRE_DEMO` and `LOCAL_DEMO_PASSWORD` / `E2E_USER_PASSWORD` — no credentials committed.
