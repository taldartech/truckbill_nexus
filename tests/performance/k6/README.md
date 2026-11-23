# Grafana k6 Performance Tests

**Status:** **Authorized** (`PO-P1-DEPS-K6` via `PO-P1-P5-FULLSTACK-AUTH`).  
**Tooling:** Grafana k6 CLI (install separately; pin agent/CI version in reports).

## Profiles

| Script       | Purpose                          |
| ------------ | -------------------------------- |
| `smoke.js`   | Quick health after script changes |
| `baseline.js`| Release-candidate baseline       |
| `load.js`    | Sustained load                   |
| `stress.js`  | Elevated load                    |
| `spike.js`   | Sudden traffic spike             |
| `soak.js`    | Long-running endurance           |

```bash
# Example (non-production only):
k6 run -e BASE_URL=http://127.0.0.1:8000 tests/performance/k6/smoke.js
```

## Rules

- Never run against production, shared customer DBs, or live Razorpay/comms.
- Do not claim 5,000 concurrent users without measured evidence and hardware notes.
- Record RPS, p50/p95/p99, errors, and environment details in `PHASE_1_TO_5_PERFORMANCE_REPORT.md`.
