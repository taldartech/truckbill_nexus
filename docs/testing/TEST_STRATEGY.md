# TruckBill Test Strategy

**Status:** Draft — Pending Review. Phase 0 artefact.  
**Date:** 2026-11-21  
**Technology direction:** Pest (PHP), Playwright (browser), k6 (load, Phase 11), Laravel feature/integration tests. Tools are specified; **not installed or changed** during Phase 0.

Phase numbering follows `Phases.md` (ADR-011).

---

## 1. Unit testing

| Field | Content |
|---|---|
| Objective | Verify pure calculations and rule engines without HTTP/DB where practical |
| Scope | Proration, discount order, Challan TOTAL, TDS snapshots, diesel amount, trip profit, weighted average cost, entitlement caps |
| Framework/tool | Pest unit tests |
| Test environment | Local / CI; no external services |
| Entry criteria | Formula documented in Rules; acceptance vectors defined |
| Exit criteria | Vectors green; edge cases (leap day, rounding, zero rates) covered |
| Relevant requirement IDs | TB-SUB-004, TB-SUB-005, TB-PTL-003, TB-OWNF-002, TB-OWNF-003, TB-BRK-002, TB-BRK-003, TB-INV-001 |

## 2. Feature testing

| Field | Content |
|---|---|
| Objective | Verify HTTP/Inertia behaviours, validation and authorization outcomes |
| Scope | Auth flows, settings policies (no self-service email/delete), CRUD for domain features per phase |
| Framework/tool | Pest feature tests + `RefreshDatabase` |
| Test environment | PostgreSQL test DB (`nexus_test`) per phpunit direction |
| Entry criteria | Route/controller or action exists; factories available |
| Exit criteria | Happy path + important failure modes asserted |
| Relevant requirement IDs | TB-AUTH-002, TB-AUTH-006, TB-AUTH-007, TB-OWN-002–005, module feature IDs |

## 3. Integration testing

| Field | Content |
|---|---|
| Objective | Verify multi-table transactions, constraints, queues and outbox |
| Scope | Mutation+audit atomicity; outbox dispatch; idempotent consumers; DB unique constraints |
| Framework/tool | Pest feature/integration; sync queue in tests unless explicitly testing async |
| Test environment | PostgreSQL; Redis optional for queue-specific cases |
| Entry criteria | Schema and primitives available (Phase 1+) |
| Exit criteria | Transaction rollback leaves no orphan audit/outbox; retries safe |
| Relevant requirement IDs | TB-AUD-001, TB-EVT-001, TB-SUB-008, TB-ACC-002 |

## 4. Tenant isolation testing

| Field | Content |
|---|---|
| Objective | Prove cross-company/branch access is denied |
| Scope | Queries, route model binding, exports, downloads, jobs, cache keys |
| Framework/tool | Pest negative tests with multi-tenant factories |
| Test environment | PostgreSQL |
| Entry criteria | Tenant context implemented (Phase 1–2) |
| Exit criteria | Spoofed IDs and cross-tenant reads/writes fail closed |
| Relevant requirement IDs | TB-SEC-001–004, TB-OWN-007 |

## 5. Authorization testing

| Field | Content |
|---|---|
| Objective | Verify role permissions, Owner Panel boundaries, Delete explicitness |
| Scope | Permission Master actions; Owner vs Admin; Internal vs Customer domains |
| Framework/tool | Pest; policy/gate assertions |
| Test environment | PostgreSQL |
| Entry criteria | RBAC schema (Phase 2) |
| Exit criteria | Matrix cases pass; permission revocation immediate |
| Relevant requirement IDs | TB-RBAC-001–003, TB-OWN-001, TB-OWN-006 |

## 6. Audit atomicity testing

| Field | Content |
|---|---|
| Objective | Ensure audit rows commit or roll back with mutations |
| Scope | Edit/delete/cancel with remarks; failed mutations leave no audit |
| Framework/tool | Pest + DB transactions |
| Test environment | PostgreSQL |
| Entry criteria | Audit writer (Phase 1) |
| Exit criteria | Atomic success and failure cases covered |
| Relevant requirement IDs | TB-AUD-001–003 |

## 7. Financial calculation testing

| Field | Content |
|---|---|
| Objective | Protect money, tax and ledger integrity |
| Scope | Invoice settlements, TDS, GST snapshots, voucher balance, reversals |
| Framework/tool | Pest unit + feature; decimal assertions |
| Test environment | PostgreSQL |
| Entry criteria | Accounting maps validated or explicitly stubbed as Pending |
| Exit criteria | Debit=credit; reversal links; closed-period rules |
| Relevant requirement IDs | TB-ACC-001–003, TB-TAX-001, TB-SUB-004–005 |

