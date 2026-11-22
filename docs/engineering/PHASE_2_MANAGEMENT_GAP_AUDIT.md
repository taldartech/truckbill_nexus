# Phase 2 Management Gap Audit

**Date:** 23 November 2026  
**Baseline HEAD (pre-work):** `434c32c34d34cd7544bde3846d1b1b073c8f9d5f`  
**Completion HEAD:** `9b20a79b747d29dd5a1f30a6670a2bb4b1d3ffdd`  
**Purpose:** Distinguish completed infrastructure / accepted Phase 2 surfaces from **end-user management** completeness for Company, Branch, and Owner administration.  
**Rule:** Does not rewrite `PHASE_2_ACCEPTANCE_RECORD.md` or historical approvals. Formal Phase 2 acceptance remains **APPROVED WITH DOCUMENTED EXCEPTIONS**.

---

## 1. Verdict (management scope)

| Area                                   | Status                                        | Notes                                                                                                        |
| -------------------------------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Company creation (PRD §7 full)         | **Requires PO decision**                      | Backend name-only `createCompany`; no Customer HTTP create; legal/tax/address/GST/banking/visibility Pending |
| Company profile edit (name)            | **Complete**                                  | Owner settings                                                                                               |
| Company list / switcher                | **Complete**                                  | Workspace switcher + Owner context                                                                           |
| Branch list / enter                    | Was **Partial** → **Complete** (this task)    | Was enter-only; now full Owner branch admin                                                                  |
| Branch create / edit / deactivate      | Was **Missing UI** → **Complete** (this task) | Services existed; Owner UI+HTTP+capacity+member assign on create                                             |
| Owner users / invites / roles          | **Complete**                                  | Existing Owner users screens                                                                                 |
| Membership reactivation                | **Deferred**                                  | Acceptance exception                                                                                         |
| Ownership transfer UI                  | **Pending design**                            | Backend HTTP exists; Internal Team IA OOS                                                                    |
| Internal company/identity lifecycle UI | **Pending design**                            | Backend HTTP exists; Internal Team IA OOS                                                                    |
| Owner Panel nav (Users/Settings/Audit) | Was **Partial** → **Complete** (this task)    | Sidebar now includes approved entries                                                                        |

**Overall management completion:** **Not complete** for full PRD §7 company provisioning and Internal lifecycle UI. **Authorized Owner Branch + existing Owner membership management is production-ready** after this task.

---

## 2. Gap matrix

