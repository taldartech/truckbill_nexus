# Phase 2A Authorization Request — Internal CRM & Customer Onboarding

**Status:** **APPROVED** via `PO-P1-P5-FULLSTACK-AUTH` / `PHASE_2A_AUTHORIZATION_RECORD.md` (23 November 2026). Historical request retained.  
**Date:** 23 November 2026  
**Baseline at request:** `f1a12011ad172399d97e7d935dc15bf3713d3e8a`  
**Governing:** `Phases.md` Phase 2A; PRD §5–§6; Identity/Company/Subscription foundations

---

## 1. Request

| Decision ID              | Requested status                | Meaning                                                                   |
| ------------------------ | ------------------------------- | ------------------------------------------------------------------------- |
| **PO-P2A-AUTH**          | Approve / Reject / Defer        | Authorize backend + full-stack CRM implementation under execution plan    |
| **PO-P2A-DESIGN-FORMAL** | Approve WITH/WITHOUT exceptions | Authorize product UI for Lead / Sales / Onboarding / Support / dashboards |

Adding Phase 2A to `Phases.md` **does not** grant these Approvals.

---

## 2. Scope (when approved)

1. Lead management (list/create/detail/edit, source/product/status, assignment, follow-ups, timeline, duplicates).
2. Sales pipeline views + role-scoped metrics from real records.
3. Customer onboarding: Lead → Qualification → Conversion → Identity (reuse, no password overwrite) → Company (via approved Company services) → Subscription/trial.
4. Support tickets (lifecycle, assignment, overdue, dashboards).
5. Internal CRM dashboards per PRD §6 (revenue only SA/Admin).
6. Pest (+ Playwright after D7) for authZ boundaries.

**Out of scope:** Logistics Enquiry (Phase 4); Sales CRM ≠ Internal lifecycle admin (D2); live comms (Phase 9); inventing pipeline statuses.

---

## 3. Hard dependencies

| Dependency                     | Status              | Impact                                                                                            |
| ------------------------------ | ------------------- | ------------------------------------------------------------------------------------------------- |
| Phase 2 Identity/RBAC          | Accepted            | Required                                                                                          |
| Company create PRD §7 (D1)     | **Pending**         | Onboarding must not bypass; may create Customer identity first and company via approved path only |
| Phase 3 subscription reporting | Accepted WITH EX    | Revenue widgets respect EX / AUD-CON-006                                                          |
| Lead/ticket lifecycle enums    | **Need PO** (below) | Blocked until decided                                                                             |
| Playwright/k6 (D7)             | Pending             | Browser/perf cert deferred                                                                        |

---

## 4. Material CRM decisions required with AUTH

| ID                     | Question                            | Recommendation                                                                                                           |
| ---------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| PO-P2A-LEAD-STATUSES   | Exact lead status set + transitions | New → Contacted → Qualified → Converted / Disqualified / Lost (with remark on terminal)                                  |
| PO-P2A-LEAD-ASSIGN     | Who may assign                      | SA/Admin any; Sales may reassign within team if configured — default: SA/Admin + Sales Manager only                      |
| PO-P2A-CONVERT         | Conversion rules                    | Qualified only; one active conversion; reuse Customer by email; never overwrite password; company via D1-approved create |
| PO-P2A-TICKET-STATUSES | Ticket lifecycle                    | Open → In Progress → On Hold → Resolved → Closed                                                                         |
| PO-P2A-CRM-ACCESS      | Permission matrix                   | Align PRD §6 dashboards; separate ticket action perms                                                                    |
| PO-P2A-DUPLICATE       | Duplicate lead detection            | Soft-block same email+company interest within N days; PO confirm N (recommend 30)                                        |

---

## 5. Recommended Product Owner reply

```
PO-P2A-AUTH: APPROVED | REJECTED | DEFERRED
PO-P2A-DESIGN-FORMAL: APPROVED | APPROVED WITH EXCEPTIONS | REJECTED
PO-P2A-LEAD-STATUSES: <accept recommendation / list>
PO-P2A-LEAD-ASSIGN: <accept recommendation / alternate>
PO-P2A-CONVERT: <accept recommendation / alternate>
PO-P2A-TICKET-STATUSES: <accept recommendation / list>
PO-P2A-CRM-ACCESS: <accept PRD §6 / exceptions>
PO-P2A-DUPLICATE: <accept 30-day / other>
```

Until Approved, engineering will **not** create CRM tables, routes, or Inertia pages.
