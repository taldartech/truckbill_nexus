# Phase 2 Design Package — Owner / Branch Panels

**Status:** Draft — Awaiting Formal Design Package Approval  
**Prepared / revised:** 22 November 2026  
**Scope:** Design and documentation only. No product UI or backend implementation is authorized by this package.

## Purpose

This package prepares the Design Deliverables Gate artefacts required before P2-WS-8 product UI implementation (`docs/engineering/PHASE_2_DESIGN_DELIVERABLES.md`, Design.md §14, PO-P2-DESIGN).

**PO-P2-DESIGN** approved the _requirement_ to prepare and review these artefacts. Baseline workspace decisions **PO-P2-DESIGN-SWITCHER**, **LANDING**, **DEACT-VIEW**, **DASH**, **MODULES**, and **SUB-UX** are **APPROVED** and incorporated here. Formal per-artefact design approval is **still required** before product UI. Sign off via `DESIGN_REVIEW_CHECKLIST.md`.

## Artefacts

| ID  | Artefact                              | File                          |
| --- | ------------------------------------- | ----------------------------- |
| A   | Owner Panel information architecture  | `OWNER_PANEL_IA.md`           |
| B   | Branch Panel information architecture | `BRANCH_PANEL_IA.md`          |
| C   | Workspace / company selection flow    | `WORKSPACE_SELECTION_FLOW.md` |
| D   | Owner Dashboard key-screen design     | `OWNER_DASHBOARD_SPEC.md`     |
| E   | Branch Dashboard key-screen design    | `BRANCH_DASHBOARD_SPEC.md`    |
| F   | Access restriction / state matrix     | `ACCESS_STATE_MATRIX.md`      |
| G   | Formal PO design approval package     | `DESIGN_REVIEW_CHECKLIST.md`  |

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

Technical shell (layouts, tokens, logo, empty states) is foundation only — not product-screen approval.

## Security invariants (non-negotiable)

1. UI selection is never trusted tenant authorization. Server resolves company/branch from authenticated identity, Owner designation and memberships (TB-SEC-002, ADR-002).
2. Client-supplied `company_id` / `branch_id` / `owner_id` must never grant access (`RejectClientTenantIdentifiers`).
3. Hiding a navigation item is not a security control; server authorization is authoritative (Design.md §2).
4. Internal Team has no customer-uploaded file access (PO-P2-FILE-INTERNAL).
5. Multi-company file access is active-company context only (PO-P2-FILE-MULTI).

## Approved baseline decisions (concise)

| ID                      | Topic                                                                                |
| ----------------------- | ------------------------------------------------------------------------------------ |
| PO-P2-DESIGN-SWITCHER   | Unified switcher: all accessible companies; Owner vs Member; server-validated        |
| PO-P2-DESIGN-LANDING    | Default OP: one owned / MRU owned / owned selection; never member-only as OP default |
| PO-P2-DESIGN-DEACT-VIEW | Deactivated company: view identity/status + billing; no Branch/ops; no extra writes  |
| PO-P2-DESIGN-DASH       | Phase 2 dashboards: admin/context shortcuts only; no charts/KPIs                     |
| PO-P2-DESIGN-MODULES    | Hide unavailable modules; no misleading disabled items                               |
| PO-P2-DESIGN-SUB-UX     | High-level entitlement presentation; exact trial/grace/restricted still Pending      |

Full text: `docs/engineering/PHASE_2_PRODUCT_OWNER_DECISIONS.md` D11–D16.

## Implementation gate

P2-WS-8 product React pages, Inertia controllers, dashboard queries and product navigation **must not start** until the relevant artefacts are formally Approved in `DESIGN_REVIEW_CHECKLIST.md`. Baseline decision approval alone is insufficient.