| #   | Requirement                                          | Decision / ref            | Service                                              | Controller / HTTP               | React screen            | AuthZ                 | Tests                           | Status                               |
| --- | ---------------------------------------------------- | ------------------------- | ---------------------------------------------------- | ------------------------------- | ----------------------- | --------------------- | ------------------------------- | ------------------------------------ |
| 1   | Company create (full PRD §7)                         | PRD §7; legal/tax Pending | `CompanyWorkspaceService::createCompany` (name + HO) | No Customer create route        | Missing                 | Owner / TBD           | Domain tests only               | **Requires PO decision**             |
| 2   | Company profile name edit                            | PO-P2-WS8 follow-on       | `updateCompany`                                      | `OwnerSettingsController`       | `owner/settings`        | Owner full            | `OwnerPanelManagementTest`      | **Complete**                         |
| 3   | Company switcher / multi-company                     | PO-P2-DESIGN-SWITCHER     | `CompanyAccessService`                               | Workspace enter routes          | `CompanySwitcher`       | Membership / Owner    | Workspace tests                 | **Complete**                         |
| 4   | First-company trial eligibility                      | Phase 3 subscription      | Contract services                                    | Owner subscription              | Subscription UI         | Owner                 | Phase 3 tests                   | **Complete** (infra; not reinvented) |
| 5   | Branch list                                          | OWNER_PANEL_IA §3.3       | Navigation / Branch query                            | `OwnerBranchesController@index` | `owner/branches/index`  | Owner scope           | `OwnerBranchAdministrationTest` | **Complete**                         |
| 6   | Branch create + existing users/roles                 | PRD §3; IA                | `OwnerBranchAdministrationService`                   | `store`                         | `owner/branches/create` | Owner full + capacity | Same                            | **Complete**                         |
| 7   | Branch edit (no users)                               | PRD §3                    | `updateBranch`                                       | `update`                        | `owner/branches/show`   | Owner full            | Same                            | **Complete**                         |
| 8   | Branch deactivate                                    | PRD / capacity            | `deactivateBranch`                                   | `deactivate`                    | show confirm            | Owner full; not last  | Same                            | **Complete**                         |
| 9   | Owner auto future branches                           | PRD §3                    | Access service                                       | N/A                             | N/A                     | Owner                 | Existing + new                  | **Complete**                         |
| 10  | Non-owner no auto new branch                         | PRD §3                    | Assignments                                          | N/A                             | N/A                     | Explicit assign       | Existing + new                  | **Complete**                         |
| 11  | Branch capacity errors                               | Phase 3 capacity          | `CompanyCapacityService`                             | store validation                | create capacity UI      | Owner                 | Capacity test                   | **Complete**                         |
| 12  | Owner users / invite / roles / deactivate membership | PO-P2-INVITE; WS-8        | Invitation / Workspace / RBAC                        | `OwnerUsersController`          | `owner/users/*`         | Owner                 | `OwnerPanelManagementTest`      | **Complete**                         |
| 13  | Membership reactivation                              | Acceptance Deferred       | Partial                                              | Missing product UI              | Missing                 | —                     | —                               | **Deferred**                         |
| 14  | Owner dashboard / subscription / audit               | Design approved           | Various                                              | Existing                        | Existing                | Owner                 | Existing                        | **Complete**                         |
| 15  | Company deactivate/reactivate (Internal)             | PO-P2-DEACT-COMPANY       | `CompanyWorkspaceService`                            | `CompanyLifecycleController`    | **No product UI**       | Super Admin           | Domain / security               | **Pending design**                   |
| 16  | Owner identity deactivate                            | PO-P2-DEACT-OWNER         | Identity services                                    | `IdentityLifecycleController`   | **No product UI**       | SA / internal         | Existing                        | **Pending design**                   |
| 17  | Ownership transfer                                   | P2-WS-7                   | Transfer services                                    | `OwnershipTransferController`   | **No product UI**       | SA                    | `OwnershipTransferTest`         | **Pending design**                   |
| 18  | Internal user CRM                                    | OOS exception             | —                                                    | Partial admin                   | None                    | —                     | —                               | **Not implemented** (gated)          |

---

## 3. Correction to UI Completion Audit narrative

Earlier UI Completion Audit rows marked Phase 2 Owner “branches” as **Complete** based on **list + Enter Branch Panel**. That was accurate for **navigation**, not for **branch administration** (create/edit/deactivate/capacity/member selection). This audit and the corrected UI Completion Audit distinguish:

- **Infrastructure complete** (services, policies, accepted Phase 2 baseline)
- **End-user management complete** (authorized CRUD workflows with UI)

Historical Phase 2 acceptance is unchanged; this is a **management completeness** follow-on under continuing Owner Panel IA approval (create/edit branch already listed as approved requirement).

---

## 4. Authorization / design gates (do not bypass)

| Gate                                                    | Status                             | Blocked work                                |
| ------------------------------------------------------- | ---------------------------------- | ------------------------------------------- |
| Company legal/tax/address/GST/banking/visibility schema | Pending historical decisions       | Full PRD §7 create UI/API fields            |
| Customer self-serve company create actor/entry          | Needs PO package                   | Public/Owner create entry point             |
| Membership reactivation                                 | Deferred                           | Reactivate membership UI                    |
| Internal Team IA                                        | Out of scope (P2 design exception) | Lifecycle / transfer / Internal CRM screens |
| AUD-CON-006 entitlements                                | Pending                            | Entitlement-matrix-dependent UX             |
| Phase 4 C–F / Enquiry product UI                        | Separate gates                     | Not in this task                            |

---

## 5. Implemented in this follow-on (authorized)

1. `OwnerBranchAdministrationService` — capacity-checked create, optional existing member+role assignment, update, deactivate (not last).
2. `OwnerBranchesController` — index/create/store/show/update/deactivate.
3. React pages `owner/branches/{index,create,show}`.
4. Owner sidebar Users / Settings / Audit links.
5. Local demo fixtures: multi-company Owner, HO-only company, deactivated company.
6. Pest `OwnerBranchAdministrationTest`.
