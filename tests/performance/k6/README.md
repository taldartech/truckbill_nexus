# Grafana k6 Performance Tests (gated)

**Status:** **Blocked pending Product Owner approval** (`PO-P1-DEPS-K6`).

## Do not

- Run against production, shared customer DBs, or live Razorpay/comms.
- Claim 5,000 concurrent user support without measured evidence.
- Make heavy stress tests mandatory on every PR.

## After approval

1. Document required k6 CLI version for macOS/Linux CI agents.
2. Add scripts: `smoke.js`, `baseline.js`, `load.js` (+ stress/spike/soak for scheduled runs).
3. Use synthetic auth against a dedicated performance environment.
4. Capture RPS, p50/p95/p99, error rate; attach reports to `PHASE_1_TO_5_PERFORMANCE_REPORT.md`.

## Proposed workloads

- Auth/session
- Workspace company/branch navigation
- Masters list/search
- Enquiry create/list (when available)
- Webhook intake (synthetic)
- LR numbering contention (when available)
- Mixed concurrent operational users

## Profiles

| Profile                      | When                               |
| ---------------------------- | ---------------------------------- |
| Smoke                        | After script changes / optional CI |
| Baseline                     | Release candidate                  |
| Load / Stress / Spike / Soak | Scheduled dedicated environment    |
