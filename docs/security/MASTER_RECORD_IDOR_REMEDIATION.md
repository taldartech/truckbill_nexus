# Master Record IDOR Remediation

**Date:** 24 November 2026  
**Severity:** High (same-company cross-branch master disclosure / mutation entry)

## Vulnerability

| ID                | Endpoint class                                 | Defect              |
| ----------------- | ---------------------------------------------- | ------------------- |
| TB-SEC-MASTER-001 | Party show/edit/update/destroy/roles/documents | Company-only assert |
| TB-SEC-MASTER-002 | Vehicle show/edit/update/destroy/documents     | Company-only assert |
| TB-SEC-MASTER-003 | Bank/Cash show/edit/update/destroy             | Company-only assert |

## Fix

`MasterAccess::assertRecordVisibleInBranch` using existing `visibleInBranch` scopes. Controllers abort **404** (no disclosure) when the record is outside active Branch visibility.

## Tests

`BranchMasterIsolationTest` — BranchWiseRestricted show/edit denied from other branch.
