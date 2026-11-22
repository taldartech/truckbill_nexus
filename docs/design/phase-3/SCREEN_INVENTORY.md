# A — Phase 3 Screen Inventory

**Status:** Proposed for Product Owner design review  
**Backend binding:** Batch A (`Party`, subscription) + Batch B (Vehicle, Bank/Cash, Station, catalogues, documents, GST/TDS)

## 1. Branch Panel — Masters

| ID      | Screen                      | Route proposal (post-approval)             | Primary actors      | Backend                          |
| ------- | --------------------------- | ------------------------------------------ | ------------------- | -------------------------------- |
| BP-M-00 | Masters hub                 | `/branch/.../masters`                      | View+               | Nav only                         |
| BP-M-01 | Party list                  | `/branch/.../masters/parties`              | View                | `PartyService::search`           |
| BP-M-02 | Party create                | `/branch/.../masters/parties/create`       | Create              | `PartyService::create`           |
| BP-M-03 | Party detail                | `/branch/.../masters/parties/{party}`      | View                | Party + roles                    |
| BP-M-04 | Party edit                  | `/branch/.../masters/parties/{party}/edit` | Edit                | `PartyService::update`           |
| BP-M-05 | Party role manage           | Panel on detail/edit                       | Edit                | `addRole` / `removeRole`         |
| BP-M-10 | Vehicle list                | `/branch/.../masters/vehicles`             | View                | `VehicleService::search`         |
| BP-M-11 | Vehicle create              | `.../vehicles/create`                      | Create              | `VehicleService::create`         |
| BP-M-12 | Vehicle detail / edit       | `.../vehicles/{vehicle}` (+ edit)          | View / Edit         | update / softDelete              |
| BP-M-20 | Bank & cash list            | `.../masters/accounts`                     | View                | `BankCashAccountService::search` |
| BP-M-21 | Bank / cash create / edit   | create + detail/edit                       | Create / Edit       | create / update / softDelete     |
| BP-M-30 | Station list                | `.../masters/stations`                     | View                | `StationService::search`         |
| BP-M-31 | Station create / edit       | create + detail                            | Owner Create / Edit | create / update / softDelete     |
| BP-M-32 | Station default transporter | Panel on station detail                    | Owner Edit          | `setDefaultTransporter`          |
| BP-M-40 | Supporting masters hub      | `.../masters/catalogues`                   | View                | `OwnerCatalogueService`          |
| BP-M-41 | Catalogue type list / form  | `.../masters/catalogues/{type}`            | Owner Create / Edit | create / update / softDelete     |
| BP-M-50 | Payment modes (read-only)   | Embedded on supporting masters             | View                | `listPaymentModes`               |

## 2. Branch Panel — Documents

| ID      | Screen                 | Placement                                              | Backend                                |
| ------- | ---------------------- | ------------------------------------------------------ | -------------------------------------- |
| BP-D-01 | Attachments panel      | Embedded on Party / Vehicle / Account / Station detail | `listForParent`                        |
| BP-D-02 | Upload dialog          | Modal from attachments panel                           | `uploadAndAttach`                      |
| BP-D-03 | Preview / download     | Drawer or new tab (authorized stream)                  | `download`                             |
| BP-D-04 | Version history        | Drawer section                                         | list with `currentOnly=false`          |
| BP-D-05 | Replace file           | Action on current attachment                           | `replace`                              |
| BP-D-06 | Soft-delete attachment | Confirm dialog                                         | `softDelete`                           |
| BP-D-07 | Storage usage chip     | Detail header + Owner subscription (read)              | `storageUsage` / reconcile foundations |

Documents are **not** a global dump of all company files. Entry is always from an authorized parent record. **Proposal.**

## 3. Owner Panel — Subscription

| ID      | Screen                  | Route proposal                                              | Backend / notes                        |
| ------- | ----------------------- | ----------------------------------------------------------- | -------------------------------------- |
| OP-S-00 | Subscription overview   | `/owner/companies/{company}/subscription` (extend existing) | Contract status, renewal date, modules |
| OP-S-01 | Plan & module catalogue | `/owner/.../subscription/catalogue`                         | Catalogue read                         |
| OP-S-02 | Add-ons & capacity      | Tab or section on catalogue                                 | Add-ons / capacity lines               |
| OP-S-03 | Pricing calculator      | `/owner/.../subscription/pricing`                           | `SubscriptionPricingCalculator`        |
| OP-S-04 | Draft contract review   | `/owner/.../subscription/contracts/{id}`                    | Draft + immutable snapshot preview     |
| OP-S-05 | Credits & quotas        | Section on overview + dedicated page optional               | Credit ledger / quota foundations      |

**Pending:** Exact trial/grace/restricted/suspended controls (AUD-CON-006). Show Confirmed status labels only; no invented lockouts. **Approved requirement.**

## 4. Owner / Internal — Tax masters (read / mutate)

| ID      | Screen                   | Notes                                                            |
| ------- | ------------------------ | ---------------------------------------------------------------- |
| IN-T-01 | GST rate list (Internal) | Central; mutate Internal + `platform.operations.manage_settings` |
| IN-T-02 | TDS rule list / editor   | Central; structured conditions UI                                |
| BP-T-01 | Active GST rates (read)  | Optional Branch reference picker when transactions exist (later) |

Customer Branch Panel does **not** edit central GST/TDS. **Approved requirement.**

## 5. Billing (future — implementation gated)

See `BILLING_FUTURE_SCREENS.md`. Screens OP-B-01…OP-B-06 are specified for design continuity but require **PO-P3-PAY** / G-PAY before implementation.

## 6. Explicitly not in Phase 3 UI

| Screen idea                           | Reason                            |
| ------------------------------------- | --------------------------------- |
| Broker / OwnFleet / Partload ops      | Phases 4–7                        |
| Accounting ledger / posting           | ADR-010 Pending                   |
| OwnFleet maintenance / tyre / battery | Premature vs Vehicle master scope |
| Entitlement lockdown matrices         | AUD-CON-006 Pending               |
| Live Razorpay hosted checkout         | G-PAY / PO-P3-PAY                 |
