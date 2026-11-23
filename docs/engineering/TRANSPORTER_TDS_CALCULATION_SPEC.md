# Transporter TDS Calculation Specification

**Status:** Implemented (Party Master rate preview + central rules)  
**Governing:** Rules §6  
**Date:** 23 November 2026

## Architecture

```
Party form (Transporter role)
  → POST /parties/tds-preview (debounced)
  → PartyController::previewTds
  → TransporterTdsCalculationService
  → TdsRuleEvaluator (active TdsRule rows, priority order)
  → TransporterTdsCalculationResult
```

On create/update, `PartyService::applyTransporterTdsRates` snapshots calculated/applicable rates into transporter `role_attributes`.

## Context fields (evaluator)

| Field                 | Source                                          |
| --------------------- | ----------------------------------------------- |
| `pan_status`          | transporter `pan_status`                        |
| `pan_fourth_char`     | 4th character of normalized PAN                 |
| `declaration_valid`   | transporter `declaration` (boolean; false = No) |
| `goods_vehicle_count` | `goods_vehicles_count`                          |
| `declaration_type`    | `owner` \| `agency`                             |

## Approved outcomes (via central rules)

| Scenario                           | Expected rate class                        |
| ---------------------------------- | ------------------------------------------ |
| Invalid / inactive PAN             | 20% rule                                   |
| Active PAN 4th = P/H               | 1% rule                                    |
| Active PAN 4th = C/F/A/B/J/L/T     | 2% rule                                    |
| Owner + declaration + ≤10 vehicles | 0% exemption                               |
| Owner + declaration + >10 vehicles | Standard PAN rate                          |
| Agency                             | Ignores owner exemption; standard PAN rate |
| No matching active rule            | Configuration error — **never silent 0%**  |

Rates always come from `TdsRule` configuration (`TdsReferenceRulesSeeder`), not hardcoded frontend math.

## Override

- Authorized override with mandatory remark
- Preserves calculated vs applicable rates
- Unauthorized / missing remark rejected

## April 1 annual reset

- Command: `tax:reset-transporter-declarations`
- Schedule: yearly 1 April 00:05 `Asia/Kolkata` (`routes/console.php`)
- Service: `TransporterDeclarationAnnualResetService`
- Idempotent via `transporter_declaration_resets.financial_year`
- Resets declaration to No, recalculates current rate, audits, preserves historical evidence

## Explicitly out of scope (this wave)

- Lorry Hire TDS amount postings / snapshots (Phase 6)
- Customer invoice TDS (separate workflow)
- Statutory tax legal certification

## Reference data vs demo

- `TdsReferenceRulesSeeder` — central rules (idempotent; not in `DatabaseSeeder`)
- `LocalDemoWorkspaceSeeder` — may call reference seeder + demo transporters (invalid PAN, agency, heavy fleet, etc.)
