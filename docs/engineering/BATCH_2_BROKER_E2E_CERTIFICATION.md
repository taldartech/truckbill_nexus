# Batch 2 — Broker E2E Certification

**Date:** 24 November 2026  
**Environment:** Local `composer run dev` / Playwright system Chrome / `LocalDemoWorkspaceSeeder`  
**Baseline:** `701f4df` → post-certification tip

## Executed journeys

| Journey                                | Spec                                                       | Result |
| -------------------------------------- | ---------------------------------------------------------- | ------ |
| Enquiry Customer selector              | `tests/e2e/branch/enquiry-customer-selector.spec.ts`       | Passed |
| Bilty Register readability + viewports | `tests/e2e/broker/bilty-lorry-hire-ui.spec.ts` journey A   | Passed |
| Bilty + Hire freight independence      | `tests/e2e/broker/bilty-lorry-hire-ui.spec.ts` journey B/C | Passed |
| Complete Broker spine                  | `tests/e2e/broker/complete-workflow.spec.ts`               | Passed |
| Linked Bilty group payment             | `tests/e2e/broker/linked-bilty-payment.spec.ts`            | Passed |
| Save & Create Lorry Hire               | `tests/e2e/broker/operational-flow-parity.spec.ts`         | Passed |
| Bilty edit previous/new confirmation   | `tests/e2e/broker/operational-flow-parity.spec.ts`         | Passed |
| Record voucher hire preselect          | `tests/e2e/broker/operational-flow-parity.spec.ts`         | Passed |

Commands:

```bash
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 PLAYWRIGHT_SKIP_WEBSERVER=1 \
  npx playwright test \
  tests/e2e/branch/enquiry-customer-selector.spec.ts \
  tests/e2e/broker/bilty-lorry-hire-ui.spec.ts \
  tests/e2e/broker/complete-workflow.spec.ts \
  tests/e2e/broker/linked-bilty-payment.spec.ts \
  tests/e2e/broker/operational-flow-parity.spec.ts \
  --reporter=list
```

## Fixture updates

`tests/e2e/fixtures/broker-workflow.ts` — FormConfirmationDialog helpers; `createBrokerLrAndContinueToHire`; `editBrokerLrWithChangeReview`; hire dialog-aware create.

## Visual captures

`storage/visual-certification/bilty-lorry-hire/` — seven viewports on journey A pass (1920×1080 … 390×844).

## Outside this certification

- Full eleven-persona RBAC matrix (customer operator/viewer smokes remain available separately)
- Large-dataset (>500) Party/Vehicle load certification beyond API limit/search tests
- Live Razorpay / production webhook e2e
