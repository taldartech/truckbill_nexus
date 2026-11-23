# Phase 6 — TDS and Settlement Certification

**Date:** 24 November 2026

## TDS (transporter / Lorry Hire)

| Area                                            | Evidence                                                                             | Status               |
| ----------------------------------------------- | ------------------------------------------------------------------------------------ | -------------------- |
| Owner vs agency declaration on transporter role | `LorryHireService` + `TransporterTdsCalculationService`; Pest `LorryHireServiceTest` | **Certified (Pest)** |
| TDS snapshot immutability on hire               | `lorry_hire_tds_snapshots`; feature tests                                            | **Certified (Pest)** |
| Customer invoice TDS separate from hire TDS     | `BrokerInvoiceServiceTest`                                                           | **Certified (Pest)** |

## Settlement (vouchers, book balance, linked pay)

| Area                                  | Evidence                                                    | Status                           |
| ------------------------------------- | ----------------------------------------------------------- | -------------------------------- |
| Cash / bank / adjust-in-advance modes | `VoucherServiceTest`                                        | **Certified (Pest)**             |
| Book balance on adjust-in-advance     | `VoucherServiceTest`                                        | **Certified (Pest)**             |
| Bulk voucher constraints              | `VoucherServiceTest`                                        | **Certified (Pest)**             |
| Linked bilty proportional vouchers    | `LinkedBiltyGroupServiceTest`                               | **Certified (Pest)**             |
| Demo seeder uses services only        | `LocalDemoWorkspaceSeeder::ensureBrokerOperationalFixtures` | **Certified (Pest seeder test)** |

## Not run

- Live PAN validation providers
- Production TDS filing exports

**Verdict:** TDS and settlement rules **certified at automated test level**; external tax production activation remains Pending.
