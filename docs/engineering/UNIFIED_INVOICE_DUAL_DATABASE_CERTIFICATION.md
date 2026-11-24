# Unified Invoice Dual-Database Certification

**Date:** 24 November 2026  
**Facade fix:** `c3ae9f2`  
**Scalability tests:** `657db4f` (+ portable driver assert)

## Issues fixed

1. `ilike` → `CaseInsensitiveLike` (PG + MySQL portable)
2. Removed 500-row memory merge; DB-backed pagination / union

## Scalability evidence

`UnifiedInvoiceScalabilityTest` seeds 601 OwnFleet + 601 Broker invoices and paginates past the old limit.

- PostgreSQL: included in full Pest (726 passed)
- MySQL 8.4 disposable (`3307` / `truckbill_mysql_test`): 4/4 scalability tests passed after removing incorrect `pgsql`-only gate
