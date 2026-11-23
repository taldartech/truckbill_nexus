# k6 Performance Report

**Date:** 23 November 2026  
**Profile:** `tests/performance/k6/smoke.js`  
**Target:** local `127.0.0.1:8000`

## Smoke results

| Metric      | Value                                  |
| ----------- | -------------------------------------- |
| VUs         | 1                                      |
| Iterations  | 28                                     |
| Checks      | 28/28 succeeded (`http_req_failed=0%`) |
| p50 latency | ~66 ms                                 |
| p95 latency | ~227 ms                                |
| RPS         | ~0.92                                  |

## Scope honesty

Login smoke only. Representative Owner/CRM/Master/LR/Trip/Invoice workloads and concurrency profiles were **not** executed this window. Do not claim 5,000-user readiness.
