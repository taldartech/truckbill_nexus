# Design Package — Internal Lifecycle Administration UI

**Status:** **Pending Product Owner / design approval** (Internal Team IA remains out of scope of PO-P2-DESIGN-FORMAL customer package).  
**Date:** 23 November 2026  
**Backend already exists:** `CompanyLifecycleController`, `IdentityLifecycleController`, `OwnershipTransferController`, `InternalUserController` (HTTP; no product Inertia screens).

---

## 1. Purpose

Request formal design approval before exposing production Internal Team UI for:

1. Company deactivate / reactivate (mandatory remark, confirmation, audit).
2. Owner / Customer identity deactivate / reactivate.
3. Ownership transfer (preserve company data, memberships, subscriptions).
4. Internal user list/detail (non-CRM; no unapproved Internal CRM module).

---

## 2. Proposed surfaces (for approval)

| Screen                    | Actors                            | Actions                                                            | Required UX                                       |
| ------------------------- | --------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------- |
| Companies lifecycle list  | Super Admin / authorized Internal | Search, status filter, open detail                                 | Permission-aware                                  |
| Company lifecycle detail  | Same                              | Deactivate / reactivate with remark + confirm; show status + audit | Success/error flash                               |
| Identities lifecycle list | Same                              | Search Customer/Internal                                           | No self-serve deletion                            |
| Identity detail           | Same                              | Deactivate / reactivate; show companies owned                      | Session revoke messaging                          |
| Ownership transfer        | Super Admin                       | Select new Owner (existing Customer), remark, confirm              | Preserve data/memberships/subscriptions per Rules |
| Internal users (minimal)  | Super Admin                       | List active Internal; no CRM pipeline                              | Explicitly **not** sales CRM                      |

---

## 3. Non-goals until approved

- Product Inertia pages under `/admin/...` for the above.
- Inventing Internal CRM / sales pipeline.
- Hard delete / restore (DEC-003).
- Changing PO-P2-DEACT-* policies.

---

## 4. Decision request

| ID (proposed)          | Decision                                                                                |
| ---------------------- | --------------------------------------------------------------------------------------- |
| PO-P2-INTERNAL-IA      | Approve Internal Team information architecture package (this document + wireframes TBD) |
| PO-P2-INTERNAL-UI-AUTH | Authorize implementation batch after IA approval                                        |

**Until Approved:** keep HTTP/backend only; do not expose production UI.
