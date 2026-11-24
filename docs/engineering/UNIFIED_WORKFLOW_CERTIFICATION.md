# Unified Workflow Certification (Batch 1 status)

**Date:** 24 November 2026  
**Nexus baseline before Batch 1:** remote `7345c2f` · local tip had erroneous `e05d1d6` (gitignore)

## Principle

Operators use ownership-driven workflows (Own vs Market vehicle), not separate Broker/OwnFleet apps.

## Batch 1 certified corrections

| Area                          | Status                                                                 |
| ----------------------------- | ---------------------------------------------------------------------- |
| Enquiry Customer eligibility  | Corrected + tested                                                     |
| Shared Party option query     | Rolled out to LR, Hire, Invoice, Trip, Vehicle, Linked Bilty, Balances |
| Operational weights in MT     | Bilty, Hire, Trip pickup/allocation presentation + conversion          |
| Freight Per MT semantics      | Unchanged (KG canonical); regression green                             |
| Legacy `/legacy` isolation    | Restored in `.gitignore`                                               |
| Larastan `transporterOptions` | Fixed (CI #45389048507)                                                |

## Not yet certified (later batches)

- Full Playwright Journeys A–F
- Broker hire edit depth (BRK-FH02)
- OwnFleet multi-leg UX depth (OF-B02)
- Admin CRM onboarding desk parity (SHR-11)
- Visual multi-viewport screenshot pack
- PostgreSQL + disposable MySQL full suites in this session

## References

- `PARTY_SELECTOR_ELIGIBILITY_CERTIFICATION.md`
- `MT_WEIGHT_CONVERSION_CERTIFICATION.md`
- `OPERATIONAL_WEIGHT_UNIT_STANDARD.md`
- `LEGACY_TO_NEXUS_FUNCTIONAL_PARITY_MATRIX.md`
