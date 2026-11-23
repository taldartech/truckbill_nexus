# Phase 6 Handoff — Broker / Market-Hired

**Date:** 24 November 2026  
**Status:** **AUTHORIZED** — PO instruction 24 Nov 2026; Batch A+B Lorry Hire foundation implementation in progress on `main` (local).  
**Phase 5 tip (local):** see `git rev-parse HEAD` / `docs/engineering/OWNFLEET_IMPLEMENTATION_CHECKPOINT.md`

## Prerequisite from Phase 5

- Market vehicles are classified with explicit `ownership = market` and Transporter `owner_party_id`.
- Creating an LR with a Market vehicle automatically sets `workflow_context = broker`.
- Lorry Hire foundation (1 active hire per Broker LR, TDS snapshots, schema for vouchers/delivery/invoice stubs) is implemented under Batch A+B.

## Phase 6 scope (governing)

PRD §13 / Rules §11 — Market vehicle Bilty → Lorry Hire → transporter vouchers → Memo/POD delivery → post-delivery adjustments → customer invoice.

## Implemented (Batch A+B foundation)

1. Permission catalogue `customerPhase6Catalogue()` + RbacSeeder registration (no blanket grants).
2. `DocumentNumberType` cases: `lorry_hire`, `broker_voucher`, `broker_invoice`, `linked_bilty_group`.
3. Migration `create_phase6_broker_foundations.php` (Lorry Hire + related stub tables).
4. Domain services: `LorryHireService`, `LorryHireCalculationService`, `LorryHireLrDependencyGuard`.
5. Branch UI: `broker/lorry-hires` index, create-from-LR, show (Inertia).
6. Pest: `tests/Feature/Broker/LorryHireServiceTest.php`.

## Remaining (later batches)

- Transporter voucher UI / payments workflow.
- Delivery Memo/POD screens.
- Post-delivery adjustments UI.
- Broker customer invoice (distinct from OwnFleet `trip_invoices`).
- Linked bilty groups UI.

## Reuse from Phase 5

- Unified LR + vehicle ownership classification
- Party Transporter / Customer roles
- Document numbering allocator
- Audit / soft-delete / tenant patterns
- `TransporterTdsCalculationService` (server authoritative TDS)

## Explicit non-goals until authorized

- Live Razorpay / production tax invoices
- GL postings (ADR-010 Pending)
- Changing OwnFleet invoice engine semantics
- Phase 7 Partload
