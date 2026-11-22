# P2-WS-8 Owner Panel Follow-on Evidence

**Date:** 22 November 2026  
**Designation:** P2-WS-8 follow-on (not P2-WS-9)  
**Authorization:** Product Owner accepted P2-WS-8 core workspace with documented exceptions and authorized Owner Panel management completion  
**Baseline HEAD before work:** `72c9d7098edc0cb58ef3315c4d4d71e035f8facc`  
**Product Owner acceptance of this follow-on:** **Not granted** by this document

## Delivered

| Area                       | Delivered                                                                                                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Company settings / profile | View status + Owner identity; edit company **name** with remark; read-only when deactivated                                                                   |
| Users & memberships        | Directory (search/filter/pagination), membership detail, invite/revoke, deactivate membership (not Owner), assign branch, assign/revoke Customer branch roles |
| Audit                      | Read-only company audit viewer with action/subject filters and pagination                                                                                     |
| Navigation                 | Dashboard Region B copy updated; deferred placeholders removed                                                                                                |

## Backend notes

- Reuses `CompanyWorkspaceService`, `InvitationService`, `RoleAssignmentService`, append-only `AuditEntry`
- `InvitationService` create/revoke authorized for **Company Owner or Super Admin** (PO-P2-INVITE / Owner Panel IA)
- Writes require Owner Panel `full` scope; deactivated company remains view-limited (`admin_billing_only`)
- Branch/company identifiers only via route model binding (`RejectClientTenantIdentifiers`)

## Explicitly deferred / blocked

| Item                                        | Reason                                |
| ------------------------------------------- | ------------------------------------- |
| Legal/tax/contact company master fields     | No approved schema/policy             |
| Visibility / numbering / documents settings | Reserved IA; not implemented          |
| Membership reactivation UI                  | No approved reactivate membership API |
| Ownership transfer UI                       | Internal Team only                    |
| Password reset / hard user delete           | Not inventable                        |
| AUD-CON-006 entitlements / checkout         | Pending / Phase 3                     |

## Tests

- `OwnerPanelManagementTest` — profile, invite/role/deactivate, cross-company isolation, deactivated read-only
- Updated `WorkspacePanelTest`, `InvitationLifecycleTest`
- Full suite: **291 tests / 289 passed / 2 skipped** (Fortify 2FA)
- `npm run build` green

## Browser QA

Interactive browser QA for this follow-on was **not** re-run in automation. Manual checklist:

1. Owner → Settings → rename company with remark
2. Owner → Users → invite (with/without branch) → accept → assign role → deactivate
3. Owner → Audit → filter and paginate
4. Member hitting Owner URLs → 403
5. Deactivated company → settings/users writes blocked; audit still readable
