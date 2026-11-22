# Phase 2 Management Completion Plan

**Date:** 23 November 2026  
**Baseline:** `434c32c34d34cd7544bde3846d1b1b073c8f9d5f`  
**Companion audit:** `docs/engineering/PHASE_2_MANAGEMENT_GAP_AUDIT.md`

---

## 1. Goals

1. Close authorized Owner **branch administration** gaps (IA §3.3 / PRD §3) as full-stack production UI.
2. Preserve Owner users/settings/subscription/audit already delivered in P2-WS-8 follow-on.
3. Prepare Product Owner decision package for gated **company creation** (PRD §7) without inventing schema.
4. Prepare Internal lifecycle design package only — no product UI until Internal Team IA approval.
5. Extend local demo fixtures and Pest coverage; local commit only; no push.

---

## 2. Execution order (this batch)

| Step | Work                                                  | Gate                    |
| ---- | ----------------------------------------------------- | ----------------------- |
| 1    | Verify baseline HEAD / clean tree                     | Done                    |
| 2    | Gap audit matrix                                      | Done                    |
| 3    | Implement Owner branch admin service + HTTP + Inertia | Authorized (IA)         |
| 4    | Owner nav polish (Users/Settings/Audit)               | Authorized              |
| 5    | Demo seeder multi-company + deactivated               | Local-only              |
| 6    | Pest regression                                       | Done (targeted)         |
| 7    | Docs + certification                                  | This file + cert report |
| 8    | CI-equivalent validation + local commit               | Pending final SHA       |

---

## 3. Explicitly not in this batch

- Full PRD §7 company create fields / Customer create entry (PO package).
- Membership reactivation UI (Deferred).
- Internal company/identity/ownership-transfer product screens (design gate).
- Phase 4 Batches C–F, live Razorpay, Sanctum, accounting posting.
- Dependency changes.

---

## 4. Technical design (branch admin)

- Reuse `CompanyWorkspaceService` create/update/deactivate/assignBranch.
- New façade `OwnerBranchAdministrationService` for Owner authZ, capacity via `CompanyCapacityService`, optional create-time member+role assignment via `RoleAssignmentService`.
- Controllers remain thin; remarks required; transactions via existing `AuditedTransaction`.
- Create does **not** create identities; edit does **not** manage users.

---

## 5. Success criteria

- Owner can list/filter branches, create (with capacity messaging), edit name+remark, deactivate non-last, enter Branch Panel, see audit.
- Non-owners forbidden; cross-company 404; capacity exceeded returns validation error.
- Docs list remaining PO decisions accurately; historical Phase 2 acceptance unchanged.
