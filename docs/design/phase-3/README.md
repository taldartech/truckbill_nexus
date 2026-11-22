# Phase 3 Design Package — Masters / Documents / Subscription

**Status:** **READY FOR FORMAL PRODUCT OWNER DESIGN APPROVAL** (awaiting PO sign-off; not formally approved)  
**Prepared:** 22 November 2026  
**Authorization:** Design artefacts + static prototypes authorized under PO-P3-AUTH.  
**Production UI:** **Blocked** until explicit Product Owner design approval. Do not wire these screens into application routes until approved.

## Purpose

Implementation-ready visual design for Phase 3 Branch Panel masters, document lifecycle, and Owner Panel subscription surfaces. Specs bind to Batch A/B backend contracts. Static HTML prototypes are reviewable separately from the production app.

## Artefacts

| ID  | Artefact                 | File                          | Status                           |
| --- | ------------------------ | ----------------------------- | -------------------------------- |
| A   | Screen inventory         | `SCREEN_INVENTORY.md`         | Proposed for PO review           |
| B   | IA and navigation        | `IA_AND_NAVIGATION.md`        | Proposed for PO review           |
| C   | Field and access matrix  | `FIELD_AND_ACCESS.md`         | Proposed for PO review           |
| D   | Key workflows            | `KEY_WORKFLOWS.md`            | Proposed for PO review           |
| E   | States and confirmations | `STATES_AND_CONFIRMATIONS.md` | Proposed for PO review           |
| F   | Responsive rules         | `RESPONSIVE_RULES.md`         | Proposed for PO review           |
| G   | Component reuse mapping  | `COMPONENT_MAPPING.md`        | Proposed for PO review           |
| H   | Billing future (gated)   | `BILLING_FUTURE_SCREENS.md`   | Spec only — implementation gated |
| I   | Design review checklist  | `DESIGN_REVIEW_CHECKLIST.md`  | Awaiting PO sign-off             |
| J   | Final design review      | `FINAL_DESIGN_REVIEW.md`      | READY FOR PO DESIGN REVIEW       |
| —   | Static prototypes        | `prototypes/`                 | Reviewable HTML/CSS              |

## Label legend

| Label                     | Meaning                                                               |
| ------------------------- | --------------------------------------------------------------------- |
| **Approved requirement**  | Explicitly supported by governing docs or an approved PO decision     |
| **Design interpretation** | Presentation/navigation interpretation that does not change behaviour |
| **Proposal**              | Requires Product Owner approval before implementation                 |
| **Pending**               | Unresolved requirement; no invented answer                            |
| **Out of scope**          | Explicitly excluded or deferred                                       |
| **Implementation gated**  | Requires PO-P3-PAY / G-PAY / further approval before build            |

## Brand and shell (reuse Phase 2)

- Light-first B2B SaaS; Poppins (DEC-007)
- Brand gradient `#2FB36F` → `#2D8CFF`; surfaces `#F8F9FA` / `#F5F7FA`; ink `#212529`
- Reuse `AppLayout` / sidebar / `CompanySwitcher` / breadcrumbs / empty states / UI primitives
- Do **not** redesign the application shell

## Explicit exclusions

- Invented AUD-CON-006 trial/grace/restricted/suspended entitlement UX
- Live Razorpay checkout / production payment UI (await **PO-P3-PAY** / G-PAY)
- Phase 4+ Broker / OwnFleet / Partload operational screens
- Hard delete / restore affordances
- New packages or parallel component libraries

## How to review prototypes

Open `docs/design/phase-3/prototypes/index.html` in a browser (file://). Screens are static mockups — not production routes.

## Next gate

1. Product Owner reviews this package + prototypes.
2. Record formal design approval (or approval with exceptions) on `DESIGN_REVIEW_CHECKLIST.md`.
3. Separate authorization required before production Inertia page implementation.
