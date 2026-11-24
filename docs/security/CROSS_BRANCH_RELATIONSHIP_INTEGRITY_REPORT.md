# Cross-Branch Relationship Integrity Report

**Date:** 24 November 2026  
**Command:** `php artisan masters:integrity-audit [--company=] [--json=]`

## Scope

Read-only detection of:

- Cross-company Vehicle → Party FKs
- Shared vehicles referencing branch-restricted Parties
- Branch-restricted vehicles referencing Parties from another branch
- Missing related Parties

## Policy

Findings are **not auto-repaired**. Historical remediations require Product Owner decision (duplicate vs share vs rewrite FK). Financial history must not be rewritten.

## Demo remediation

LocalDemoWorkspaceSeeder now aligns Shared Data masters to `branch_id = null` and updates existing transporter/vehicle fixtures on re-seed.
