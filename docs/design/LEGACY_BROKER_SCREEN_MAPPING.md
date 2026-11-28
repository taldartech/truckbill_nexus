# Legacy Broker → Nexus Screen Mapping

**Date:** 24 November 2026  
**PO-LGC-017:** Legacy broker is the primary UX/flow reference.  
**Legacy SHA:** `6a90e955bd7a05497f60a6d0bd72891c880e6d14`  
**Nexus pages:** `resources/js/pages/**` (Inertia)  
**Routes:** `legacy/truckbill_broker/routes/web.php` vs `routes/web.php` (`branch.broker.*`, branch LR/enquiry)  
**Verification:** **Source-verified** (sidebar + routes + page files). Browser-verified only where cited in `BATCH_2_BROKER_E2E_CERTIFICATION.md`.

---

## Legend

| Column         | Meaning                                                                             |
| -------------- | ----------------------------------------------------------------------------------- |
| **Parity**     | Equivalent / Partial / Deferred / Intentional / Nexus-only — not a percentage score |
| **Nexus page** | Primary React page component path under `resources/js/pages`                        |

---

## Operations spine

| Workflow step         | Legacy view / route                             | Nexus page(s)                                                             | Route name(s) (Nexus)                              | Parity      | Notes                                                                                                |
| --------------------- | ----------------------------------------------- | ------------------------------------------------------------------------- | -------------------------------------------------- | ----------- | ---------------------------------------------------------------------------------------------------- |
| Dashboard             | `dashboard`                                     | `operations/dashboard` (branch)                                           | `operations`                                       | Partial     | Unified ops dashboard vs legacy KPI cards                                                            |
| Enquiry               | _(not in legacy sidebar)_                       | `branch/enquiries/{index,create,show}`                                    | `branch.enquiries.*`                               | Nexus-only  | Pre-LR capture in Nexus                                                                              |
| Bilty list            | `lorry_receipts/index`                          | `branch/lorry-receipts/index`                                             | `branch.lorry-receipts.index`                      | Improved    | Register + broker context filter                                                                     |
| Bilty create          | `lorry_receipts/create`                         | `branch/lorry-receipts/create` + `lr-form.tsx`                            | `branch.lorry-receipts.create`                     | Partial     | MT weights, confirmation density; freeze rules                                                       |
| Bilty edit            | `lorry_receipts/{id}/edit`                      | `branch/lorry-receipts/edit`                                              | `branch.lorry-receipts.edit`                       | Partial     | Financial freeze vs legacy open edit                                                                 |
| Lorry Hire list       | `freight_bookings/index`                        | `broker/lorry-hires/index`                                                | `branch.broker.lorry-hires.index`                  | Equivalent  |                                                                                                      |
| Lorry Hire create     | From LR / booking store                         | `broker/lorry-hires/create`                                               | `branch.broker.lorry-hires.create-from-lr`         | Partial     | Payee chip + margin warning shipped                                                                  |
| Lorry Hire show       | `freight_bookings/show`                         | `broker/lorry-hires/show`                                                 | `branch.broker.lorry-hires.show`                   | Partial     | Record voucher CTA; settlement PDF deferred                                                          |
| Lorry Hire edit       | `freight_bookings/edit`                         | `broker/lorry-hires/edit` (shared `lorry-hire-form`)                      | `branch.broker.lorry-hires.edit` / `update`        | Aligned     | Custom Amount; remark + change review; payee via Bilty                                               |
| Voucher list          | `voucher_payments/index`                        | `broker/vouchers/index`                                                   | `branch.broker.vouchers.index`                     | Partial     | No legacy export-payout file in Nexus                                                                |
| Voucher payment       | `#voucherPaymentModal` (Good Hires / hire show) | Voucher Payment modal on `broker/lorry-hires/index` + `show`              | `branch.broker.vouchers.payment-context` / `store` | Aligned     | Single + bulk modal; Book Balance cap; no standalone page                                            |
| Voucher show          | `voucher_payments/{voucherNumber}`              | `broker/vouchers/show`                                                    | `branch.broker.vouchers.show`                      | Partial     | Show density vs legacy                                                                               |
| Daily payments        | `daily-payments`                                | `broker/daily-payments/index`                                             | `branch.broker.daily-payments.index`               | Partial     |                                                                                                      |
| Book balance          | Transporter advance/book balance UX             | `broker/transporter-balances/index` + `BookBalanceDialog`                 | `branch.broker.transporter-balances.*`             | Equivalent  | Shared modal from Party Master/Show/Balances; full statement; Accounting integration when subscribed |
| Delivery / Memo       | Memo bulk on `freight_bookings`                 | Memo / POD modal on `broker/lorry-hires/index`; `broker/deliveries/index` | `branch.broker.deliveries.*`                       | Aligned     | Bulk Memo / POD marks Delivered; deliver without memo; POD attach                                    |
| Linked bilty          | `lorry_receipts/create-linked`                  | `broker/linked-bilties/{index,create,show}`                               | `branch.broker.linked-bilties.*`                   | Intentional | Post-hire grouping, not at LR create                                                                 |
| Broker invoice list   | `invoices/index`                                | `broker/invoices/index`                                                   | `branch.broker.invoices.index`                     | Partial     |                                                                                                      |
| Broker invoice create | `invoices/create`                               | `broker/invoices/create`                                                  | `branch.broker.invoices.create`                    | Partial     | No custom invoice (**PO-LGC-001**)                                                                   |
| Broker invoice show   | `invoices/show`                                 | `broker/invoices/show` + print                                            | `branch.broker.invoices.show`                      | Partial     | Cancel/reissue vs legacy edit                                                                        |
| Customer receipt      | `invoices/{invoice}/payments`                   | Unified `payments` + broker invoice payment form                          | `branch.broker.invoices.payments.store`            | Partial     | Unified `/payments` nav entry                                                                        |
| Registers / reports   | `reports/*` (22 routes)                         | `broker/registers/index` (hub)                                            | `branch.broker.registers.index`                    | Deferred    | Phase 11 full report suite                                                                           |

