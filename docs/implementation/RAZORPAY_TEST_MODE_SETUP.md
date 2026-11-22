# Razorpay Test Mode Setup (Official PHP SDK)

**Authority:** PO-P3-PAY-TEST · PO-P1-DEPS (official `razorpay/razorpay` only)  
**Production:** PO-P3-PAY-PROD remains **Pending** — live keys and production charges are blocked in code.

## Environment (exactly three variables)

```dotenv
RAZORPAY_KEY_ID=rzp_test_...
RAZORPAY_KEY_SECRET=...
RAZORPAY_WEBHOOK_SECRET=...
```

No other Razorpay flags are used. Gateway selection is automatic:

| Condition                                          | Gateway                                     |
| -------------------------------------------------- | ------------------------------------------- |
| `APP_ENV=testing`                                  | `FakePaymentGateway` (CI)                   |
| Non-production + complete `rzp_test_*` credentials | `RazorpayTestPaymentGateway` (official SDK) |
| Missing / incomplete credentials                   | `FakePaymentGateway`                        |
| `APP_ENV=production` or `rzp_live_*`               | Razorpay processing rejected                |

Never commit secrets. Never paste secrets into chat or documentation.

## Local checkout

1. Set the three variables in `.env` (Test Mode dashboard keys).
2. `php artisan optimize:clear`
3. `composer run dev` or `php artisan serve` + `npm run dev`
4. Sign in as Company Owner → Subscription → Pay / activate (Test Mode)
5. Confirm banner: **Razorpay Test Mode — No Real Money Will Be Charged**
6. Complete a Test Mode payment; server verifies signature + **captured** status before activation

## Optional real webhooks

Razorpay cannot reach `http://127.0.0.1` directly. Use a public HTTPS tunnel (e.g. Cloudflare Tunnel) and register:

`POST {APP_URL}/webhooks/razorpay`

Subscribe at least: `payment.captured`, `payment.failed`.

Webhook processing requires the same three credentials and a non-production `APP_ENV`.

## Failed payment / retry

- Checkout failures stay on the Owner checkout page with an accessible message.
- Refreshing checkout after a gateway switch creates a new gateway-scoped payment intent (stale `order_fake_*` rows are not reused).
- Only **captured** payments fulfill subscriptions; **authorized** remains pending reconciliation.

## CI

Standard Pest uses `FakePaymentGateway` and SDK boundary mocks. No Razorpay credentials or external network calls are required.
