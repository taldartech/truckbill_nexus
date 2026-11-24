# Lorry Hire Financial Workflow Certification

**Status:** Backend certified; browser journeys executed under demo seed when `E2E_REQUIRE_DEMO=1`  
**Date:** 24 November 2026

## Server authority

Final hire amounts recalculated by `LorryHireCalculationService` + `FreightCalculator` + `TransporterTdsCalculationService`. Client preview uses server TDS percent × transporter freight for display only.

## Gross freight margin

`customer_freight_total − transporter_freight_total`. TDS is withholding, not margin.

## Regression assertions (Pest)

- Customer LR freight unchanged after hire create.
- Loading weight and transporter rate persisted independently.
- TDS snapshot recorded.
- Outstanding = freight − TDS (net payable basis).

## Playwright

See `tests/e2e/broker/bilty-lorry-hire-ui.spec.ts` and `tests/e2e/broker/complete-workflow.spec.ts` (fixture updated to enter transporter rate).
