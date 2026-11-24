# LR Downstream Financial Integrity

**Date:** 24 November 2026  
**Commit:** `e0ff99a`  
**Service:** `App\Domains\Consignment\LrFinancialImmutabilityService`

## Problem

`LorryReceiptService::update` allowed Pending LRs to change freight, goods, charges, and billing customer even when:

- An OwnFleet Trip existed (LR often remains Pending after Trip completion).
- A Broker Lorry Hire existed.
- A non-cancelled OwnFleet or Broker customer invoice line referenced the LR.

That desynchronized Trip profitability, Hire freight/TDS snapshots, and invoice amounts.

## Policy (implemented)

When any of the above dependencies exist, reject mutations to:

- `billing_customer_party_id`
- `goods_value` / goods rows (rate, weights, packages, freight)
- `freight_total`
- `additional_charges_total` / additional charges

Still allowed (non-financial / already immutable separately):

- Route text / stations, consignor/consignee, e-way bills, `lr_date`
- `vehicle_id` / `workflow_context` / `lr_number` (already frozen on update)

Trip completion **must not** auto-mark LR Delivered solely for invoice eligibility — unchanged.

## User messaging

Validation error on `freight_total` explains the blocking dependency and that route/party/EWB edits remain available until the downstream record is cancelled (where permitted).
