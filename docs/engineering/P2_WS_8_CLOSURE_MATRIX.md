# P2-WS-8 Closure Matrix

**Date:** 22 November 2026  
**Baseline reviewed:** `a6ae398a3eb9a9baa53245f0cfe17473a77cd2c5`  
**Closure review / defect-fix commits:** recorded in Implementation Log  
**Product Owner acceptance:** **Not granted** by this document

## Verdict

### **READY WITH DOCUMENTED EXCEPTIONS**

Core authorized P2-WS-8 journeys, panels, security boundaries, and high-level subscription presentation are implemented and validated. Full Owner users/memberships/settings/audit **CRUD** remains deferred. Exact subscription entitlements remain Pending (AUD-CON-006).

---

## Owner Panel placeholder classifications

| Item                                                       | Included in approved P2-WS-8?                           | Design requires                      | Backend exists?                          | Authz enforced?        | New business rules?         | Classification                                           | Next action                                         |
| ---------------------------------------------------------- | ------------------------------------------------------- | ------------------------------------ | ---------------------------------------- | ---------------------- | --------------------------- | -------------------------------------------------------- | --------------------------------------------------- |
| Users & memberships **entry point**                        | Yes (DASH Region B; implementation auth “entry points”) | Link / shortcut                      | Invitation + membership services exist   | Owner Panel gate       | No for empty/deferred page  | **REQUIRED** (entry) — delivered as honest deferred page | Accept deferred depth or authorize CRUD slice       |
| Users & memberships **management UI**                      | Authorized in Owner Panel follow-on (22 Nov 2026)       | Directory, invite, membership, roles | Invitation + membership + role services  | Owner Panel gate       | Owner invite authz extended | **REQUIRED** — delivered in follow-on                    | PO accept follow-on                                 |
| Company profile / settings **entry point**                 | Yes (Region B)                                          | Link                                 | Company update service exists            | Owner Panel gate       | No                          | **REQUIRED** — settings hub delivered                    | PO accept follow-on                                 |
| Company profile **name editing**                           | Authorized in follow-on                                 | Schema-backed name only              | `CompanyWorkspaceService::updateCompany` | Owner Panel full scope | No legal/tax invent         | **REQUIRED** — delivered                                 | Legal/tax fields remain deferred                    |
| Company audit **entry point**                              | Yes (Region B / IA)                                     | Link                                 | Audit store exists                       | Owner Panel gate       | No                          | **REQUIRED** — delivered                                 | PO accept follow-on                                 |
| Company audit **read screen**                              | Authorized in follow-on                                 | Read-only list/filter/page           | `AuditEntry` query                       | Owner Panel gate       | No edits/deletes            | **REQUIRED** — delivered                                 | PO accept follow-on                                 |
| Misleading “Company administration” card with no action    | Violated DASH Region B                                  | Links required                       | N/A                                      | N/A                    | N/A                         | **DEFECT** — fixed                                       | Region B shortcuts + deferred pages                 |
| Empty-state copy implying branch create UI                 | Misleading                                              | Must not invent create flow          | Create branch exists backend             | N/A                    | N/A                         | **DEFECT** — fixed                                       | Honest empty copy                                   |
| `router.post` company/branch buttons unreliable in browser | Broken journey                                          | Server-validated enter               | Controllers exist                        | Yes                    | No                          | **DEFECT** — fixed                                       | Inertia `Form` + Wayfinder POST                     |
| Active company chrome lagging on first Owner GET           | UX/context defect                                       | Context indicator                    | Session + share                          | N/A                    | No                          | **DEFECT** — fixed                                       | Route-derived active company/branch in shared props |

---

## Closure matrix (approved requirement → status)

