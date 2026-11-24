# Phase 1–6 Gap Remediation Report

**Date:** 24 November 2026  
**Program tip:** `3aa5bfe5970721d3fff5632e6a1c62b23c456784`  
**Authority:** Product Owner complete legacy parity + Phase 1–6 certification directive

## Scope of remediation this pass

| Gap ID  | Source   | Action taken                                                                | Commit    | Tests                                |
| ------- | -------- | --------------------------------------------------------------------------- | --------- | ------------------------------------ |
| G16-A01 | OF-B15   | `TripService::assertVehicleHasNoActiveTrip` on create                       | `3aa5bfe` | `OwnFleetTripMovementCorrectionTest` |
| G16-A09 | OF-B06   | `TripMovementService::removePickup/removeDrop` + HTTP DELETE + trip show UI | `3aa5bfe` | Same + HTTP destroy                  |
| CI      | Larastan | Remove unused `DocumentAttachmentController::$authorizer`                   | `56da1b8` | Static analysis                      |
| MySQL   | Masters  | Portable `CaseInsensitiveLike::whereAnyContains` for master search          | `59bd2ec` | Feature Pest MySQL                   |
| E2E     | Docs     | OwnFleet document drawer upload Playwright                                  | `59bd2ec` | Playwright OwnFleet doc              |

## Explicit non-remediation (authorized)

| Item                          | Reason                                                     |
| ----------------------------- | ---------------------------------------------------------- |
| OF-C06 expense bulk import    | Reclassified Deferred — not Phase 5 exit criteria          |
| BRK-VP09 bank payout export   | Reclassified Deferred Phase 11 export library              |
| BRK-VP03/04 voucher edit/void | PO-LGC-003 — do not restore unsafe mutation without policy |
| BRK-IN03 invoice edit         | PO-LGC-002 — cancel+reissue preserved                      |
| Masters bulk CSV              | PO-LGC-005 — entity list required                          |
| Phase 7 Partload              | Unauthorized                                               |
| Phase 8 GL / ADR-010          | Pending                                                    |
| Phase 10 fleet assets         | Deferred                                                   |
| Live Razorpay / prod tax PDF  | Existing EX gates                                          |
| Lorry Hire Active activation  | P56-011 Deferred — no invented rule                        |

## Root-cause themes

1. **Certification labeling ahead of remote CI** — local green ≠ GitHub Actions green (`efe341d` Larastan).
2. **Parity over-classification** — report/export and import packs labeled Missing Current Scope despite Phases.md §11 / exit criteria.
3. **OwnFleet movement UX** — create-only pickups/drops blocked dispatch corrections (fixed).
4. **Vehicle concurrency** — Rules §9 one-active-trip not previously enforced at create (fixed).

## Outcome

- **Bucket A open Missing Current Scope:** **0**
- **Approved Phase 1–6 functional spine:** intact; two OwnFleet P1 operational gaps closed
- **Certification gaps remaining:** remote CI, browser RBAC matrix breadth, k6 beyond smoke, production tax/Razorpay EX

See `PHASE_1_6_FINAL_ENGINEERING_CERTIFICATION.md` for verdict.
