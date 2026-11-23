# Phase 6 Handoff — Broker / Market-Hired

**Date:** 23 November 2026  
**Status:** Prepared after Phase 5 implementation; **Phase 6 not authorized for coding until PO phase decision.**  
**Phase 5 tip (local):** see `git rev-parse HEAD` / `docs/engineering/OWNFLEET_IMPLEMENTATION_CHECKPOINT.md`

## Prerequisite from Phase 5

- Market vehicles are classified with explicit `ownership = market` and Transporter `owner_party_id`.
- Creating an LR with a Market vehicle automatically sets `workflow_context = broker`.
- **Do not** implement Lorry Hire, transporter vouchers, Broker customer invoices, or voucher payments until Phase 6 is authorized.

## Phase 6 scope (governing)

PRD §13 / Rules §11 — Market vehicle Bilty → Lorry Hire → transporter vouchers → Memo/POD delivery → post-delivery adjustments → customer invoice.

## Suggested entry points

1. Lorry Hire domain linked 1:1 (or approved cardinality) to Broker LR + Market Vehicle + Transporter.
2. Transporter freight rows mapped to LR goods; TDS snapshots via existing `TransporterTdsCalculationService`.
3. Advance/final vouchers and Transporter Book Balance.
4. Delivery Memo/POD sharing with Broker Bilties.
5. Post-delivery adjustments (detention, shortage/damage, unloading weight).
6. Broker customer invoice (distinct from OwnFleet `trip_invoices`).

## Reuse from Phase 5

- Unified LR + vehicle ownership classification
- Party Transporter / Customer roles
- Document numbering allocator
- Audit / soft-delete / tenant patterns
- GST rate snapshots (customer invoice side)

## Explicit non-goals until authorized

- Live Razorpay / production tax invoices
- GL postings (ADR-010 Pending)
- Changing OwnFleet invoice engine semantics
