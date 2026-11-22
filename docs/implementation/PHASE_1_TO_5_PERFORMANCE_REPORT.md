# Phase 1–5 Performance Report (Stage 0)

**Date:** 23 November 2026  
**Baseline:** `06ead4bd09730741e3c406ce90e128b8aeccbae7`

---

## 1. Measured results

| Metric                       | Result                                            |
| ---------------------------- | ------------------------------------------------- |
| k6 smoke                     | **Not executed** (tooling not approved/installed) |
| k6 baseline/load/stress/soak | **Not executed**                                  |
| p50 / p95 / p99              | **N/A**                                           |
| Error rate under load        | **N/A**                                           |
| 5,000 concurrent users       | **Not claimed** — Architecture objective only     |

---

## 2. Planned profiles (after `PO-P1-DEPS-K6`)

See `tests/performance/k6/README.md`.

Workloads: auth/session, workspace navigation, masters list/search, enquiry create/list (when UI exists), webhook intake, LR numbering contention (when LR exists), OwnFleet reads (Phase 5).

**Safety:** Dedicated performance environment only. No production. No real Razorpay/SMS/WhatsApp/email.

---

## 3. Known engineering notes (not load-test claims)

- Pest suite ~445 passing locally at tip class; not a latency benchmark.
- Numbering allocator and conversion will need concurrency tests under PostgreSQL when implemented.
- N+1 / index review required per new list endpoint in Batches C–F / Phase 5.
