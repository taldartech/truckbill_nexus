# Frontend ↔ Backend Form Contract Audit

**Date:** 24 November 2026  
**Batch:** Legacy workflow alignment — Batch 1 foundation

## Method

For each approved business form: UI field → form state → request payload → validation → domain → persistence → reload presentation.

## Confirmed defects corrected this batch

| Form                     | Defect                               | Correction                                                                     |
| ------------------------ | ------------------------------------ | ------------------------------------------------------------------------------ |
| Enquiry create           | Customer selector listed all Parties | `PartyOptionQuery` Customer + branch visibility; searchable **Customer** label |
| Bilty goods              | Weights labeled/entered as KG        | MT entry with hidden KG submit; Wt./Unit remains KG                            |
| Lorry Hire create        | Loading weight KG UX                 | MT entry + KG submit; freight preview fixed for MT draft state                 |
| Trip pickup/allocation   | Weights unlabeled / KG UX            | MT placeholders; controller converts MT→KG at HTTP boundary                    |
| Multiple Party dropdowns | Missing `visibleInBranch`            | Consolidated via `PartyOptionQuery`                                            |

## Still open (follow-up batches)

- Full field-by-field contract matrix for Memo, Voucher, Invoice, Custom Invoice, Linked Bilty payment, Admin CRM forms.
- Confirmation dialog coverage gaps on Enquiry create and Bilty save (some surfaces still direct-submit).
- Server-side searchable autocomplete for large Party masters.

## Regression tests

- `PartySelectorEligibilityTest`
- `PartyOptionQueryTest`
- `OperationalWeightTest`
- Existing Lorry Hire / LR freight tests
