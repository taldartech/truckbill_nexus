# Unified Operational Screen Mapping

**Date:** 24 November 2026  
**Companion:** `docs/engineering/UNIFIED_BATCH_2_3_SCREEN_COVERAGE.md`

Three-way mapping (Broker / OwnFleet / Nexus) for authorized Batch 2/3 screens. Classification: Common | Vehicle-dependent | Intentional | Deferred | Decision Required.

| Operation       | Broker legacy    | OwnFleet legacy | Nexus                    | Class                        |
| --------------- | ---------------- | --------------- | ------------------------ | ---------------------------- |
| Bilty create    | LR create        | GR create       | `lorry-receipts/lr-form` | Vehicle-dependent            |
| Bilty register  | LR index         | Trip/GR lists   | `lorry-receipts/index`   | Common + conditional actions |
| Lorry Hire      | Freight Booking  | —               | `lorry-hires/*`          | Market                       |
| Voucher         | Voucher payments | —               | `vouchers/*`             | Market                       |
| Linked Bilty    | Linked groups    | —               | `linked-bilties/*`       | Market                       |
| Trip / Expenses | —                | Trip detail     | `trips/show`             | Own                          |
| Invoice         | Customer invoice | Trip invoice    | Unified invoice          | Common                       |
| Receipt         | Receipt          | Receipt         | Unified receipt          | Common                       |

Deferred OwnFleet fleet modules (tyres, payroll, FASTag, workshop) map to later phases — not Batch 3.