| Approved requirement                 | Implemented     | Evidence                                         | Tests                                      | Status                 | Remaining gap                       | Next action          |
| ------------------------------------ | --------------- | ------------------------------------------------ | ------------------------------------------ | ---------------------- | ----------------------------------- | -------------------- |
| Login → workspace landing            | Yes             | Fortify `home=/workspace`; browser QA            | Auth + WorkspacePanelTest                  | Met                    | —                                   | PO review            |
| Multi owned → selection / MRU        | Yes             | Select + MRU column                              | WorkspacePanelTest / NavigationServiceTest | Met                    | —                                   | PO review            |
| Member-only → Branch                 | Yes             | Landing redirect                                 | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Zero-access screen                   | Yes             | `workspace/no-access`                            | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Unified switcher Owner/Member        | Yes             | Shared companies + Form POST                     | Feature + browser                          | Met                    | —                                   | PO review            |
| Sequential Company → Branch          | Yes             | Select flows                                     | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Owner Dashboard admin/context only   | Yes             | No charts/KPIs                                   | Inertia asserts                            | Met                    | —                                   | PO review            |
| Region B shortcuts                   | Yes (after fix) | Branches/Users/Settings/Audit/Subscription links | New deferred tests                         | Met (entry)            | Full CRUD deferred                  | Documented exception |
| Deactivated company limits           | Yes             | Banner + Branch forbid                           | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Branch Panel + hide modules          | Yes             | Empty modules list                               | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Branch Settings prefs-only           | Yes             | Deferred prefs note                              | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Subscription high-level only         | Yes             | `actions_allowed=false`                          | WorkspacePanelTest                         | Met                    | AUD-CON-006                         | Exception            |
| Server authz / no client tenant IDs  | Yes             | Middleware + tests                               | WorkspacePanelTest                         | Met                    | —                                   | PO review            |
| Server MRU                           | Yes             | Migration + revalidate                           | NavigationServiceTest                      | Met                    | —                                   | PO review            |
| Responsive / mobile Owner control    | Partial         | Browser mobile viewport QA performed             | Manual checklist                           | Met for critical paths | Tablet formal matrix not exhaustive | Manual QA checklist  |
| Full Owner users/settings/audit CRUD | No              | Deferred pages                                   | Deferred tests                             | **Exception**          | CRUD screens                        | Separate auth        |
| Operational modules                  | Excluded        | Hidden                                           | Assert empty modules                       | Out of scope           | Later phases                        | —                    |
| P2-WS-9                              | Excluded        | —                                                | —                                          | Unauthorized           | —                                   | Separate auth        |

---

## Browser QA performed

| Journey                                         | Result                                                                          |
| ----------------------------------------------- | ------------------------------------------------------------------------------- |
| Login (owner multi-company)                     | Pass → owned-company selection                                                  |
| Owned company selection UI                      | Pass (Owner/Active labels)                                                      |
| Owner Dashboard (Alpha)                         | Pass; subscription disclaimer visible                                           |
| Branch Panel (Head Office)                      | Pass; modules hidden; Return to Owner Panel visible                             |
| Company switcher shows active context on Branch | Pass after route-context fix                                                    |
| POST enter via `router.post` buttons            | **Failed pre-fix** (no POST); **fixed** to Form POST                            |
| Member / zero-access / deactivated (browser)    | Covered by automated Feature tests; not fully re-walked in browser this session |
| Desktop wide layout                             | Not separately exercised (automation viewport mobile-width)                     |

Manual QA checklist for Product Owner / QA:

1. Login as multi-owner → select company → Owner Dashboard → switcher shows active company.
2. Enter Branch Panel → Settings → Return to Owner Panel.
3. Open Users / Settings / Audit → deferred empty pages (not CRUD).
4. Login as member-only → Branch Panel; Owner URLs 403.
5. Login with no companies → no-access + logout.
6. Deactivated company → Owner limited; Branch blocked.
7. Resize desktop/tablet/mobile: switcher and Owner return remain reachable.

---

## Skipped tests

| Test area                         | Count | Reason                                 | Affects P2-WS-8 acceptance?       |
| --------------------------------- | ----- | -------------------------------------- | --------------------------------- |
| `Settings/SecurityTest` 2FA cases | 2     | Fortify two-factor feature not enabled | **No** — unrelated to WS-8 panels |

Password reset Fortify feature is enabled; those tests run.

---

## Automated results (closure)

- Full suite (post-fix): **284 tests, 282 passed, 2 skipped**, 1155 assertions.
- Frontend: `npm run build` green after fixes.
