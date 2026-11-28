# Broker completion — visual certification (28 Nov 2026)

**Baseline commit:** continuation from `9c4e3bd`  
**Screenshots (when Playwright green):** `storage/visual-certification/broker-legacy-alignment-2026-11-28/`

| Artifact                                     | Journey                        |
| -------------------------------------------- | ------------------------------ |
| `02-lorry-hire-edit-1440.png`                | Good Hire edit + toolbar       |
| `12-lorry-hire-edit-mark-delivered-1440.png` | Mark as Delivered on edit      |
| `07-daily-payments-1440.png`                 | Daily Payments column layout   |
| `06-voucher-register-1440.png`               | Voucher register labels        |
| `08-voucher-payment-modal-1440.png`          | Single voucher modal sizing    |
| `09-voucher-bank-combobox-dropdown-1440.png` | Bank account combobox in modal |

**Run (local):**

```bash
php artisan e2e:ensure-broker-journey
E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1 \
  npx playwright test tests/e2e/broker/legacy-ui-screenshots.spec.ts
```

**E2E fixture:** `storage/app/e2e/broker-journey-auth.json` (company + branch IDs). Optional `E2E_ALLOW_DEMO_SEED=1` seeds Demo Alpha via `LocalDemoWorkspaceSeeder` without DB reset.