---

## Masters (legacy split → Nexus unified)

| Legacy menu / route                               | Nexus equivalent                                        | Parity      | Notes                                                                                                           |
| ------------------------------------------------- | ------------------------------------------------------- | ----------- | --------------------------------------------------------------------------------------------------------------- |
| `transporters`                                    | `parties` (role: transporter)                           | Improved    | TDS preview in Nexus                                                                                            |
| `truck_owners`                                    | `parties` (owner merged into transporter policy)        | Intentional | Rules: truck owner merged                                                                                       |
| `consignees`                                      | `parties` (customer/consignee roles)                    | Improved    |                                                                                                                 |
| `trucks` + `drivers`                              | `vehicles` + `drivers` (unified `Party` role: `driver`) | Aligned     | Broker & OwnFleet Driver Master + Quick Add; bulk CSV remains **PO-LGC-005**; OwnFleet Vehicle Drivers isolated |
| `master/goods-types`, `truck-types`, `pumps`, geo | `goods-types`, `truck-types`, branch/owner catalogues   | Partial     | Scope split owner vs branch                                                                                     |
| Bank accounts (owner/transporter)                 | `bank-cash`                                             | Improved    | Tenant-safe isolation                                                                                           |

---

## Administration (out of Batch 2 UX scope)

| Legacy                         | Nexus                       | Parity           |
| ------------------------------ | --------------------------- | ---------------- |
| `users`, `branches`, `company` | Owner `/management/*`       | Improved         |
| In-app `subscription/*`        | Owner subscription surfaces | Intentional      |
| `accounting/*` (BETA)          | Not in Branch panel         | Deferred ADR-010 |

---

## Deep links and query contracts

| Operator action (legacy habit) | Nexus equivalent                                                               | Evidence                                                               |
| ------------------------------ | ------------------------------------------------------------------------------ | ---------------------------------------------------------------------- |
| Pay hire from booking screen   | Hire show → **Voucher Payment** modal (Create & Record Voucher opens `?pay=1`) | `lorry-hires/show.tsx`, `components/broker/voucher-payment-dialog.tsx` |
| Create hire from LR            | LR show/register → create hire route                                           | `branch.broker.lorry-hires.create-from-lr`                             |
| Linked LR at create time       | Create Linked Bilty group after hires exist                                    | `linked-bilties/create.tsx`                                            |

---

## Cross-references

- UX alignment principles: `LEGACY_BROKER_UX_ALIGNMENT.md`
- Happy-path sequencing: `BROKER_OPERATIONAL_FLOW_STANDARD.md`
- BRK row detail: `docs/audit/LEGACY_BROKER_FUNCTIONAL_PARITY.md`
