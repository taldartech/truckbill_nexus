# Batch 2 — Broker Party Selector Certification

**Date:** 24 November 2026  
**Commit:** `6802dc9a1b34803092f8eb6144596349a481bb95` (+ follow-up)

## Capability

Scalable, role-filtered, branch-visible Party selection for Broker operational forms.

## Implementation

| Layer        | Source                                                                                                       |
| ------------ | ------------------------------------------------------------------------------------------------------------ |
| Domain query | `App\Domains\Master\Party\PartyOptionQuery` — `q`, `selected_id`, limit ≤ 300                                |
| HTTP         | `GET branch.parties.options` → `PartyController::options`                                                    |
| UI           | `SearchablePartySelect` remote debounce fetch (250 ms), loading + empty states                               |
| Wired forms  | Enquiry Customer; Bilty Billing/Consignor/Consignee; Hire Payee; Broker Invoice Customer; Linked Bilty Payee |

## Search fields

Name, contact number, city, email, PAN (case-insensitive portable LIKE).

## Evidence

| Test                                   | Result                                                                                               |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `PartyOptionsEndpointTest`             | Passed (role filter, beyond-limit domain search, selected_id, invalid role, cross-company rejection) |
| `PartyOptionQueryTest`                 | Passed                                                                                               |
| `PartySelectorEligibilityTest`         | Passed                                                                                               |
| Playwright `enquiry-customer-selector` | Pending this run                                                                                     |

## Guarantees

- Parties outside the initial option window remain searchable.
- Full Party Master is not downloaded to the browser.
- Forged / cross-company `selected_id` values are ignored.
- Soft-deleted / inactive roles are excluded.

## Remaining

- Invoice index filter, Trip, Vehicle, Book Balance remote selectors (B2-PS-03).
- Browser large-dataset certification (>500 parties) still outstanding.
