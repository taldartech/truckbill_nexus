# Party Selector Eligibility Certification

**Date:** 24 November 2026  
**Scope:** Enquiry Customer selector + shared `PartyOptionQuery` rollout

## Root cause (Enquiry)

`EnquiryController::create` previously loaded all non-deleted company Parties into the Customer dropdown. Backend domain validation correctly rejected non-Customer roles (`Selected party must have an active Customer role`), but the UI offered ineligible Parties.

## Correction

- Introduced `App\Domains\Master\Party\PartyOptionQuery` — company + `visibleInBranch` + active role filter + soft-delete exclusion + bounded limit.
- Enquiry create now passes Customer-only options (`parties` / `customers`).
- UI: searchable **Customer** selector (`SearchablePartySelect`) with empty-state + link to Party create.
- Same query reused for: LR consignors/consignees/billing customers, Lorry Hire transporters, Broker invoice billing customers, Trip drivers/consignors/consignees, Vehicle transporters/drivers.

## Eligibility matrix (enforced)

| Field                            | Role filter |
| -------------------------------- | ----------- |
| Customer / Billing Customer      | Customer    |
| Consignor                        | Consignor   |
| Consignee                        | Consignee   |
| Transporter / Payee options      | Transporter |
| Primary / Substitute Driver      | Driver      |
| Vehicle Owner (transporter list) | Transporter |

Server-side domain validation remains authoritative against forged IDs.

## Tests

- `tests/Feature/PartyOptionQueryTest.php`
- `tests/Feature/PartySelectorEligibilityTest.php` (Inertia options + forged Customer rejection)

## Remaining follow-ups

- Server-side autocomplete endpoints for very large master sets (current bound: 200–300).
- Pump Vendor / General Vendor selectors when those workflows are next touched.
- Playwright Journey A (Enquiry Customer filter) in Batch 7 certification.
