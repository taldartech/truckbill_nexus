# Portal Redesign — Screen Matrix (Batches E–H)

**Date:** 24 November 2026  
**Baseline parent:** ~`ee5ffd2` (Batches A–D)  
**Status key:** **Fixed** = icon actions + density/copy remediated in this pass; **Partial** = list actions fixed, forms/detail polish deferred; **Open** = not audited or deferred to Batch I–J.

## Owner Portal

| Screen                           | Route (typical)             | Batch E status | Notes                                   |
| -------------------------------- | --------------------------- | -------------- | --------------------------------------- |
| Home / dashboard                 | `/owner`                    | Fixed          | Link-style CTAs → buttons; tighter grid |
| Company dashboard                | `/owner/companies/{id}`     | Fixed          | Removed raw `ownerPanelScope` copy      |
| Users (company)                  | `.../users`                 | Fixed          | `AppIconAction` view; compact filters   |
| Users (global)                   | `/owner/users`              | Fixed (A–D)    | Prior batch                             |
| User show                        | `.../users/{id}`            | Partial        | No table actions; layout OK             |
| Branches (company)               | `.../branches`              | Fixed          | Manage icon; compact filters            |
| Branches (global)                | `/owner/branches`           | Fixed (A–D)    | Prior batch                             |
| Companies index                  | `/owner/companies`          | Fixed (A–D)    | Prior batch                             |
| Company audit                    | `.../audit`                 | Fixed          | `FormSection` filters                   |
| Global audit                     | `/owner/audit`              | Fixed (A–D)    | Prior batch                             |
| Subscription invoices            | `.../subscription/invoices` | Fixed          | Preview / download / email icons        |
| Subscription overview & checkout | `.../subscription/*`        | Open           | Batch I visual cert                     |
| Settings / drafts                | `.../settings`, drafts      | Open           | Low traffic                             |

## Internal (Admin) Portal

| Screen                     | Route                          | Batch F status | Notes                               |
| -------------------------- | ------------------------------ | -------------- | ----------------------------------- |
| CRM dashboard              | `/admin/crm`                   | Fixed          | View-all link → button              |
| CRM leads index            | `/admin/crm/leads`             | Fixed (A–D)    | Prior batch                         |
| CRM tickets                | `/admin/crm/tickets`           | Fixed (A–D)    | Prior batch                         |
| Companies / identities     | `/admin/companies`, identities | Partial        | Identity show company rows iconized |
| Internal users             | `/admin/internal-users`        | Fixed (A–D)    | Prior batch                         |
| GST / TDS rules            | `/admin/tds-rules`             | Fixed (A–D)    | Prior batch                         |
| Subscription invoices (SA) | `/admin/subscription-invoices` | Open           | Batch I                             |

## Branch / OwnFleet

| Screen                        | Route                    | Batch G status | Notes                                 |
| ----------------------------- | ------------------------ | -------------- | ------------------------------------- |
| LR index / show               | `/lorry-receipts`        | Fixed          | Service labels; row `Eye` actions     |
| Enquiries                     | `/enquiries`             | Fixed (A–D)    | Prior batch                           |
| Parties / vehicles / stations | `/parties`, etc.         | Fixed          | Row icons; primary cell non-underline |
| Bank-cash                     | `/bank-cash`             | Fixed          | Added view actions                    |
| OwnFleet trips                | `/trips`                 | Fixed          | Actions column                        |
| Trip invoices (legacy list)   | `/trip-invoices`         | Fixed          | Open → icon                           |
| Unified invoices / payments   | `/invoices`, `/payments` | Fixed (A–D)    | Prior batch                           |
| Trip / LR forms               | create-edit              | Partial        | Multi-column polish deferred          |

## Broker

| Screen                   | Route                          | Batch H status | Notes                   |
| ------------------------ | ------------------------------ | -------------- | ----------------------- |
| Lorry hires index / show | `/broker/lorry-hires`          | Fixed          | Icons; detail tab links |
| Vouchers index / show    | `/broker/vouchers`             | Fixed          |                         |
| Daily payments           | `/broker/daily-payments`       | Fixed          |                         |
| Customer invoices        | `/broker/invoices`             | Fixed          | Actions column          |
| Linked bilties           | `/broker/linked-bilties`       | Fixed          |                         |
| Registers                | `/broker/registers`            | Open           | Read-only; Batch I      |
| Transporter balances     | `/broker/transporter-balances` | Open           | Batch I                 |

## Summary counts (this remediation pass)

| Portal    | Fixed  | Partial | Open  |
| --------- | ------ | ------- | ----- |
| Owner     | 9      | 2       | 3     |
| Internal  | 1      | 2       | 1     |
| Branch    | 8      | 1       | 0     |
| Broker    | 6      | 0       | 2     |
| **Total** | **24** | **5**   | **6** |

Batch I–J: Playwright visual certification (`PORTAL_PLAYWRIGHT_VISUAL_CERTIFICATION.md`), remaining Open rows, and final PO sign-off.
