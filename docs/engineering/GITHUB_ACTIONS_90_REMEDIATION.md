# GitHub Actions #90 Remediation

**Date:** 24 November 2026  
**Failed run:** https://github.com/taldartech/truckbill_nexus/actions/runs/45313001807  
**Baseline tip before fix:** `bd4033e893907dade7646d3b815c3187a4541049`

## Root cause

`tests/Feature/CustomerInvoice/CaseInsensitiveLikeTest.php` failed Pint rule `single_blank_line_at_eof` (missing trailing newline).

## Fix

Local commit `ac62b4c4575d24053c4937a738822a6416f65030` restores the EOF newline. Pint was not suppressed.

## Local verification (not remote green)

Commands executed after subsequent Phase 5/6 gap-closure commits:

- `vendor/bin/pint --test` (file-level) — pass
- Focused Pest suites for GA #89 regressions and new work — pass locally
- Full PostgreSQL Pest and expanded disposable MySQL Pest — see final acceptance report

**Remote Actions green must not be claimed until the Product Owner pushes and a successful run completes.**
