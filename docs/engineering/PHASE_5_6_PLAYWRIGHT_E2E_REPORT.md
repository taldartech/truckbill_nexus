# Phase 5 & 6 Playwright E2E Report

**Date:** 24 November 2026

## Commands

```bash
set -a && source .env && set +a
export E2E_USER_PASSWORD="${LOCAL_DEMO_PASSWORD}"
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  npx playwright test tests/e2e/ownfleet/complete-workflow.spec.ts
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  npx playwright test tests/e2e/broker/
```

## Results

| Spec                                  | Result                           |
| ------------------------------------- | -------------------------------- |
| OwnFleet `complete-workflow.spec.ts`  | 3 passed / 0 skipped (`bb5031a`) |
| Broker `complete-workflow.spec.ts`    | passed (`c6ed866`)               |
| Broker `linked-bilty-payment.spec.ts` | passed (`c6ed866`)               |

## Gaps / polish

OwnFleet drawer file-upload automation not required for HTTP certification (Pest covers uploads). Role browser smokes remain env-gated.
