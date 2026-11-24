# Phase 6 Broker — Final Workflow Certification

**Date:** 24 November 2026

## Workflow path

Market Vehicle → Broker LR → Lorry Hire → TDS → Vouchers → Book Balance → Memo/POD → Delivery → Adjustments → Customer Invoice → Payment → Linked Bilty

## Certified in this pass

| Gate                                         | Status   | Evidence                               |
| -------------------------------------------- | -------- | -------------------------------------- |
| Bulk voucher status all-or-nothing           | Fixed    | `e0ff99a` wraps `bulkTransitionStatus` |
| Broker invoice SAMPLE print/preview          | Fixed    | `branch.broker.invoices.print`         |
| LR financial freeze after Hire/Invoice       | Fixed    | `e0ff99a`                              |
| Unified invoice list includes Broker         | Fixed    | `c3ae9f2` + prior facade               |
| Prior Hire/TDS/Voucher/Delivery/Linked Bilty | Retained | `tests/Feature/Broker`                 |

## Open / deferred

| Gate                                            | Status                                            |
| ----------------------------------------------- | ------------------------------------------------- |
| `LorryHireStatus::Active` meaningful transition | Deferred — no invented activation without PO rule |
| Full Playwright Broker + Linked Bilty journeys  | Open                                              |
| POD attach HTTP wiring browser proof            | Open                                              |
| MySQL full Broker suite                         | Open                                              |
