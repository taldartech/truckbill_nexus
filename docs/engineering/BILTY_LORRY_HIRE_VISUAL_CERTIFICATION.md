# Bilty / Lorry Hire Visual Certification

**Status:** Screenshots captured and inspected  
**Date:** 24 November 2026  
**Playwright:** `tests/e2e/broker/bilty-lorry-hire-ui.spec.ts` — **2 passed** (48.3s) with `E2E_REQUIRE_DEMO=1 PLAYWRIGHT_USE_SYSTEM_CHROME=1`

## Viewports

1920×1080, 1440×900, 1366×768, 1280×800, 1024×768, 768×1024, 390×844.

## Captured paths (`storage/visual-certification/bilty-lorry-hire/`)

- `bilty-register-{viewport}.png` (all seven viewports)
- `bilty-create-goods-1920x1080.png`, `bilty-create-goods-390x844.png`
- `bilty-parties-route-1920x1080.png`
- `lorry-hire-create-1920x1080.png`, `lorry-hire-create-390x844.png`
- `lorry-hire-detail-1920x1080.png`, `lorry-hire-detail-1280x800.png`

## Inspected findings

- Bilty Register: headers readable; Truck No. fully visible; horizontal icon action group; compact filter toolbar; no page-level horizontal overflow assertions passed.
- Goods create: two desktop rows; Goods Description last and marked optional; Per MT default.
- Hire create: customer freight ₹72,000 vs transporter rate ₹7,500 / freight ₹60,000; TDS 2% ₹1,200; net ₹58,800; gross margin ₹12,000; free-text Broker route shown.
- Hire detail: transporter freight / TDS / net / outstanding cards reconcile with create preview.

## Remaining visual notes (non-blocking)

- Some demo seeder free-text routes still contain UUID-like enquiry markers — data, not table layout.
- Journey E (eleven-persona browser certification) not claimed; Owner demo persona executed for A–C.

## CI note

Prior CI failure (`SubscriptionRoleCombinationTest` expecting `trips.index` 200) corrected to expect redirect to Bilty Register after Trips nav removal.
