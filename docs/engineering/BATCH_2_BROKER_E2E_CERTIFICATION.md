# Batch 2 — Broker E2E Certification (partial)

**Date:** 24 November 2026  
**Environment:** Local `composer run dev` / Playwright system Chrome / `LocalDemoWorkspaceSeeder`

## Executed journeys

| Journey                           | Spec                                                       | Result | Duration |
| --------------------------------- | ---------------------------------------------------------- | ------ | -------- |
| Enquiry Customer selector         | `tests/e2e/branch/enquiry-customer-selector.spec.ts`       | Passed | ~4–7s    |
| Bilty Register readability        | `tests/e2e/broker/bilty-lorry-hire-ui.spec.ts` journey A   | Passed | ~7s      |
| Bilty + Hire freight independence | `tests/e2e/broker/bilty-lorry-hire-ui.spec.ts` journey B/C | Passed | ~11s     |
| Complete Broker spine             | `tests/e2e/broker/complete-workflow.spec.ts`               | Passed | ~22s     |
| Linked Bilty group payment        | `tests/e2e/broker/linked-bilty-payment.spec.ts`            | Passed | ~23s     |

Commands:

```bash
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 PLAYWRIGHT_SKIP_WEBSERVER=1 \
  npx playwright test \
  tests/e2e/branch/enquiry-customer-selector.spec.ts \
  tests/e2e/broker/bilty-lorry-hire-ui.spec.ts \
  tests/e2e/broker/complete-workflow.spec.ts \
  tests/e2e/broker/linked-bilty-payment.spec.ts \
  --reporter=list
```

## Fixture updates

`tests/e2e/fixtures/broker-workflow.ts` confirms FormConfirmationDialog after Bilty, Hire, Voucher, Memo, Adjustment, Invoice, Linked Bilty, **Delivery**, and **POD upload** submits (`57930fd`).

## UX continuation re-run (24 Nov 2026, after `ab9e20d`)

| Suite                               | Result       |
| ----------------------------------- | ------------ |
| `bilty-lorry-hire-ui.spec.ts`       | Passed (2/2) |
| `complete-workflow.spec.ts`         | Passed (1/1) |
| `enquiry-customer-selector.spec.ts` | Passed (1/1) |

Visual captures: `storage/visual-certification/bilty-lorry-hire/` (14 viewport PNGs on pass).

## Still outstanding for full Batch 2 H

- Full eleven-persona RBAC browser matrix
- Visual certification at seven viewports
- Large-dataset (>500) Party/Bilty pagination certification
- MySQL 8.4 full Pest suite
- Dedicated Playwright journeys A–I as named in the Batch 2 directive (partial coverage via existing specs)
