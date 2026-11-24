# Master Data Integrity Audit Report

**Tool:** `App\Console\Commands\MasterDataIntegrityAuditCommand`  
**Invocation:** `php artisan masters:integrity-audit --json=storage/logs/master-integrity-audit.json`

Does not mutate data. Output enumerates relationship inconsistencies for PO remediations.
