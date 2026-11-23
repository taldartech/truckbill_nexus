# Phase 2A Design Package — Internal CRM (Draft for Formal Approval)

**Status:** **APPROVED** (`PO-P2A-DESIGN-FORMAL` via `PO-P1-P5-FULLSTACK-AUTH`, 23 November 2026).  
**Date:** 23 November 2026  
**Implementation authorized.** Screen specs + independent UX/QA review remain required before each new surface ships; do not claim personal Product Owner review of post-authorization artifacts.

---

## 1. Surfaces (proposed)

| Screen                     | Actors                      | Purpose                                                     |
| -------------------------- | --------------------------- | ----------------------------------------------------------- |
| CRM Dashboard              | Role-scoped per PRD §6      | Metrics from real aggregates                                |
| Leads index                | SA/Admin/Sales              | Search/filter/pagination                                    |
| Lead create/edit/show      | Same                        | Fields: source, product interest, status, assignee, remarks |
| Lead follow-ups            | Assignee + Admin            | Schedule + complete follow-ups; timeline                    |
| Sales pipeline             | SA/Admin/Sales              | Assigned leads + scheduled follow-ups                       |
| Conversion confirm         | SA/Admin/Sales (policy TBD) | Preview identity reuse + company create gate                |
| Support tickets index/show | SA/Admin/Support            | Category/priority/status/assignee                           |
| Ticket activity            | Same                        | Notes + status transitions                                  |
| Support dashboard          | SA/Admin/Support            | Totals / overdue / assigned-to-me                           |
| Internal users (minimal)   | SA only                     | Exists under D2 Internal admin; link only                   |

## 2. Brand / UX

TruckBill design system (Poppins, green→blue, light surfaces). Permission-aware actions; mandatory remarks on terminal transitions; empty/loading/error states; responsive.

## 3. Non-goals

- Logistics Enquiry UI (Phase 4)
- Unapproved WhatsApp/SMS delivery (Phase 9)
- Invented revenue metrics for Sales/Support
- Bypassing company §7 Pending fields via “quick convert”

## 4. Approval

Product Owner: mark `PO-P2A-DESIGN-FORMAL` Approved (with any exceptions) in `PHASE_1_TO_5_CONSOLIDATED_PO_DECISIONS.md` / Memory before UI coding.
