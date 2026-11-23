# Phase 6 Authorization Record — Broker / Market-Hired

**Record ID:** `PO-P6-AUTH`  
**Date:** 24 November 2026  
**Status:** **APPROVED**

## Authorization

The Product Owner explicitly authorized **Phase 6 — Broker / Market-Hired Operations** under the complete legacy comparison and full Broker implementation directive dated 24 November 2026.

## Scope authorized

Market vehicle Bilty → Lorry Hire → Transporter TDS snapshots → Transporter voucher payments → Book Balance → Delivery/Memo/POD → Freight adjustments → Broker customer invoice → Settlement → Linked Bilty / group payment → Broker operational views → RBAC / entitlement / demo seeder / Pest / Playwright / k6 certification artefacts.

## Explicit exclusions (unchanged)

- Phase 7 Partload coding (handoff only at Phase 6 end)
- Live Razorpay / production tax invoices
- GL postings while ADR-010 Pending
- Legacy data migration (Phase 11)
- Git push / production deploy
- Homebrew MySQL datadir mutation
- Attendance / tyres / batteries / maintenance as Phase 6 scope

## Baseline

- Nexus remote HEAD at authorization inspect: `0fed5290320a2c779fbed75991336903102c45b0`
- GA #87 remediation commit: `ae48109f9b4e9afaf45c01b7c3c445c9ee14e295`
- Legacy Broker SHA: `6a90e955bd7a05497f60a6d0bd72891c880e6d14`

## Related

- `docs/implementation/PHASE_6_HANDOFF.md` (status updated)
- `docs/engineering/LEGACY_TO_NEXUS_COMPREHENSIVE_PARITY_MATRIX.md`
- Governing: PRD §13, Rules §11, Phases.md Phase 6

Historical Phase 0–5 acceptance records are **not** rewritten by this authorization.
