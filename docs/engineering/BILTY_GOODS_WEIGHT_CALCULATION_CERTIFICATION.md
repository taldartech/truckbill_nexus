# Bilty Goods Weight & Freight Calculation Certification

**Date:** 24 November 2026

## Backend (authoritative)

| Rate type   | Formula                            | Evidence                          |
| ----------- | ---------------------------------- | --------------------------------- |
| Per MT      | `rate × (actual_weight_kg ÷ 1000)` | `FreightCalculator` + Pest Phase4 |
| Other types | Unchanged                          | Phase4 LorryReceiptServiceTest    |

## Frontend assist

| Rule              | Behavior                      |
| ----------------- | ----------------------------- |
| Wt./Unit → Actual | `units × wt_per_unit`         |
| Actual → Wt./Unit | `actual ÷ units` (units > 0)  |
| Units change      | Refresh Wt./Unit from Actual  |
| Charged Weight    | Independent — not overwritten |
| Default new row   | `per_mt`                      |

## Persistence

Wt./Unit is **not** a database column. Reconstructed on edit from Actual ÷ Units.
