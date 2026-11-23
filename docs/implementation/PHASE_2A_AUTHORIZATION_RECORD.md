# Phase 2A Authorization Record — Internal CRM & Customer Onboarding

**Decision IDs:** `PO-P2A-AUTH`, `PO-P2A-DESIGN-FORMAL` (+ lifecycle sub-decisions)  
**Date:** 23 November 2026  
**Authority:** Product Owner via `PO-P1-P5-FULLSTACK-AUTH`  
**Status:** **APPROVED**  
**Design package:** `docs/design/phase-2a/` (authorized; delegated nonmaterial refinements)

---

## Approved scope

Full-stack Internal CRM: leads, pipeline, follow-ups, conversion/onboarding, support tickets, role-aware dashboards. Distinct from Internal lifecycle administration (D2).

### Binding sub-decisions

| ID                     | Status   | Binding                                                                          |
| ---------------------- | -------- | -------------------------------------------------------------------------------- |
| PO-P2A-LEAD-STATUSES   | APPROVED | New → Contacted → Qualified → Converted; Disqualified/Lost with remarks + audit  |
| PO-P2A-LEAD-ASSIGN     | APPROVED | Super Admin / Internal Admin assign; Sales Executives assigned-only              |
| PO-P2A-CONVERT         | APPROVED | Qualified only; safe Customer reuse; company via PRD §7; no credential overwrite |
| PO-P2A-TICKET-STATUSES | APPROVED | Open → In Progress → On Hold → Resolved → Closed; reopen Resolved with reason    |
| PO-P2A-CRM-ACCESS      | APPROVED | PRD §6 matrix                                                                    |
| PO-P2A-DUPLICATE       | APPROVED | 30-day potential-duplicate warning; override with remark + audit                 |

## Boundaries

- No Customer access to Internal CRM
- No fake revenue metrics
- Preserve trial/subscription rules on conversion
- No production deploy / live payments / Phase 9 provider delivery
