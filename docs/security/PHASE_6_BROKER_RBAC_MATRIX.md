# Phase 6 Broker RBAC Matrix

**Date:** 24 November 2026  
**Source:** `PermissionCodes` broker.* catalogue + Branch controllers + Pest authorization tests.

## Permission catalogue (customer domain)

| Code                                                     | Purpose                        |
| -------------------------------------------------------- | ------------------------------ |
| `broker.lorry_hire.view`                                 | Lorry hire register / show     |
| `broker.lorry_hire.create`                               | Create from Broker LR          |
| `broker.lorry_hire.edit`                                 | TDS override, edits            |
| `broker.lorry_hire.cancel`                               | Cancellation                   |
| `broker.voucher.view`                                    | Voucher register               |
| `broker.voucher.create`                                  | Single voucher                 |
| `broker.voucher.edit`                                    | Voucher edits                  |
| `broker.voucher.status`                                  | Status transitions             |
| `broker.voucher.bulk`                                    | Bulk voucher                   |
| `broker.book_balance.view`                               | Transporter balances           |
| `broker.delivery.view`                                   | Memo/POD register              |
| `broker.delivery.manage`                                 | Assign memo, complete delivery |
| `broker.adjustment.view` / `.create`                     | Post-hire adjustments          |
| `broker.invoice.view` / `.create` / `.cancel` / `.print` | Customer invoices              |
| `broker.linked_bilty.view` / `.create` / `.pay`          | Linked bilty groups            |

## Enforcement points

- All Branch Broker controllers call `AuthorizesBranchPanel` + `PermissionAuthorizer::allowsCustomer`.
- `SubscriptionModule::Broker` entitlement required (`CoreModuleEntitlementResolver`).
- Default `customer.admin` role has **no** blanket broker grants (Pest `LorryHireServiceTest`).

## Registers hub

- `BrokerRegistersController`: any one of view permissions above; counts hidden per permission.

## Certification

- Pest covers missing entitlement, viewer deny create, voucher/invoice/linked bilty authorization scenarios.
- Full cross-role browser matrix — **Pending** (no Playwright run this batch).
