# GitHub Actions #88 Remediation

**Run:** https://github.com/taldartech/truckbill_nexus/actions/runs/45296079738  
**Date:** 24 November 2026  
**Baseline HEAD:** `fef49e6c656581d995c2f33d0a696cccecd6f24e`

## Failures

| Job   | Cause                                                                                                                                                                                           |
| ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MySQL | Migration `2026_11_23_195314_add_linked_bilty_group_header_columns` — composite FK `linked_bilty_groups_company_payee_fk` used `ON DELETE SET NULL` while `company_id` is NOT NULL (errno 1830) |
| Pint  | `app/Models/LinkedBiltyGroup.php` — `unary_operator_spaces`, `not_operator_with_successor_space`, `ordered_imports`                                                                             |

## Fix

1. **Original migration** — payee composite FK changed to `restrictOnDelete()` (same pattern as OwnFleet trip invoice allocation FKs). Historical payee references retained; party hard-delete blocked while referenced; `payee_party_id` remains nullable for optional payee.
2. **Forward migration** `2026_11_24_004732_fix_linked_bilty_groups_payee_fk_restrict_on_delete.php` — idempotent drop/recreate for PostgreSQL environments that already applied SET NULL.
3. **Pint** — ordered imports / operator spacing on `LinkedBiltyGroup.php`.

## Phase 6 scan

Other Phase 6 composite FKs already use `restrictOnDelete`. Single-column `created_by_user_id` → `nullOnDelete()` remains valid (nullable FK column).

## Remote Actions

Local CI-equivalent validation is recorded in the Batch A commit. **Remote Actions green is not claimed until a pushed commit passes.**
