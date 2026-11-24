# Branch Master Isolation Security Audit

**Date:** 24 November 2026  
**Baseline remote tip at start:** `859df200e9c0b0f44db049d11dd96c18d225eadf`  
**Status:** Confirmed defects remediated for Party / Vehicle / Bank-Cash direct access and Vehicle↔Party relationship validation

## Reported reproduction (Demo Logistics Alpha)

| Fact               | Value                                                                              |
| ------------------ | ---------------------------------------------------------------------------------- |
| Company visibility | `shared_data`                                                                      |
| Vehicle            | `MH14DEMO1141` (Pune `branch_id`)                                                  |
| Owner Party        | Konkan Transporters (Mumbai HO `branch_id`)                                        |
| Symptom            | Pune Branch Panel used Mumbai-scoped owner as Lorry Hire payee via silent fallback |

## Root causes

1. **Demo seeder inconsistency** — Shared Alpha still created Konkan with Mumbai `branch_id` and market truck with Pune `branch_id` (bypassed `scopeBranchForCreate`).
2. **Silent payee** — `LorryHireService::resolveTransporter` used `$transporterPartyId ?? $vehicle->owner_party_id`.
3. **IDOR on master show/edit** — `assertParty` / `assertVehicle` / `assertAccount` checked `company_id` only.
4. **Related-party validation gap** — `assertTransporterParty` / `assertDriverParty` skipped branch visibility when `$branch === null`.

## Corrections

- `MasterAccess::assertRecordVisibleInBranch` + controller asserts with active Branch.
- VehicleService panel-branch visibility for owner/driver FKs.
- Explicit required `transporter_party_id` on Lorry Hire create; options filtered with `visibleInBranch`.
- LocalDemoWorkspaceSeeder Shared masters use `branch_id = null` and realign existing fixtures.
- Read-only `php artisan masters:integrity-audit`.

## Regression

`tests/Feature/BranchMasterIsolationTest.php`
