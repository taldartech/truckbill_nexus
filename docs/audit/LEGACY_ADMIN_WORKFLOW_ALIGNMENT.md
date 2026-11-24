# Legacy Admin (Control Plane) Workflow Alignment

**Audit date:** 24 November 2026  
**Legacy repo:** `truckbill_admin` @ **`b951db3355cc7bc7b1af1898c61d358bdec99ab0`** (main)  
**Legacy read-only path:** `legacy/truckbill_admin/`  
**Nexus HEAD:** `e05d1d6f505ac076963ede60b86e4ebe062e2edb`

---

## Purpose

Legacy **truckbill_admin** is the internal CRM + subscription control plane for all product apps (Broker, OwnFleet, Partload). Nexus embeds **subset** capabilities under `/admin/*` and moves customer subscription to **Owner** routes.

---

## Classification legend

Same as `LEGACY_BROKER_WORKFLOW_ALIGNMENT.md`.

---

## Workflow spine (legacy)

### Inertia web (`routes/web.php`)

| Area               | Controller                                                 | Key methods                                                                                                                                                       |
| ------------------ | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard          | `LeadWebController::dashboard`                             | CRM stats                                                                                                                                                         |
| Leads              | `LeadWebController`                                        | `index`, `store`, `update`, `destroy`, `storeNote`, `invite`                                                                                                      |
| Users              | `UserWebController`                                        | Internal user CRUD                                                                                                                                                |
| Roles              | `RoleWebController`                                        | `index`, `update`                                                                                                                                                 |
| Pricing calculator | `SubscriptionWebController::calculator`, `calculatorQuote` | Public-style quote UI for staff                                                                                                                                   |
| Subscription admin | `SubscriptionWebController`                                | `pricing`, `updatePricing`, `settings`, `promoCodes*`, `companies`, `companyShow`, `companyUpdate`, `companyQuote`, `companyOfflinePayment`, `companyExtendTrial` |
| Partner onboarding | `PartnerOnboardingWebController`                           | Tokenized partner signup                                                                                                                                          |

### JSON API (`routes/api.php`)

| Area                | Controller                                                                                                                                           | Key methods                                                        |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Public leads        | `PublicLeadController::store`                                                                                                                        | Website capture                                                    |
| Public onboarding   | `PublicOnboardingController`                                                                                                                         | OTP, policy, password, `complete`                                  |
| Product integration | `ProductSubscriptionController`                                                                                                                      | `catalog`, `validatePromo`, `incrementPromoUse` (HMAC per product) |
| Admin API (Sanctum) | `LeadController`, `UserController`, `SubscriptionCatalogController`, `PromoCodeController`, `SubscriptionSettingsController`, `InvitationController` | CRM + catalog PATCH                                                |

### Cross-product provisioning (tests as evidence)

Feature tests document expected behaviours:

- `tests/Feature/ControlPlane/CustomerOnboardingProvisioningTest.php`
- `tests/Feature/ControlPlane/ProvisioningErrorReconciliationTest.php`
- `tests/Feature/ControlPlane/PlansPricingRenewalsTest.php`
- `tests/Feature/Crm/LeadManagementTest.php`

Legacy admin pushes users into **separate product databases** via signed APIs; Nexus provisions **one company workspace** with module entitlements.

---

## Nexus mapping

| Legacy workflow                             | Nexus                                                                    | Classification                                                          |
| ------------------------------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------- |
| Lead CRUD + notes + invite                  | `Admin\Crm\LeadController` + Inertia `/admin/crm/leads/*`                | **Improved**                                                            |
| CRM dashboard                               | `Admin\Crm\CrmDashboardController`                                       | **Partial** — stats parity not verified                                 |
| Support tickets                             | `Admin\Crm\SupportTicketController`                                      | **Improved** (Nexus-only surface)                                       |
| Internal users / roles                      | Nexus platform admin RBAC (not legacy role names)                        | **Improved**                                                            |
| Subscription catalog edit                   | Seeded `SubscriptionCatalogueVersion`; customer Owner checkout           | **Partial** — no full legacy pricing admin UI                           |
| Per-company subscription override           | `OwnerSubscriptionController` (customer) + limited admin invoice approve | **Partial**                                                             |
| Offline payment + extend trial              | Legacy `companyOfflinePayment`, `companyExtendTrial`                     | **Missing** — PO Decision Required                                      |
| Promo codes admin                           | Legacy `PromoCodeController` API                                         | **Partial** — promo validation in Owner flow; admin CRUD not equivalent |
| Product HMAC API (`/v1/products/{product}`) | Nexus entitlement resolver internal                                      | **Intentional Difference**                                              |
| Multi-product company registry              | Single Nexus `companies` table + modules                                 | **Improved**                                                            |
| Partner onboarding web                      | Not located in Nexus `routes/web.php`                                    | **Missing** — PO Decision Required                                      |
| Public website lead API                     | Not verified in Nexus this pass                                          | **Not Verified**                                                        |
| Pricing calculator (staff)                  | Owner catalogue pages only                                               | **Partial**                                                             |

