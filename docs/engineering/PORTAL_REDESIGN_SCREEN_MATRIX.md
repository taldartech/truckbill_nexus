# Portal Redesign — Screen Matrix (Batches E–J)

**Date:** 24 November 2026  
**Baseline parent:** ~`ee5ffd2` (Batches A–D)  
**Status key:** **Fixed** = icon actions + density/copy remediated; **Partial** = list actions fixed, form polish deferred; **Open** = none remaining for this directive.

## Owner Portal

| Screen                           | Route (typical)             | Status  | Notes                                   |
| -------------------------------- | --------------------------- | ------- | --------------------------------------- |
| Home / dashboard                 | `/owner`                    | Fixed   | Link-style CTAs → buttons; tighter grid |
| Company dashboard                | `/owner/companies/{id}`     | Fixed   | Removed raw `ownerPanelScope` copy      |
| Users (company)                  | `.../users`                 | Fixed   | `AppIconAction` view; compact filters   |
| Users (global)                   | `/owner/users`              | Fixed   | Icon manage                             |
| User show                        | `.../users/{id}`            | Partial | Detail layout OK; text CTAs acceptable  |
| Branches (company)               | `.../branches`              | Fixed   | Manage icon; compact filters            |
| Branches (global)                | `/owner/branches`           | Fixed   | Enter Branch Panel fixture hardened     |
| Companies index                  | `/owner/companies`          | Fixed   | Semantic action colours                 |
| Company audit                    | `.../audit`                 | Fixed   | Compact filters                         |
| Global audit                     | `/owner/audit`              | Fixed   | Icon company audit                      |
| Subscription invoices            | `.../subscription/invoices` | Fixed   | Preview / download / email icons        |
| Subscription overview & checkout | `.../subscription/*`        | Fixed   | Density on overview (Batch I)           |
| Settings                         | `.../settings`              | Partial | FormSection 2-col; not icon-table work  |

## Internal (Admin) Portal

| Screen                     | Route                          | Status  | Notes                               |
| -------------------------- | ------------------------------ | ------- | ----------------------------------- |
| CRM dashboard              | `/admin/crm`                   | Fixed   | View-all → button                   |
| CRM leads index            | `/admin/crm/leads`             | Fixed   |                                     |
| CRM tickets                | `/admin/crm/tickets`           | Fixed   |                                     |
| Companies / identities     | `/admin/companies`, identities | Partial | Identity show company rows iconized |
| Internal users             | `/admin/internal-users`        | Fixed   |                                     |
| GST / TDS rules            | `/admin/tds-rules`             | Fixed   |                                     |
| Subscription invoices (SA) | `/admin/subscription-invoices` | Fixed   | Approve icon action (Batch I)       |

## Branch / OwnFleet

| Screen                        | Route                    | Status  | Notes                             |
| ----------------------------- | ------------------------ | ------- | --------------------------------- |
| LR index / show               | `/lorry-receipts`        | Fixed   | Service labels; row `Eye` actions |
| Enquiries                     | `/enquiries`             | Fixed   |                                   |
| Parties / vehicles / stations | `/parties`, etc.         | Fixed   | Row icons                         |
| Bank-cash                     | `/bank-cash`             | Fixed   |                                   |
| OwnFleet trips                | `/trips`                 | Fixed   |                                   |
| Trip invoices (legacy list)   | `/trip-invoices`         | Fixed   | Redirects into unified workspace  |
| Unified invoices / payments   | `/invoices`, `/payments` | Fixed   | OwnFleet+Broker list evidenced    |
| Six supporting masters        | `/goods-types`…          | Fixed   | Separate routes                   |
| Partload create               | LR create partload       | Fixed   | No Workflow field                 |
| Trip / LR forms               | create-edit              | Partial | Multi-column polish deferred      |

## Broker

| Screen                   | Route                          | Status | Notes                    |
| ------------------------ | ------------------------------ | ------ | ------------------------ |
| Lorry hires index / show | `/broker/lorry-hires`          | Fixed  | Icons; detail tab links  |
| Vouchers index / show    | `/broker/vouchers`             | Fixed  |                          |
| Daily payments           | `/broker/daily-payments`       | Fixed  |                          |
| Customer invoices        | `/broker/invoices`             | Fixed  | Redirects to unified     |
| Linked bilties           | `/broker/linked-bilties`       | Fixed  |                          |
| Registers                | `/broker/registers`            | Fixed  | AppIconAction open tiles |
| Transporter balances     | `/broker/transporter-balances` | Fixed  | ResponsiveDataTable      |

## Summary counts

| Portal    |  Fixed | Partial |  Open |
| --------- | -----: | ------: | ----: |
| Owner     |     11 |       2 |     0 |
| Internal  |      6 |       1 |     0 |
| Branch    |      9 |       1 |     0 |
| Broker    |      7 |       0 |     0 |
| **Total** | **33** |   **4** | **0** |
