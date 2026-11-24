# Phase 5 & 6 Performance Certification

**Date:** 24 November 2026

## k6 smoke (executed)

```bash
k6 run -e BASE_URL=http://127.0.0.1:8000 tests/performance/k6/smoke.js
```

| Metric                | Result       | Threshold   |
| --------------------- | ------------ | ----------- |
| checks_succeeded      | 100% (28/28) | —           |
| http_req_failed       | 0.00%        | `< 5%`      |
| http_req_duration p95 | 107.06 ms    | `< 2000 ms` |
| VUs                   | 1            | smoke only  |

## Scope honesty

This validates non-production login reachability thresholds only. It does **not** certify 5,000-user readiness. Write-path financial concurrency is covered by Pest row-lock suites, not by this k6 smoke.
