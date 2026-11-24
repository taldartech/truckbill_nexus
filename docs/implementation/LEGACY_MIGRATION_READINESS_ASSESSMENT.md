# Legacy Migration Readiness Assessment

**Date:** 24 November 2026  
**Assessment only — no production data migrated**  
**Nexus tip:** `3aa5bfe5970721d3fff5632e6a1c62b23c456784`  
**Legacy Broker:** `stable` @ `6a90e955bd7a05497f60a6d0bd72891c880e6d14`  
**Legacy OwnFleet:** `development` @ `9423293edd8b4e313198728f687f657d4a64c27f`

## Verdict summary

| Customer class                             | Migration readiness                                                   |
| ------------------------------------------ | --------------------------------------------------------------------- |
| Broker — core LR→Hire→Voucher→Memo→Invoice | **Conditional W0** after PO-LGC billing/treasury decisions + training |
| Broker — GL/Tally/GSTR heavy               | **Not ready** until Phase 8 / ADR-010                                 |
| Broker — report-library dependent          | **Not ready** until Phase 11 (hub substitutes exist)                  |
| OwnFleet — trip/invoice spine              | **Conditional W0** (tax PDF EX remains)                               |
| OwnFleet — tyres/payroll/maintenance       | **Not ready** until Phase 10                                          |
| Partload                                   | **Out of scope** until Phase 7                                        |

## Entity mapping (high level)

| Legacy concept              | Nexus entity / service                                | Notes                                         |
| --------------------------- | ----------------------------------------------------- | --------------------------------------------- |
| Company / branch            | `Company`, `Branch`, memberships                      | Multi-company Owner Panel                     |
| Customer / consignor / etc. | Unified `Party` + roles                               | Deduplicate on migrate                        |
| Transporter / truck owner   | `Party` roles                                         | No platform transporter registry (PO-LGC-004) |
| Driver                      | `Party` / trip snapshots                              | Assignment history thinner than legacy        |
| Vehicle                     | Unified `Vehicle`                                     | Ownership Own vs Market                       |
| Bilty / LR                  | `LorryReceipt` + workflow context                     | Numbering via DocumentNumberAllocator         |
| Freight booking             | `LorryHire`                                           | Preserve TDS snapshots                        |
| Trip                        | `Trip` + pickups/drops/allocations                    | One active trip / Own vehicle                 |
| Vouchers                    | Transporter vouchers + status workflow                | No unsafe edit/delete                         |
| Customer invoice            | OwnFleet trip invoice + Broker invoice + unified list | Cancel+reissue model                          |
| Documents                   | `DocumentAttachmentService`                           | Versioned                                     |
| Subscription                | Platform entitlement resolver                         | Map legacy gates carefully                    |

## Must-have before cutover (any customer)

1. Successful GitHub Actions on pushed tip (post-`56da1b8`).
2. Production tax PDF decision (PO-LGC-009) if legal invoices required day-one.
3. SOP for cancel+reissue (invoices) and voucher status workflow.
4. Master data cleansing + Party unification plan.
5. Parallel-run reconciliation: open hires, open trips, outstanding invoices, transporter book balances.

## Cutover risks

| Risk                       | Mitigation                                             |
| -------------------------- | ------------------------------------------------------ |
| Duplicate parties          | Staging match keys (GSTIN/PAN/phone)                   |
| Historical float money     | Recompute with bcmath; flag deltas                     |
| Missing report exports     | Phase 11 backlog; export from registers interim        |
| Bulk master import absent  | PO-LGC-005 or staged CSV tooling                       |
| Fleet asset modules absent | Do not migrate tyre/battery customers until Phase 10   |
| Concurrent open trips      | Nexus now rejects — clean legacy overlapping schedules |

## Reconciliation checklist (cutover day)

- [ ] LR counts by status / branch
- [ ] Open Lorry Hires balances vs transporter book
- [ ] Open OwnFleet trips + vehicle assignment conflicts
- [ ] Outstanding customer invoices (Broker + OwnFleet)
- [ ] Document attachment sample hash checks
- [ ] User/role mapping vs Permission Master (AUD-013)

## Explicit non-goals of this assessment

- No migration scripts
- No production DB access
- No credential or `.env` copy from legacy
