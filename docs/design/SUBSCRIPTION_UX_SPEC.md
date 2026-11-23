# Subscription Management UX Specification

**Status:** Implemented (interactive shell over existing Phase 3 services)  
**Date:** 23 November 2026

---

## Entry

Owner Panel → **Subscriptions** → company list → **Manage**.

List columns: Company, Status, Active Modules, Subscription status, Trial/Renewal, Manage.

No Owner company selector.

## Company overview

Read-only company context in title/breadcrumb. Primary actions only when applicable: Subscribe / Add Module / Upgrade, Pay, Renew, Invoices, Payment History, Credits. Advanced contract/quota/storage remain on the same page in secondary sections.

## Guided draft / purchase flow

Draft edit is a stepped experience:

1. Select modules
2. Select plans (cards)
3. Configure add-ons
4. Select tenure + common renewal date
5. Review + refresh **server** price preview + Save draft

Checkout remains Razorpay **Test Mode** via existing verify/simulate endpoints. No live Razorpay. No client-side pricing invention.

## Preserved commercial rules

One billing account per company; common renewal date; multi-module; proration; catalogue; discounts/caps; trial; renewal; snapshots; capacity/quotas; credits; webhook idempotency; cancellation at renewal; deferred production billing exceptions.
