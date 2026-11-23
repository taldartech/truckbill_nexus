# Master Visibility Compatibility Report

**Decision:** `PO-MASTER-COMPANY-VISIBILITY`  
**Date:** 23 November 2026

## Findings

Historical Party / Vehicle / Bank-Cash records may mix `branch_id = null` (shared) and branch-scoped rows regardless of current `companies.data_visibility`.

Runtime helper: `OwnerGlobalPanelService::masterVisibilityCompatibilityReport()`.

## Policy applied

| Case                                          | Handling                                                             |
| --------------------------------------------- | -------------------------------------------------------------------- |
| Existing records                              | **Preserved** — no mass rewrite, no deletion                         |
| New creates                                   | Follow current company `data_visibility` only                        |
| Company visibility change while masters exist | **Blocked** with explicit domain exception until PO migration policy |

## Remediation (future PO decision)

If remapping is required: audited, batched migration that maps shared↔branch rows with Owner confirmation, preserving audit identity.
