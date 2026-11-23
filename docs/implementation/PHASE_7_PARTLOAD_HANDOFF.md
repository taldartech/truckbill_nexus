# Phase 7 Partload — Handoff (documentation only)

**Date:** 24 November 2026  
**Status:** Phase 7 **not authorized** for implementation. No Phase 7 application code in this batch.

## Shared Nexus foundations to reuse

- Company / branch tenancy, RBAC, subscription entitlements (`SubscriptionModule::Partload` stub in catalogue).
- Unified LR with `workflow_context = partload` (sidebar placeholder already links create URL).
- Party, Vehicle, Station, Bank/Cash masters; document numbering; audit / soft-delete patterns.
- Enquiry → conversion patterns from Phase 4 (distinct from Partload inward/challan flows).
- Demo seeder policy: extend `LocalDemoWorkspaceSeeder` only — no module-specific seeders.

## Legacy reference location

Read-only comparison: `/Users/ttc/Sites/truckbill/_legacy_refs/truckbill_partload/`

### Legacy route surface (indicative)

From `routes/web.php`:

- `bilties` — Partload bilty CRUD + print
- `challans`, `main-challans` — line-haul / main challan workflows + print
- `inward-registers` — inward register
- `delivery-receipts` (resource elsewhere in file)
- Reports: `reports/bilty-register`, `reports/challan-register`
- Accounting module tests under `tests/Feature/Accounting/*` (operational bridge — ADR-010 Pending in Nexus)

### Legacy functional themes (for parity planning)

- Bilty advance payment modes, charge minimums (`BiltyAdvancePaymentModeTest`, `BiltyChargeMinimumTest`)
- Main challan accounting bridge (`MainChallanAccountingTest`)
- GST exemption limits (`GstExemptionLimitTest`)
- Agent / station agent patterns (`CompanyStationAgentTest`)

## Nexus gaps before Phase 7 coding

1. PO-P7-AUTH (not issued).
2. Partload cardinality and LR linkage decisions (do not assume Broker rules).
3. PO-P4-DESIGN-FORMAL still Pending — Partload UI needs explicit design approval.
4. Accounting posting architecture (ADR-010 Pending).

## Suggested Phase 7 batch sketch (non-binding)

- Batch A: Partload LR/bilty domain + permissions
- Batch B: Challan / main challan
- Batch C: Inward register + delivery receipt
- Batch D: Partload invoices / settlements (per governing Rules)
- Batch E: Registers + demo fixtures + certification

**Handoff path:** Complete Phase 6 PO acceptance → authorize Phase 7 → refresh parity matrix against legacy SHAs under `_legacy_refs/truckbill_partload`.
