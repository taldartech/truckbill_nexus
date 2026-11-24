# Operational Weight Unit Standard

**Status:** Approved implementation standard (Batch 1 — legacy workflow alignment)  
**Date:** 24 November 2026

## Decision

| Layer                                        | Unit               | Scale            |
| -------------------------------------------- | ------------------ | ---------------- |
| Canonical storage (DB / domain)              | Kilograms (KG)     | 3 decimal places |
| User-facing operational entry & presentation | Metric Tonnes (MT) | 3 decimal places |
| Weight per Unit (`Wt./Unit`)                 | Kilograms (KG)     | 3 decimal places |

`FreightCalculator` and all financial domain services continue to receive **canonical KG** weights.  
`Per MT` freight divides actual weight KG by `1000` (unchanged).

## Conversion boundary

- Convert **MT → KG** at the UI submit boundary (hidden fields / request transform).
- Convert **KG → MT** at presentation and edit-form initialization.
- Reusable helpers:
    - PHP: `App\Support\Weight\OperationalWeight`
    - TypeScript: `resources/js/lib/operational-weight.ts`

## Wt./Unit relationship

```
Actual Weight (MT) = Units × Wt./Unit (KG) ÷ 1000
Wt./Unit (KG) = Actual Weight (MT) × 1000 ÷ Units
```

Charged Weight remains independently editable and is also entered/shown in MT.

## Historical data

Existing persisted weights remain kilograms. Do not mass-migrate historical rows.  
Edit screens must convert KG → MT for display and MT → KG on save so financial amounts are unchanged when weights are not edited.

## Safeguards

- No double conversion on edit/save.
- Freight snapshots and locked financial totals are not rewritten by presentation changes.
- Labels always include the unit (`MT` or `KG`).
