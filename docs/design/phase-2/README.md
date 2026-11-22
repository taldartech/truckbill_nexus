# Phase 2 Design Package — Owner / Branch Panels

**Status:** **APPROVED WITH DOCUMENTED EXCEPTIONS** — 22 November 2026 (PO-P2-DESIGN-FORMAL)  
**Prepared / revised:** 22 November 2026  
**Final review:** `FINAL_DESIGN_REVIEW.md`  
**Formal sign-off:** `DESIGN_REVIEW_CHECKLIST.md`  
**Scope:** Design package approval only. Product UI implementation requires a **separate** Product Owner authorization. This package does **not** start P2-WS-8 or P2-WS-9.

## Purpose

This package is the Design Deliverables Gate artefacts for Phase 2 Customer Owner/Branch panels (`docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md`, Design.md §14, PO-P2-DESIGN).

Baseline workspace decisions **PO-P2-DESIGN-SWITCHER**, **LANDING**, **DEACT-VIEW**, **DASH**, **MODULES**, and **SUB-UX** are **APPROVED**. Formal artefact approval is **APPROVED WITH DOCUMENTED EXCEPTIONS** (PO-P2-DESIGN-FORMAL). Sign-off record: `DESIGN_REVIEW_CHECKLIST.md`.

## Artefacts

| ID  | Artefact                              | File                          | Formal status                       |
| --- | ------------------------------------- | ----------------------------- | ----------------------------------- |
| A   | Owner Panel information architecture  | `OWNER_PANEL_IA.md`           | Approved with documented exceptions |
| B   | Branch Panel information architecture | `BRANCH_PANEL_IA.md`          | Approved                            |
| C   | Workspace / company selection flow    | `WORKSPACE_SELECTION_FLOW.md` | Approved                            |
| D   | Owner Dashboard key-screen design     | `OWNER_DASHBOARD_SPEC.md`     | Approved with documented exceptions |
| E   | Branch Dashboard key-screen design    | `BRANCH_DASHBOARD_SPEC.md`    | Approved                            |
| F   | Access restriction / state matrix     | `ACCESS_STATE_MATRIX.md`      | Approved with documented exceptions |
| G   | Formal PO design approval package     | `DESIGN_REVIEW_CHECKLIST.md`  | Approved (gate record)              |
| —   | Final design review (readiness)       | `FINAL_DESIGN_REVIEW.md`      | Complete (engineering)              |

## Label legend

Used throughout this package:

| Label                     | Meaning                                                               |
| ------------------------- | --------------------------------------------------------------------- |
| **Approved requirement**  | Explicitly supported by governing docs or an approved PO decision     |
| **Design interpretation** | Presentation/navigation interpretation that does not change behaviour |
| **Proposal**              | Requires Product Owner approval before implementation                 |
| **Pending**               | Unresolved requirement; no invented answer                            |
| **Out of scope**          | Explicitly excluded or deferred for this phase                        |

## Brand foundation (already decided)

- Light-first B2B logistics SaaS; avoid dark full-page backgrounds — Design.md §1
- Poppins typography — DEC-007 / TB-DES-001
- Gradient `#2FB36F` → `#2D8CFF`; supporting palette in Design.md §1
- Official TruckBill logo; tokens already present in technical shell (`resources/css/app.css`)
- Status never by colour alone; visible keyboard focus; accessible contrast — Design.md §13

Technical shell (layouts, tokens, logo, empty states) is foundation only — not product-screen approval by itself.

## Security invariants (non-negotiable)

1. UI selection is never trusted tenant authorization. Server resolves company/branch from authenticated identity, Owner designation and memberships (TB-SEC-002, ADR-002).
2. Client-supplied `company_id` / `branch_id` / `owner_id` must never grant access (`RejectClientTenantIdentifiers`).
3. Hiding a navigation item is not a security control; server authorization is authoritative (Design.md §2).
4. Internal Team has no customer-uploaded file access (PO-P2-FILE-INTERNAL).
5. Multi-company file access is active-company context only (PO-P2-FILE-MULTI).

## Approved decisions (concise)

| ID                      | Topic                                                                                |
| ----------------------- | ------------------------------------------------------------------------------------ |
| PO-P2-DESIGN-SWITCHER   | Unified switcher: all accessible companies; Owner vs Member; server-validated        |
| PO-P2-DESIGN-LANDING    | Default OP: one owned / MRU owned / owned selection; never member-only as OP default |
| PO-P2-DESIGN-DEACT-VIEW | Deactivated company: view identity/status + billing; no Branch/ops; no extra writes  |
| PO-P2-DESIGN-DASH       | Phase 2 dashboards: admin/context shortcuts only; no charts/KPIs                     |
| PO-P2-DESIGN-MODULES    | Hide unavailable modules; no misleading disabled items                               |
| PO-P2-DESIGN-SUB-UX     | High-level entitlement presentation; exact trial/grace/restricted still Pending      |
| PO-P2-DESIGN-FORMAL     | Formal A–G approval with documented exceptions                                       |
| PO-DEC-P2-REVIEW-001    | Defer detailed entitlement matrix; no inferred permissions                           |
| PO-DEC-P2-REVIEW-002    | Dedicated zero-access screen                                                         |
| PO-DEC-P2-REVIEW-003    | Sequential Company → Branch selection                                                |
| PO-DEC-P2-REVIEW-004    | Branch Settings = branch prefs only                                                  |
| PO-DEC-P2-REVIEW-005    | Mobile Owner control must remain discoverable                                        |
| PO-DEC-P2-REVIEW-006    | Server-side MRU; revalidate; never client-authorize                                  |

Full text: `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md` D11–D23.

## Explicit out of scope / exceptions

- Exact trial / grace / restricted / expired entitlement matrices (AUD-CON-006 / TB-SUB-STATE-001 **Pending**)
- Undecided subscription-dependent actions
- Internal Team product IA / landing
- Broker / OwnFleet / Partload operational screens (later phases)
- Subscription checkout, proration UI, and Phase 3 billing delivery

## Implementation gate

P2-WS-8 product React pages, Inertia controllers, dashboard queries and product navigation:

1. Require a **separate** Product Owner implementation authorization after this formal design approval.
2. Must **not** implement entitlement-dependent interactions until AUD-CON-006 is resolved.
3. Must **not** start P2-WS-9.
4. Must not weaken existing authorization enforcement.

Formal design approval alone does **not** authorize implementation start.