### Admin subscription invoice (Nexus-only partial)

`routes/web.php`: `admin.subscription-invoices` approve — legacy used richer company subscription console per product (`SubscriptionWebController::companyShow`).

---

## Workflow alignment summary

| Workflow                                          | Classification | Severity |
| ------------------------------------------------- | -------------- | -------- |
| Lead → invite → onboarding                        | **Partial**    | P1       |
| Subscription catalog governance                   | **Partial**    | P1       |
| Company subscription intervention (offline/trial) | **Missing**    | P1       |
| Promo lifecycle admin                             | **Partial**    | P2       |
| Partner onboarding                                | **Missing**    | P2       |
| Product-signature subscription API for silo apps  | **Deprecated** | —        |

---

## ADM-* row register (summary for matrix)

| ID     | Capability                            | Legacy evidence                          | Nexus                          | Classification         |
| ------ | ------------------------------------- | ---------------------------------------- | ------------------------------ | ---------------------- |
| ADM-01 | Lead pipeline                         | `LeadWebController::*`                   | `LeadController`               | Improved               |
| ADM-02 | Lead invite / onboarding email        | `LeadWebController::invite`              | Lead invite flows in Nexus CRM | Partial                |
| ADM-03 | CRM dashboard metrics                 | `CrmDashboardStats`                      | `CrmDashboardController`       | Not Verified           |
| ADM-04 | Internal user admin                   | `UserWebController`                      | Platform admin users           | Partial                |
| ADM-05 | Role permission edit                  | `RoleWebController::update`              | Permission master codes        | Improved               |
| ADM-06 | Catalog pricing PATCH                 | `SubscriptionCatalogController::update`  | Catalogue seed / owner-facing  | Partial                |
| ADM-07 | Subscription settings                 | `SubscriptionSettingsController`         | Config + owner                 | Partial                |
| ADM-08 | Promo CRUD                            | `PromoCodeController`                    | Limited                        | Partial                |
| ADM-09 | Per-product company subscription desk | `SubscriptionWebController::companyShow` | Owner + admin invoice approve  | Partial                |
| ADM-10 | Offline payment recording             | `companyOfflinePayment`                  | —                              | Missing                |
| ADM-11 | Trial extension                       | `companyExtendTrial`                     | —                              | Missing                |
| ADM-12 | Staff pricing calculator              | `calculator`, `calculatorQuote`          | Owner catalogue                | Partial                |
| ADM-13 | Public lead ingest                    | `PublicLeadController::store`            | —                              | Not Verified           |
| ADM-14 | Token onboarding API                  | `PublicOnboardingController`             | —                              | Partial                |
| ADM-15 | Product subscription HMAC API         | `ProductSubscriptionController`          | Entitlements in-app            | Intentional Difference |
| ADM-16 | Partner onboarding pages              | `PartnerOnboardingWebController`         | —                              | Missing                |
| ADM-17 | Control-plane provisioning jobs       | ControlPlane feature tests               | Nexus onboarding services      | Partial                |
| ADM-18 | Sanctum admin API                     | `routes/api.php` v1/admin                | Inertia-first admin            | Intentional Difference |

**ADM totals (18 rows):** Equivalent 0 · Improved 4 · Partial 10 · Missing 3 · Not Verified 1 · Intentional Difference 2 · Deprecated 0

---

## Evidence paths

- Legacy web routes: `legacy/truckbill_admin/routes/web.php`
- Legacy API routes: `legacy/truckbill_admin/routes/api.php`
- Nexus CRM: `app/Http/Controllers/Admin/Crm/*`, `resources/js` pages under `admin/crm`
- Nexus owner subscription: `app/Http/Controllers/Owner/OwnerSubscriptionController.php`

_No legacy files modified._
