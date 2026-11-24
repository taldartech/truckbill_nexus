# Phase 5 & 6 Document / POD Certification

**Date:** 24 November 2026  
**Implementation tip:** `42e4caa4911fac657acc91c75d2089ead72487da`

## Gap closed (P56-007)

| Area               | Evidence                                                                                     |
| ------------------ | -------------------------------------------------------------------------------------------- |
| Route parent types | `DocumentParentTypes::routeConstraint()` includes `lorry_receipt`, `trip`, `broker_delivery` |
| Parent-type auth   | `DocumentParentAccess` — masters use PLATFORM_*; LR/Trip/Broker Delivery use their own codes |
| OwnFleet UI        | DocumentDrawer on Trip and LR show pages                                                     |
| Broker POD HTTP    | `POST branch.broker.deliveries.pod` + deliveries index upload + drawer                       |
| Status rule        | Broker Delivery mutate only when Memo Assigned or Delivered                                  |
| Pest               | `tests/Feature/OperationalDocumentHttpTest.php` — 5 passed                                   |

## Security notes

- Private storage + scan gate unchanged
- Cross-company parent IDs denied
- Viewer can list trip docs; cannot upload
- Party Master mutate does not imply Trip/POD mutate

## Playwright

Broker complete workflow uploads POD through the portal UI (`tests/e2e/broker/complete-workflow.spec.ts`). OwnFleet journey verifies the Documents tab; full upload drawer automation remains a residual polish item.
