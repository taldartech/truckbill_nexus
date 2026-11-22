# Phase 3 Scope and Transition Summary

**Date:** 22 November 2026  
**Authority:** `docs/governing/Phases.md` (DEC-001 / ADR-011)  
**Phase 2:** Formally accepted WITH DOCUMENTED EXCEPTIONS (PO-P2-FINAL-ACCEPT)  
**Historical note:** This document was written before PO-P3-AUTH as a non-authorizing readiness summary.  
**Current status:** Phase 3 was authorized (PO-P3-AUTH), implemented (Batches A–G), and **FORMALLY ACCEPTED WITH DOCUMENTED EXCEPTIONS** (**PO-P3-FINAL-ACCEPT**, Option A, 22 November 2026) — see `docs/implementation/PHASE_3_ACCEPTANCE_RECORD.md`.  
**Phase 4:** **Not authorized** for implementation.

---

## 1. Business scope (Phases.md)

Phase 3 — Masters / documents / subscription:

| Area                    | Governing intent                                                                             |
| ----------------------- | -------------------------------------------------------------------------------------------- |
| Masters                 | Party, Vehicle, Bank, Station, other masters; GST/TDS masters                                |
| Documents               | Common document service                                                                      |
| Subscription commercial | Catalogue, contracts, entitlements, trial, capacity, quotas, credits                         |
| Payments                | Initial **manual** Razorpay renewals (no auto-renew); invoice-number approval; Owner billing |

**Exit (governing):** pricing calculation; webhook idempotency; entitlement enforcement; storage/credit reconciliation; immutable contracts.

---

## 2. Reuse Phase 2 infrastructure first

Prefer extending existing foundations before new packages or patterns:

- Tenant isolation (`CompanyAccessService`, route binding, `RejectClientTenantIdentifiers`)
- RBAC + immediate invalidation (`PermissionAuthorizer`, Permission Masters)
- Owner / Branch panels and workspace session / MRU
- Audit (`AuditedTransaction`), outbox / idempotency, private files
- Fortify web sessions (ADR-006); company deactivation / Owner billing-info surfaces

Do not re-audit Phase 2 architecture as a Phase 3 prerequisite.

---

## 3. Material blockers (only)

| Blocker                              | Status                  | Why it matters                                                                                        |
| ------------------------------------ | ----------------------- | ----------------------------------------------------------------------------------------------------- |
| **PO-P3-AUTH**                       | Missing                 | DEC-004 requires separate Phase 3 implementation authorization                                        |
| **AUD-CON-006 / TB-SUB-STATE-001**   | Pending                 | Exact trial/grace/restricted entitlements — do not invent; blocks entitlement-accurate UX/enforcement |
| **Phase 3 design gate**              | Not started             | Product UI for masters/documents/billing needs artefacts + PO approval (`Design.md` gate)             |
| **ADR-010**                          | Pending                 | Do not invent accounting posting in Phase 3                                                           |
| Razorpay non-prod + webhook approach | Not decided/provisioned | Needed before payment integration work under PO-P3-AUTH                                               |

Non-blockers for starting an authorized Phase 3 plan: PO-P1-DEPLOY remains Deferred (operational); Phase 2 exceptions above stay open without converting to Approved.

---

## 4. Explicit non-actions

- No Phase 3 implementation until **PO-P3-AUTH**
- No invented entitlements / catalogue / live Razorpay without approval
- No Broker / OwnFleet / Partload (Phases 4–7)
- No Sanctum unless ADR-006 is revised

---

## 5. Product Owner decisions before Phase 3 implementation

1. **PO-P3-AUTH** — authorize Phase 3 under an execution plan
2. **AUD-CON-006** — resolve or document deliberate exceptions for entitlements
3. **Phase 3 design formal approval** — before product UI
4. Payment/webhook non-production approach (when authorizing billing work)
5. Package approvals under **PO-P1-DEPS** if new dependencies are required
