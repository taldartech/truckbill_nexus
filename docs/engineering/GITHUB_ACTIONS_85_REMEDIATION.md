# GitHub Actions #85 Remediation

**Run:** https://github.com/taldartech/truckbill_nexus/actions/runs/45205924568  
**Baseline HEAD:** `3e3b9bb731273e100030e4acf33b118d317b690c`  
**Fix commit:** `03164f28c4bf30af49ce1485440b3d31f2c12273`

## Jobs

| Job             | Result                      |
| --------------- | --------------------------- |
| PostgreSQL `ci` | SUCCESS (preserve)          |
| MySQL `mysql`   | FAILED — Pest after migrate |

## Root cause

`LocalDemoWorkspaceSeeder::ensureEnquiryCdfDemoFixtures()` selected quotation/conversion candidates with `orderBy('created_at')->first()` / `orderByDesc('created_at')`.

On MySQL 8.4, identical or truncated `created_at` values make that order nondeterministic. Quotation preference could land on an enquiry without `customer_party_id`, while conversion search required `status=selected` + customer linkage — so conversion was silently skipped. PostgreSQL often ordered differently, masking the defect.

## Fix

1. Create a **deterministic** Company A enquiry keyed by `demo-cdf-convert:{companyId}` in instructions.
2. Quote → prefer → convert **that** enquiry via domain services.
3. Throw `RuntimeException` if ineligible or LR links missing (no silent skip).
4. Strengthen Pest: assert converted status, fixture key, conversion + LR links.

## Local evidence

- `tests/Feature/LocalDemoWorkspaceSeederTest.php` — **4 passed**
- Full PostgreSQL Pest after fix — **609 passed**, 2 skipped
- Disposable MySQL full re-run: **not executed** this window (Docker daemon unavailable); remote confirmation requires push
