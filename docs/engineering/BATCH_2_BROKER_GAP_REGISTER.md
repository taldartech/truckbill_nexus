# Batch 2 — Broker Gap Register

**Status:** In progress  
**Baseline HEAD:** `d359256ea860a5a6526b98d718eeee699d552342`  
**Date:** 24 November 2026

## Authority

- Latest Product Owner Batch 2 directive
- Approved governing documents
- Legacy `truckbill_broker` as workflow evidence (not authority over Nexus rules)

## Legacy security check (Batch 2A)

| Check                                    | Result                                      |
| ---------------------------------------- | ------------------------------------------- |
| `git ls-files legacy/`                   | Empty (0 tracked paths)                     |
| `git check-ignore` for four legacy trees | Ignored                                     |
| Historical `e05d1d6` temporary un-ignore | No legacy source/credentials remain tracked |
| Pint / static analysis                   | `legacy` excluded                           |

## Do not implement (PO-LGC)

| ID         | Topic                                          |
| ---------- | ---------------------------------------------- |
| PO-LGC-001 | Custom Invoice product policy                  |
| PO-LGC-002 | Broker invoice edit                            |
| PO-LGC-003 | Voucher edit/delete                            |
| PO-LGC-004 | Platform transporter master registry           |
| PO-LGC-005 | Masters bulk CSV                               |
| PO-LGC-006 | Hire settlement statement PDF                  |
| PO-LGC-007 | Full Lorry Hire PATCH / unrestricted hire edit |

## Confirmed gaps — implementation status

| Gap ID    | Severity | Area                                     | Status          | Notes                                                                                                   |
| --------- | -------- | ---------------------------------------- | --------------- | ------------------------------------------------------------------------------------------------------- |
| B2-PS-01  | P0       | Scalable Party search                    | **Implemented** | `PartyOptionQuery` search + `selected_id`; `GET branch.parties.options`; remote `SearchablePartySelect` |
| B2-PS-02  | P0       | Wire selectors                           | **Implemented** | Enquiry, Bilty, Hire payee, Broker Invoice, Linked Bilty                                                |
| B2-CF-01  | P1       | Enquiry confirmation                     | **Implemented** | `FormConfirmationDialog` on create                                                                      |
| B2-CF-02  | P1       | Bilty confirmation                       | **Implemented** | `FormConfirmationDialog` on create/edit                                                                 |
| B2-CF-03  | P1       | Other Broker mutations                   | **Partial**     | Voucher/Invoice/Hire adjustment/cancel/Memo/Linked create confirmations done; POD/receipt remain        |
| B2-PS-03  | P2       | Invoice index / Trip / Vehicle selectors | Open            | Book Balance remote search done; remaining large-master filters                                         |
| B2-EN-01  | P2       | Enquiry edit surface                     | Partial         | Show/update exist; no dedicated edit form with searchable customer                                      |
| B2-FH-01  | P1       | Hire lifecycle edit                      | **PO-LGC-007**  | Adjustments + cancel only                                                                               |
| B2-VP-01  | P1       | Voucher UX summary completeness          | **Implemented** | Financial summary + confirmation on voucher create                                                      |
| B2-DOC-01 | P2       | Documentation pack                       | Open            | Certification docs after regression                                                                     |

## Closed by Batch 1 (do not re-open)

- Customer-only Enquiry selector eligibility
- MT operational weight UX (KG storage)
- PartyOptionQuery consolidation for role eligibility

## Browser evidence (partial)

- Enquiry selector, Bilty/Hire UI, complete Broker spine, Linked Bilty payment — Playwright passed locally (see `BATCH_2_BROKER_E2E_CERTIFICATION.md`).

## Next execution

1. Remaining selector wiring (B2-PS-03)
2. POD / customer receipt confirmation surfaces
3. Full PG + MySQL regression + visual/persona Playwright matrix
4. Final certification pack (`BATCH_2_BROKER_*` acceptance docs)
