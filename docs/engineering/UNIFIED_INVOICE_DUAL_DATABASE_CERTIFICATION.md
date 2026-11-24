# Unified Invoice Dual-Database Certification

**Date:** 24 November 2026  
**Commit:** `c3ae9f2`

## Issues fixed

1. **`ilike`** — replaced with `CaseInsensitiveLike::whereContains` (PostgreSQL `ilike`, MySQL `LOWER(...) LIKE`).
2. **`limit(500)` memory merge** — removed. Single-source paths use Eloquent `paginate()`. Mixed sources use `unionAll` key query + hydrate page IDs.

## Tests

`tests/Feature/CustomerInvoice/` — CaseInsensitiveLike + facade pagination helpers (committed with `c3ae9f2`).

## MySQL

Focused CI MySQL job historically green for migrations; **full** MySQL Pest matrix for 600+600 invoice seed **not** re-executed locally in this pass (disposable DB only when available — Homebrew datadir untouched).
