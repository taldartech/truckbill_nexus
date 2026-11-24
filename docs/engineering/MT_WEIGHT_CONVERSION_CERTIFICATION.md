# MT Weight Conversion Certification

**Date:** 24 November 2026  
**Related:** `docs/architecture/OPERATIONAL_WEIGHT_UNIT_STANDARD.md`

## Strategy

Retain **KG** as canonical storage. Convert at the UI boundary only.

| User enters                  | Stored               | User sees after reload |
| ---------------------------- | -------------------- | ---------------------- |
| `8.125` MT                   | `8125.000` KG        | `8.125 MT`             |
| Wt./Unit `80` KG × 100 units | Actual `8000.000` KG | Actual `8.000 MT`      |

## Touched surfaces

- Bilty create/edit (`lr-form.tsx`) — Actual/Charged MT; Wt./Unit KG; hidden KG submit fields.
- Bilty show / print / index — MT presentation.
- Lorry Hire create — Loading weight MT entry; goods weights shown as MT; freight preview uses MT/KG correctly per rate type.
- Lorry Hire show — Loading weight displayed as MT.

## Freight verification

Canonical example (unchanged domain math):

- Actual weight stored: `8000` KG
- Rate: `₹9,000` Per MT
- Freight: `₹72,000` (`9000 × 8000/1000`)

Transporter independent freight with loading `8000` KG @ `₹7,500` Per MT → `₹60,000` (covered by `LorryHireIndependentFreightTest`).

## Tests

- `tests/Unit/OperationalWeightTest.php`
- Existing freight regression: `tests/Feature/LorryHireIndependentFreightTest.php`
- Filtered LorryReceipt freight tests

## Safeguards verified

- No change to `FreightCalculator` KG semantics.
- No historical weight migration.
- Edit path: KG → MT on load; MT → KG on submit.