## 8. Frontend component testing

| Field | Content |
|---|---|
| Objective | Verify reusable UI components and form behaviours |
| Scope | Shared inputs, remark dialogs, status components, design tokens/Poppins usage |
| Framework/tool | Pending selection consistent with Vite/React stack (not installed in Phase 0) |
| Test environment | Node CI job |
| Entry criteria | Component catalogue exists (Design deliverables) |
| Exit criteria | Critical components covered |
| Relevant requirement IDs | TB-DES-001, TB-DES-002 |

## 9. Playwright browser testing

| Field | Content |
|---|---|
| Objective | End-to-end critical journeys |
| Scope | Login, Owner/Branch shell navigation, irreversible confirmations, billing checkout later |
| Framework/tool | Playwright (Phase 1+; not installed now) |
| Test environment | Non-production app + test DB |
| Entry criteria | UI shell available; test users seeded |
| Exit criteria | Critical paths green in CI |
| Relevant requirement IDs | TB-OWN-002–004, TB-DES-002, TB-SUB-002 |

## 10. API and webhook contract testing

| Field | Content |
|---|---|
| Objective | Verify external contracts and signatures |
| Scope | Website enquiry webhook; Razorpay webhooks; provider callbacks |
| Framework/tool | Pest HTTP tests; optional contract fixtures |
| Test environment | Signed fixture payloads |
| Entry criteria | Contract published |
| Exit criteria | Invalid signature/replay rejected; valid path idempotent |
| Relevant requirement IDs | TB-ENQ-001, TB-SUB-002, TB-SUB-008, TB-PTL-005 |

## 11. Idempotency testing

| Field | Content |
|---|---|
| Objective | Duplicate requests/webhooks/jobs do not double-apply side effects |
| Scope | Payments, posting, credit reservation, outbox consumers |
| Framework/tool | Pest |
| Test environment | PostgreSQL |
| Entry criteria | Idempotency keys/outbox (Phase 1/3) |
| Exit criteria | Second delivery is no-op or safe |
| Relevant requirement IDs | TB-EVT-001, TB-SUB-008 |

## 12. File upload and malware scan testing

| Field | Content |
|---|---|
| Objective | Enforce size/MIME and scan gate |
| Scope | Accept/reject paths; unavailable until scanned; quota behaviour |
| Framework/tool | Pest with storage fake / scanner stub |
| Test environment | Local disk fake; scanner double |
| Entry criteria | File service (Phase 1) |
| Exit criteria | Unscanned/failed scan never served |
| Relevant requirement IDs | TB-DOC-001, TB-DOC-002 |

## 13. Security testing

| Field | Content |
|---|---|
| Objective | Reduce authz, IDOR, injection and secret-exposure risk |
| Scope | Tenant isolation, CSRF on web, mass assignment, webhook replay, log masking |
| Framework/tool | Pest negatives; dependency scanning in CI later |
| Test environment | Non-production |
| Entry criteria | Feature under test exists |
| Exit criteria | No open Critical/High findings for the phase exit |
| Relevant requirement IDs | TB-SEC-001–004, TB-AUTH-006–007, TB-DEL-001 |

## 14. Performance and load testing

| Field | Content |
|---|---|
| Objective | Measure against 5,000 concurrent objective with defined personas |
| Scope | Read/write mix, queue lag, p95/p99 |
| Framework/tool | k6 (Phase 11) |
| Test environment | Sized non-production |
| Entry criteria | Workload personas/RPS defined (currently Pending) |
| Exit criteria | Documented results vs SLOs |
| Relevant requirement IDs | TB-NFR-001 |

## 15. Backup and recovery testing

| Field | Content |
|---|---|
| Objective | Prove restore meets RPO/RTO |
| Scope | DB PITR restore drill; S3 object recovery where applicable |
| Framework/tool | Runbooks + restore drills |
| Test environment | Isolated restore environment |
| Entry criteria | Numeric RPO/RTO defined (Pending) |
| Exit criteria | Successful drill evidence |
| Relevant requirement IDs | TB-NFR-002 |

---

## Cross-cutting rules
- Do not weaken tests to obtain green results.
- Report skipped tests accurately (e.g. disabled Fortify 2FA).
- Every implemented requirement needs an acceptance-test ID linked in the traceability register.
- Tenant, authorization, audit and financial tests are mandatory gates for relevant phases (Phases.md).
