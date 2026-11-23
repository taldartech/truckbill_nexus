# Phase 6 Handoff — Broker / Market-Hired

**Date:** 24 November 2026  
**Status:** **FUNCTIONALLY COMPLETE (local)** — Batches A–H delivered; certification batches I–J documented; external production activations Pending.  
**Baseline:** `d114c1e` (Batch G) → `4f7509e37e3ace895f7f762db0c9d64a2fd27675` (Batch H–J, local, not pushed).

## Prerequisite from Phase 5

- Market vehicles are classified with explicit `ownership = market` and Transporter `owner_party_id`.
- Creating an LR with a Market vehicle automatically sets `workflow_context = broker`.
- Lorry Hire foundation (1 active hire per Broker LR, TDS snapshots) implemented Batch A/B.

## Implemented (A–H)

1. Permissions + portable Phase 6 schema.
2. Lorry Hire create/show/index + cancellation.
3. Transporter vouchers, daily payments, transporter book balance.
4. Delivery memo/POD + adjustments.
5. Broker customer invoices + payments.
6. Linked bilty groups + group payment.
7. Branch sidebar Broker navigation.
8. Operational registers hub `/broker/registers`.
9. `LocalDemoWorkspaceSeeder` broker fixtures (Company A) via domain services.

## Certification artefacts

- `docs/engineering/PHASE_6_BROKER_IMPLEMENTATION_REPORT.md`
- `docs/engineering/PHASE_6_TDS_AND_SETTLEMENT_CERTIFICATION.md`
- `docs/security/PHASE_6_BROKER_RBAC_MATRIX.md`
- `docs/engineering/PHASE_6_POSTGRESQL_MYSQL_TEST_REPORT.md`
- `docs/engineering/PHASE_6_PLAYWRIGHT_CERTIFICATION.md` (Pending)
- `docs/engineering/PHASE_6_K6_REPORT.md` (Pending)
- `docs/engineering/PHASE_6_BROKER_UI_REDESIGN_REPORT.md`

## Phase 7 pointer

- `docs/implementation/PHASE_7_PARTLOAD_HANDOFF.md` — documentation only; no Partload code.

## Reuse from Phase 5

- Unified LR + vehicle ownership classification
- Party Transporter / Customer roles
- Document numbering allocator
- Audit / soft-delete / tenant patterns
- `TransporterTdsCalculationService` (server authoritative TDS)

## Explicit non-goals

- Live Razorpay / production tax invoices
- GL postings (ADR-010 Pending)
- Phase 7 Partload implementation without PO-P7-AUTH
