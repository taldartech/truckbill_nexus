# Premium UI · TDS · Dual-DB — Implementation Checkpoint

**Date:** 23 November 2026  
**Baseline start:** `47f86a975c36c67fa6f2911cab60b62f67aa82f8`

## Delivered

- Dual-engine migration compatibility (`SoftDeleteUniqueIndex` + identifier/FK fixes)
- Transporter TDS calculation service wired to Party Master + live preview
- April 1 declaration reset (Asia/Kolkata, idempotent)
- Semantic button/info/warning tokens; Party FormSection UX
- Demo transporters + `TdsReferenceRulesSeeder` separation
- CI MySQL job (focused Pest matrix)

## Honest gaps

See certification report §R / §T.
