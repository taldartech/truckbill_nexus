# G — Reusable Component Mapping

**Status:** Proposed for Product Owner design review  
**Rule:** Prefer existing Phase 2 components. Do not invent parallel libraries.

## Shell / navigation

| Need          | Reuse                                                            |
| ------------- | ---------------------------------------------------------------- |
| Page chrome   | `layouts/app-layout.tsx` / `app-sidebar-layout`                  |
| Sidebar items | `NavMain`, `AppSidebar` (extend Masters + Subscription children) |
| Switcher      | `CompanySwitcher`                                                |
| Breadcrumbs   | `components/breadcrumbs.tsx` / `ui/breadcrumb`                   |
| Status        | `WorkspaceStatusBadge`                                           |
| Empty         | `ShellEmptyState`                                                |
| Flash         | existing flash + `sonner`                                        |

## Forms and inputs

| Need                           | Reuse                                               |
| ------------------------------ | --------------------------------------------------- |
| Text / number                  | `ui/input`, `Label`, `InputError`                   |
| Select                         | `ui/select`                                         |
| Checkbox                       | `ui/checkbox`                                       |
| Buttons                        | `ui/button`                                         |
| Dialogs                        | `ui/dialog`                                         |
| Sheets (mobile filters / docs) | `ui/sheet`                                          |
| Inertia forms                  | `<Form>` / `useForm` (post-approval implementation) |

## Data display

| Need             | Reuse / thin wrapper                                                     |
| ---------------- | ------------------------------------------------------------------------ |
| Cards            | Prefer bordered sections like Owner settings — avoid card soup in heroes |
| Badges           | `ui/badge`                                                               |
| Skeleton         | `ui/skeleton`                                                            |
| Spinner          | `ui/spinner`                                                             |
| Alert            | `ui/alert`, `alert-error`                                                |
| Dropdown actions | `ui/dropdown-menu`                                                       |
| Tooltip          | `ui/tooltip`                                                             |

## New thin domain components (only if needed after approval)

| Component           | Purpose              | Notes                  |
| ------------------- | -------------------- | ---------------------- |
| `MasterScopeBadge`  | Shared / This branch | Wrapper around `Badge` |
| `RoleChipGroup`     | Party roles          |                        |
| `MaskedAccount`     | Bank number display  |                        |
| `ScanStatusBadge`   | File scan states     | Text + icon            |
| `AttachmentPanel`   | List/upload/replace  | Composes Dialog/Sheet  |
| `DiscountBreakdown` | Pricing calculator   | Presentational         |
| `QuotaMeter`        | Storage/quota        | Simple progress + text |

Do **not** add a new design-system package. Implement wrappers under `resources/js/components/` when UI is authorized.

## Wayfinder

Post-approval, wire pages via `@/actions` / `@/routes` generated controllers — no hardcoded URLs.
