# Product Owner Decision Package — Company Creation (PRD §7)

**Status:** **Pending Product Owner decisions** — do not implement gated fields until approved.  
**Date:** 23 November 2026  
**Related:** PRD §7; Phase 2 acceptance exceptions; `PHASE_2_MANAGEMENT_GAP_AUDIT.md`

---

## 1. Current authorized implementation

| Capability                                                                  | State                                                                  |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `CompanyWorkspaceService::createCompany($owner, $name, $initialBranchName)` | Creates company + Owner membership + initial branch atomically; audits |
| First-company trial / subscription common account                           | Handled by Phase 3 subscription services (do not re-invent)            |
| Owner company profile **name** edit                                         | Owner Settings (approved WS-8 follow-on)                               |
| Customer HTTP/Inertia company **create** entry                              | **Not implemented** (gated)                                            |

---

## 2. Decisions required (exact)

| ID (proposed)           | Question                                                                      | Options / notes                                                                                            | Blocks                     |
| ----------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | -------------------------- |
| PO-P2-CO-CREATE-ACTOR   | Who may create a company in product UI?                                       | (A) Existing Customer creating additional company (B) Internal-assisted only (C) Both with different paths | Entry route + authZ        |
| PO-P2-CO-CREATE-ENTRY   | Entry point                                                                   | Owner Panel “Add company” vs Workspace landing vs Internal-only                                            | Navigation                 |
| PO-P2-CO-LEGAL-SCHEMA   | Persist PAN, registered address, state/city/PIN, contact person/mobile/email? | Approve column set + validation regex; or defer subset                                                     | Migrations + forms         |
| PO-P2-CO-GST-SCHEMA     | GST registration type, tax liability, GSTIN, GST rate snapshot?               | Approve; Composition/Unregistered rules                                                                    | Migrations + forms         |
| PO-P2-CO-BANK-ON-CREATE | Optional bank fields create first Bank Account master when complete?          | Approve / defer to Masters only                                                                            | Create transaction scope   |
| PO-P2-CO-VISIBILITY     | Data Visibility Shared vs Branch-wise Restricted on create?                   | Approve enums + numbering defaults; or Deferred                                                            | Masters/numbering coupling |
| PO-P2-CO-DIGITAL-ASSETS | Digital signature image + company logo upload on create?                      | Approve storage rules / defer                                                                              | File pipeline              |
| PO-P2-CO-WITHOUT-BRANCH | May company exist without branch (PRD wording)?                               | Confirm vs current always-create initial branch                                                            | createCompany semantics    |
| PO-P2-CO-DUPLICATE-PAN  | Same PAN across companies allowed (PRD)?                                      | Confirm uniqueness policy                                                                                  | Validation                 |

---

## 3. Recommended interim (engineering)

Until decisions are **Approved**:

1. Keep `createCompany` as the sole provisioning API for tests/seeders/Internal tooling.
2. Do **not** ship a Customer “Create company” form with invented columns.
3. Continue Owner **name** profile edit only.
4. Demo seeder may create multiple companies via service (already).

---

## 4. Acceptance criteria after approval

- Atomic company + Owner membership + initial branch (or approved no-branch policy).
- No partial provision on validation failure.
- Audit + remarks per DEC-003 / audit rules.
- Trial eligibility preserved for first owned company.
- Tenant isolation and Owner designation invariants unchanged.
