# Global UI Consistency Audit

**Date:** 23 November 2026
**Feature baseline:** session routing + icon/date foundations
**Verdict:** PARTIALLY IMPLEMENTED — routing/session/date foundations landed; full page-by-page icon/layout remediation ongoing

| Page                                     | Module        | Layout    | Width | Icons | Dates | Status      | Notes                                     |
| ---------------------------------------- | ------------- | --------- | ----- | ----- | ----- | ----------- | ----------------------------------------- |
| `admin/companies/index.tsx`              | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/companies/show.tsx`               | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/crm/leads/create.tsx`             | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/crm/leads/index.tsx`              | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/crm/leads/show.tsx`               | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/crm/tickets/create.tsx`           | admin         | AppLayout | TBD   | TBD   | TBD   | Remediated  | PageShell form sections + Save icon       |
| `admin/crm/tickets/index.tsx`            | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/crm/tickets/show.tsx`             | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/gst-rates.tsx`                    | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/identities/index.tsx`             | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/identities/show.tsx`              | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/internal-users/index.tsx`         | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/subscription-invoices.tsx`        | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `admin/tds-rules.tsx`                    | admin         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `auth/accept-invitation.tsx`             | auth          | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `auth/forgot-password.tsx`               | auth          | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `auth/login.tsx`                         | auth          | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `auth/reset-password.tsx`                | auth          | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/dashboard.tsx`                   | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/enquiries/create.tsx`            | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/enquiries/index.tsx`             | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/enquiries/show.tsx`              | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/bank-cash/form.tsx`      | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/bank-cash/index.tsx`     | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/bank-cash/show.tsx`      | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/catalogues/index.tsx`    | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/documents/index.tsx`     | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/index.tsx`               | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/parties/form.tsx`        | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/parties/index.tsx`       | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/parties/show.tsx`        | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/stations/form.tsx`       | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/stations/index.tsx`      | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/stations/show.tsx`       | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/vehicles/form.tsx`       | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/vehicles/index.tsx`      | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/masters/vehicles/show.tsx`       | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `branch/settings.tsx`                    | branch        | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `dashboard.tsx`                          | dashboard.tsx | AppLayout | TBD   | TBD   | TBD   | Pending     |                                           |
| `owner/audit/all.tsx`                    | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/audit.tsx`                        | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/branches/all.tsx`                 | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/branches/create.tsx`              | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/branches/index.tsx`               | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/branches/show.tsx`                | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/companies/create.tsx`             | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/companies/index.tsx`              | owner         | AppLayout | TBD   | TBD   | TBD   | Remediated  | WorkspaceContextAction icons; date util   |
| `owner/dashboard.tsx`                    | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/home.tsx`                         | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/settings.tsx`                     | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/catalogue.tsx`       | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/checkout.tsx`        | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/credits.tsx`         | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/draft-edit.tsx`      | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/drafts.tsx`          | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/invoice-preview.tsx` | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/invoices.tsx`        | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/overview.tsx`        | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscription/payments.tsx`        | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/subscriptions/index.tsx`          | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/users/all.tsx`                    | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/users/index.tsx`                  | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `owner/users/show.tsx`                   | owner         | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `settings/appearance.tsx`                | settings      | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `settings/profile.tsx`                   | settings      | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `settings/security.tsx`                  | settings      | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `welcome.tsx`                            | welcome.tsx   | AppLayout | TBD   | TBD   | TBD   | Pending     |                                           |
| `workspace/no-access.tsx`                | workspace     | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `workspace/select-branch.tsx`            | workspace     | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
| `workspace/select-company.tsx`           | workspace     | AppLayout | TBD   | TBD   | TBD   | In progress | Shared standards apply; verify per screen |
