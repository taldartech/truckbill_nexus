# TruckBill — Phase 1 Authorization Record

**Date:** 21 November 2026  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Decision authority:** Product Owner  
**Governing plan:** `docs/implementation/PHASE_1_EXECUTION_PLAN.md`

> **FORMAL PHASE 1 AUTHORIZATION RECORDED (21 November 2026)**
>
> - **PO-P1-AUTH:** APPROVED — Phase 1 Engineering Foundation implementation authorized under the current Phase 1 Execution Plan. This satisfies the separate implementation authorization requirement under DEC-004.
> - **PO-P1-ADR-001 … 003, 007 … 009:** ACCEPTED — see table below.
> - **ADR-006:** later **Accepted** for Phase 2 web-only (PO-P2-ADR-006, 22 November 2026) — Sanctum still not installed; see ADR-006.
> - **ADR-010:** remains **Pending** — do not finalize accounting posting architecture.
> - **PO-P1-DEPS:** CASE-BY-CASE — no blanket dependency approval.
> - **PO-P1-DEPLOY:** **DEFERRED** (22 November 2026) — not waived; Phase 1 formal closure still pending; see Phase 2 authorization record.

---

## 1. Phase 1 authorization

| Decision ID    | Decision                                                                                                | Status       | Date             | Authority     |
| -------------- | ------------------------------------------------------------------------------------------------------- | ------------ | ---------------- | ------------- |
| **PO-P1-AUTH** | Authorize implementation of Phase 1 — Engineering Foundation — under the current Phase 1 Execution Plan | **APPROVED** | 21 November 2026 | Product Owner |

**Implementation consequences:** Agents may implement WS-1 through WS-9 within the approved plan scope, sequencing, acceptance criteria and exclusions. Phase 2+ identity, membership, RBAC, Owner/Branch product panels, Sanctum, Razorpay, business modules and accounting posting architecture remain out of scope.

**Remaining restrictions:** Preserve Pending/Deferred/Excluded/Proposed product-requirement statuses (PO-EXCEPT-001). Absolute no-push / no remote publishing remains in force.

---

## 2. Accepted ADRs

| Decision ID       | ADR     | Decision                                                  | Status       | Date             | Authority     |
| ----------------- | ------- | --------------------------------------------------------- | ------------ | ---------------- | ------------- |
| **PO-P1-ADR-001** | ADR-001 | Accept modular monolith structure                         | **ACCEPTED** | 21 November 2026 | Product Owner |
| **PO-P1-ADR-002** | ADR-002 | Accept tenant context propagation                         | **ACCEPTED** | 21 November 2026 | Product Owner |
| **PO-P1-ADR-003** | ADR-003 | Accept audit log and atomic mutation                      | **ACCEPTED** | 21 November 2026 | Product Owner |
| **PO-P1-ADR-007** | ADR-007 | Accept PostgreSQL and Redis local/CI baseline             | **ACCEPTED** | 21 November 2026 | Product Owner |
| **PO-P1-ADR-008** | ADR-008 | Accept private file storage and malware-scanning strategy | **ACCEPTED** | 21 November 2026 | Product Owner |
| **PO-P1-ADR-009** | ADR-009 | Accept transactional outbox and idempotency               | **ACCEPTED** | 21 November 2026 | Product Owner |

Each ADR document carries its own Acceptance record. Rationale, scope, consequences and history already present in those ADRs are preserved.

---

## 3. ADRs that remain Pending

| ADR                                         | Status                                            | Restriction                                          |
| ------------------------------------------- | ------------------------------------------------- | ---------------------------------------------------- |
| **ADR-006** Web/mobile authentication       | **Accepted (Phase 2 web-only)** as of 22 Nov 2026 | See ADR-006 / PO-P2-ADR-006; Sanctum not for Phase 2 |
| **ADR-010** Accounting posting architecture | **Pending**                                       | Do not finalize an accounting posting design         |

No other ADR statuses were changed by this authorization.

---

## 4. Dependency approval

| Decision ID    | Decision                                       | Status           | Date             | Authority     |
| -------------- | ---------------------------------------------- | ---------------- | ---------------- | ------------- |
| **PO-P1-DEPS** | New dependencies require case-by-case approval | **CASE-BY-CASE** | 21 November 2026 | Product Owner |

Before adding any package, library, external service client or infrastructure dependency:

1. Check whether existing dependencies already provide the required capability.
2. Explain why the new dependency is necessary.
3. State alternatives, maintenance/security considerations and licensing implications.
4. Request Product Owner approval.
5. Do not install it before approval.

---

## 5. Deployment target

| Decision ID      | Decision                                                | Status                         | Date             | Authority     |
| ---------------- | ------------------------------------------------------- | ------------------------------ | ---------------- | ------------- |
| **PO-P1-DEPLOY** | Non-production deployment target for Phase 1 exit smoke | **DEFERRED** (was NOT DECIDED) | 22 November 2026 | Product Owner |

Phase 1 deployment smoke remains **outstanding**. Deferral is **not** a waiver: do not provision staging, do not claim smoke passed, and do not mark Phase 1 formally closed. Authorized Phase 2 development may proceed in parallel (`PO-P2-AUTH`).

---

## 6. Related records

- ADR files: `docs/architecture-decisions/ADR-001`, `002`, `003`, `007`, `008`, `009`
- Plan: `docs/implementation/PHASE_1_EXECUTION_PLAN.md`
- Memory: `docs/governing/Memory.md`
- Phase 0 approval (prior): `docs/engineering/PHASE_0_APPROVAL_READINESS_REPORT.md`
