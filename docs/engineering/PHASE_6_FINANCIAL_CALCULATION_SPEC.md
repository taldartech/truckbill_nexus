# Phase 6 — Broker Financial Calculation Specification

**Status:** Living engineering spec under PO-P6-AUTH (24 November 2026)  
**Authority:** PRD §13, Rules §11; legacy Broker formulas are reference only  
**Implementation:** `app/Domains/Broker/LorryHire/LorryHireCalculationService.php` (+ forthcoming Voucher / Book Balance services)

## Precision

- Money: scale 2 (`numeric-string` via bcmath)
- Rates: scale 3–4 as stored
- Weights: scale 3
- Never trust client-supplied freight/TDS/outstanding totals

## Goods-line transporter freight

| Mode | Formula |
| --- | --- |
| Fixed amount | `freight = approved_fixed_amount` |
| Per kg / actual weight | `freight = rate × applicable_weight` |
| Per MT | `freight = rate × (applicable_weight / 1000)` |
| Per package | `freight = rate × package_count` |
| Per charged weight | `freight = rate × charged_weight` |

`applicable_weight` at create = loading weight (charged preferred, else actual).  
After unloading adjustments: `applicable_weight = min(loading_weight, unloading_weight)` for variable rates. Fixed amount unchanged.

`hire.freight_total = Σ goods_line.freight_amount` (server-computed).

## TDS

- Engine: `TransporterTdsCalculationService` + `TdsRuleEvaluator`
- Basis at hire create: **original** loading/fixed freight total (immutable snapshot)
- `tds_amount = freight_basis × applicable_rate%`
- Unloading / shortage / damage must **not** change snapshot basis or historical `tds_amount`
- Manual override requires remark + authorization
- April 1 declaration reset: existing `TransporterDeclarationAnnualResetService` only

Snapshot fields: freight_basis, calculated_rate, applicable_rate, tds_amount, rule id/code/priority, pan_status, declaration_status, goods_vehicle_count, declaration_type, override_state, explanation, snapshotted_at.

## Net payable / outstanding

```
NetPayable   = FreightTotal + Detention + Deviation − Misc − Shortage − Damage − TDS
Outstanding  = NetPayable − Σ(PaidVoucherAmounts) + Σ(ApplicableBookBalanceSignedAmounts)
```

Unloading weight adjustments recalculate freight lines; they are not additive “amount” credits.

Negative outstanding / advance situations are handled via controlled Book Balance entries (not silent write-offs).

## Voucher defaults (Rules §11)

| Mode | Default status |
| --- | --- |
| Bank | Pending |
| Paid to Pump | Pending |
| Cash | In Progress |
| Adjust in Advance | In Progress |

Flow: `Pending → In Progress → Paid` (Paid terminal unless authorized reversal procedure exists).

## Group payment (Linked Bilty)

Proportional to each hire’s eligible outstanding; separate voucher per hire; residual rounding deterministic; no over-allocation.

## Evidence

- Pest: `tests/Feature/Broker/LorryHireServiceTest.php` (create, fixed/variable, TDS snapshot, concurrency)
- Further voucher/adjustment/invoice suites land in Batches C–G
