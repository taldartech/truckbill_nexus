# Batch 2 — Broker Gap Register

**Status:** Closed for authorized Batch 2 operational-flow scope  
**Baseline HEAD (start of this continuation):** `701f4df`  
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

## Confirmed gaps — final status

| Gap ID    | Severity | Area                                     | Final status | Notes                                                                                                                                |
| --------- | -------- | ---------------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| B2-PS-01  | P0       | Scalable Party search                    | **Aligned**  | `PartyOptionQuery` search + `selected_id`; `GET branch.parties.options`; remote `SearchablePartySelect`                              |
| B2-PS-02  | P0       | Wire selectors                           | **Aligned**  | Enquiry, Bilty, Hire payee, Broker Invoice, Linked Bilty                                                                             |
| B2-CF-01  | P1       | Enquiry confirmation                     | **Aligned**  | `FormConfirmationDialog` on create                                                                                                   |
| B2-CF-02  | P1       | Bilty confirmation                       | **Aligned**  | Expanded `FieldReview`: parties, stations, goods rows, EWB, charges, MT totals; remote party names                                   |
| B2-CF-03  | P1       | Other Broker mutations                   | **Aligned**  | Memo/Delivery/POD; hire Create & Record Voucher; bilty edit previous/new diffs; Linked pay allocations + account; hire adjustments   |
| B2-LB-01  | P0       | Linked create field binding              | **Aligned**  | `group_date` / driver / reference / remarks bound to `useForm`                                                                       |
| B2-LB-02  | P0       | Linked pay bank account                  | **Aligned**  | `bankCashAccounts` wired when `payment_mode=bank`                                                                                    |
| B2-PS-03  | P2       | Trip / Vehicle / Enquiry quote selectors | **Aligned**  | Vehicle options API + `SearchableVehicleSelect` on Bilty/Linked; Trip substitute driver remote; Enquiry quotation transporter remote |
| B2-EN-01  | P2       | Enquiry edit surface                     | **Aligned**  | Show overview edit form with searchable customer + change confirmation                                                               |
| B2-FH-01  | P1       | Hire lifecycle edit                      | **Deferred** | Adjustments + cancel only — **PO-LGC-007**                                                                                           |
| B2-FH-UX  | P2       | Hire margin / Owner-Agency chip          | **Aligned**  | `role_label` chip; amber when transporter freight > customer freight                                                                 |
| B2-VP-01  | P1       | Voucher UX summary completeness          | **Aligned**  | Create hire context + show density: freight/TDS/net/paid/outstanding, adjustments, voucher history, account                          |
| B2-DOC-01 | P2       | Documentation pack                       | **Aligned**  | Final acceptance + E2E/gap/OF parity updated after regression                                                                        |
| B2-OF-01  | P1       | Bilty Save & Create Lorry Hire           | **Aligned**  | Confirmation secondary CTA + `redirect_to_hire` → `create-from-lr` (browser-verified)                                                |
| B2-OF-02  | P2       | Bilty quick-add masters from form        | **Partial**  | Create Party/Vehicle deep-links on selectors (no modal overlay; intentional — preserve form via browser back; no extra screens)      |
| B2-OF-03  | P2       | Truck-owner declaration missing banner   | **Aligned**  | Amber banner when selected market vehicle owner transporter declaration is incomplete                                                |
| B2-OF-04  | P2       | Hire multi E-way + GST portal link       | **Aligned**  | Hire create shows LR E-Way Bills + GST portal link (EWB remains on Bilty; intentional)                                               |
| B2-OF-05  | P3       | Ops-first sidebar label polish           | **Aligned**  | Branch Operations reordered: Bilty → Hire → Vouchers → Invoices → Delivery → Linked → Balances → Enquiry                             |
| B2-OF-06  | P1       | Hire Create & Record Voucher             | **Aligned**  | Confirmation secondary CTA + `redirect_to_voucher` → voucher create with hire id                                                     |

## Closed by Batch 1 (do not re-open)

- Customer-only Enquiry selector eligibility
- MT operational weight UX (KG storage)
- PartyOptionQuery consolidation for role eligibility

## Browser evidence

| Suite                                                                                     | Result                                                          |
| ----------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| Enquiry selector + Bilty/Hire UI + complete Broker spine + Linked payment                 | Passed                                                          |
| Operational-flow parity (Save & Create Hire, edit previous/new, Record voucher preselect) | Passed (3/3)                                                    |
| Visual viewports (bilty-lorry-hire)                                                       | Captured under `storage/visual-certification/bilty-lorry-hire/` |

## Remaining Product Owner decisions

| ID                       | Topic                         | Blocks Batch 2 operational acceptance? |
| ------------------------ | ----------------------------- | -------------------------------------- |
| PO-LGC-001…007           | Deferred product policies     | **No** — explicit out of Batch 2 scope |
| PO-P4-DESIGN-FORMAL      | Broader product UI design     | Outside this OF pack                   |
| PO-P4-CNV-CARDINALITY    | Conversion LR cardinality     | Outside this OF pack                   |
| B2-OF-02 modal quick-add | Optional overlay vs deep-link | **No** — Partial justified             |

## Next execution

Batch 2 authorized operational-flow work is complete. Do **not** begin Batch 3 / Phase 7 without separate PO authorization.
