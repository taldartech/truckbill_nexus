# Phase 5 & 6 Full MySQL Certification

**Date:** 24 November 2026  
**Engine:** disposable Docker `mysql:8.4` on host port **3307**  
**Database:** `truckbill_mysql_test`  
**Homebrew datadir:** untouched (`/opt/homebrew/var/mysql` not used)

## Local execution

```bash
docker run -d --name truckbill-mysql-ci \
  -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_DATABASE=truckbill_mysql_test \
  -e MYSQL_USER=nexus_user \
  -e MYSQL_PASSWORD=secret \
  -p 3307:3306 mysql:8.4

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 \
DB_DATABASE=truckbill_mysql_test DB_USERNAME=nexus_user DB_PASSWORD=secret \
php artisan migrate:fresh --force --no-interaction

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 \
DB_DATABASE=truckbill_mysql_test DB_USERNAME=nexus_user DB_PASSWORD=secret \
php artisan test --compact \
  tests/Feature/DatabaseDialectCompatibilityTest.php \
  tests/Feature/Phase3/TransporterTdsCalculationTest.php \
  tests/Feature/TaxMasterTest.php \
  tests/Feature/Phase4/PartyRoleAttributesTest.php \
  tests/Feature/LocalDemoWorkspaceSeederTest.php \
  tests/Feature/OperationalDocumentHttpTest.php \
  tests/Feature/CustomerInvoice/CaseInsensitiveLikeTest.php \
  tests/Feature/CustomerInvoice/UnifiedInvoiceScalabilityTest.php \
  tests/Feature/Financial/FinancialInvariantsTest.php \
  tests/Feature/Financial/InvoiceFinancialConcurrencyTest.php \
  tests/Feature/Rbac/Phase5Phase6SensitiveMutationHttpTest.php \
  tests/Feature/Broker \
  tests/Feature/Phase5
```

## Results

| Run                                                          | Result                                                                            |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------- |
| Expanded certification slice (before scalability driver fix) | **164 passed / 1 failed** — failure was a test asserting driver `pgsql` only      |
| `UnifiedInvoiceScalabilityTest` after portable driver assert | **4 passed** on MySQL                                                             |
| Net assessment                                               | Phase 5/6 MySQL slice **green** after removing the incorrect PostgreSQL-only gate |

## CI

`.github/workflows/tests.yml` MySQL job now includes a **Phase 5/6 certification slice** in addition to the focused dialect job. Remote green requires Product Owner push.
