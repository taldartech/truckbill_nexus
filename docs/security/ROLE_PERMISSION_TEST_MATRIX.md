# Role / Permission Test Matrix

**Generated:** 23 November 2026  
**Source of truth:** `PermissionCodes`, `RbacSeeder`, Pest under `tests/Feature/Rbac/` + legacy `PermissionMasterTest`, `OwnFleetNoFreightViewTest`, Phase feature suites.

## Summary (verified this batch)

| Metric                                  | Value                                     | Evidence                                  |
| --------------------------------------- | ----------------------------------------- | ----------------------------------------- |
| Default Customer roles                  | 5                                         | `RbacSeeder` + catalogue Pest             |
| Default Internal roles                  | 3 (+ Super Admin flag, not a PM role)     | `RbacSeeder`                              |
| Registered Customer permission codes    | 41                                        | `CustomerPermissionCatalogueCoverageTest` |
| Codes with grant→revoke authorizer loop | 41                                        | same                                      |
| Default matrix assertion                | Pass                                      | same                                      |
| Owner without branch role / Trip HTTP   | Denied (403/302)                          | `OwnerOperationalPermissionHttpTest`      |
| Owner with admin role / Trip HTTP       | Allowed                                   | same                                      |
| Cross-company Owner trip access         | Denied                                    | same                                      |
| Playwright persona coverage             | Incomplete (smoke + limited owner/branch) | `tests/e2e`                               |

## Customer default roles × platform codes

| Code                                | Admin | Operator | Viewer | Op NF | Viewer NF | Authorizer grant/deny loop | HTTP ops test            |
| ----------------------------------- | ----- | -------- | ------ | ----- | --------- | -------------------------- | ------------------------ |
| platform.operations.view            | Y     | Y        | Y      | Y     | Y         | Y                          | Partial (masters/panels) |
| platform.operations.create          | Y     | Y        | N      | Y     | N         | Y                          | Partial                  |
| platform.operations.edit            | Y     | N        | N      | N     | N         | Y                          | Partial                  |
| platform.operations.delete          | N     | N        | N      | N     | N         | Y                          | Partial                  |
| platform.operations.cancel          | Y     | N        | N      | N     | N         | Y                          | Partial                  |
| platform.operations.print           | Y     | Y        | Y      | Y     | Y         | Y                          | Partial                  |
| platform.operations.export          | Y     | N        | N      | N     | N         | Y                          | Deactivate case          |
| platform.operations.manage_settings | Y     | N        | N      | N     | N         | Y                          | Partial                  |
| platform.operations.view_freight    | Y     | Y        | Y      | N     | N         | Y                          | OwnFleet No-Freight Pest |

## Module codes (no default grants)

All enquiry/quotation/lr/trip/invoice/payment codes: **registered**, authorizer grant/deny loop **Y**, dedicated HTTP positive+negative per code **Partial / expanding**.

## Internal roles

| Role              | Default PM grants           | Configurable | Tests                             |
| ----------------- | --------------------------- | ------------ | --------------------------------- |
| Super Admin       | Bypass via `isSuperAdmin()` | N/A          | PermissionMasterTest              |
| Internal Admin    | None seeded                 | Y            | create user / no SA escalation    |
| Sales Executive   | None seeded                 | Y            | revoke/reassign lead view example |
| Support Executive | None seeded                 | Y            | role switch clears prior grants   |

## Owner designation

| Scenario                              | Expected             | Tested                                     |
| ------------------------------------- | -------------------- | ------------------------------------------ |
| Owner, no branch role, Owner Panel    | Allowed              | PermissionMasterTest                       |
| Owner, no branch role, operational PM | Denied               | PermissionMasterTest + Trip HTTP           |
| Owner + Admin branch role, Trip view  | Allowed              | OwnerOperationalPermissionHttpTest         |
| Owner A → Company B trip              | Denied               | OwnerOperationalPermissionHttpTest         |
| Owner masters OR-bypass               | Allowed without role | Documented AUD-003; HTTP expansion pending |

## Gaps (honest)

1. Not every one of the 24 authorization case types is applied to every code (inapplicable cases documented in audit).
2. Playwright role personas incomplete.
3. Subscription×module×role combinatorial suite incomplete.
4. Internal CRM permission codes are created ad hoc in tests; production Internal PM catalogue remains configurable, not a fixed 41-style Customer catalogue.
