# Unified Batch 2 & 3 — Security Certification

**Date:** 24 November 2026  
**Status:** Prior remediations preserved; full re-matrix incomplete

## Preserved controls

- Company / Branch tenant isolation on masters and operational records
- Module entitlements gate Trip vs Lorry Hire actions
- Server-side authorization for `redirect_to_hire` / `redirect_to_trip`
- No client-trusted ownership classification for security decisions
- No-Freight View redaction remains backend-enforced on prior paths

## This pass

UI changes are presentation + searchable selectors + ownership-conditional fields. No intentional weakening of authz. Full persona/entitlement browser matrix (Journey H) not re-run — certification remains Partial.
