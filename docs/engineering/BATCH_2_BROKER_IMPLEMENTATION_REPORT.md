# Batch 2 — Broker Implementation Report (in progress)

**Baseline HEAD:** `4b3b874ab08be7dcc752ed94640dbe7f72bbe925`  
**Legacy broker:** `6a90e955bd7a05497f60a6d0bd72891c880e6d14`  
**Date:** 24 November 2026  
**PO decision:** **PO-LGC-017 Accepted** — legacy Broker is the operational-flow UX reference; Nexus security/financial rules remain authoritative.

## Commits this batch (local only — no push)

| SHA                   | Purpose                                                                               |
| --------------------- | ------------------------------------------------------------------------------------- |
| `6802dc9`             | Scalable Party search + Enquiry/Bilty/Hire/Voucher/Invoice confirmations              |
| `62695e5`             | Memo/Linked confirmations + Playwright fixture updates + E2E/party cert docs          |
| `b86b721`             | Book Balance remote transporter selector                                              |
| `8f37c5a`             | Memory and gap register                                                               |
| `6553caf`             | Unified Invoice customer search                                                       |
| `f216703` / `59e397b` | Interim acceptance documentation                                                      |
| `fc923aa`             | Customer receipt confirmation                                                         |
| `4b3b874`             | Linked Bilty form binding and payment accounts                                        |
| `ab9e20d`             | Hire chip/amber, voucher preselect, bilty confirm, enquiry edit, delivery/POD confirm |
| `619974d`             | PO-LGC-017 operational-flow design pack + Memory / gap register                       |

## Implemented (cumulative)

1. **Scalable Party selectors** — `PartyOptionQuery` search/`selected_id`; `GET branch.parties.options`; remote `SearchablePartySelect`.
2. **Wired surfaces** — Enquiry, Bilty, Hire payee, Broker Invoice, Linked Bilty, Book Balance, Invoice index, Enquiry quotation transporter.
3. **Form confirmations** — Enquiry create/edit, expanded Bilty, Hire create/adjust/cancel, Voucher, Invoice, Memo (with Bilty numbers), Linked Bilty, Delivery complete, POD upload, Customer receipt.
4. **Hire UX (B2-FH-UX)** — Owner/Agency `role_label` chip; amber warning when transporter freight exceeds customer freight (does not block).
5. **Voucher UX (B2-VP-01)** — `?lorry_hire_id=` preselect + hire context panel; hire show **Record voucher** CTA.
6. **Enquiry edit (B2-EN-01)** — Overview edit form with searchable customer + change confirmation; Inertia redirect on PATCH.
7. **Operational-flow docs** — `LEGACY_BROKER_UX_ALIGNMENT.md`, `LEGACY_BROKER_SCREEN_MAPPING.md`, `BROKER_OPERATIONAL_FLOW_STANDARD.md`; Memory + gap register updated.
8. **Legacy security** — `legacy/` remains untracked and Pint-excluded.

## Not implemented (gated)

PO-LGC-001…007 (custom invoice, invoice/voucher edit, hire PATCH, platform transporter master, bulk CSV, settlement PDF).

## Remaining for Batch 2 closure

- Trip / Vehicle remote selectors (remaining B2-PS-03)
- Voucher **show** density (remaining B2-VP-01)
- Full PG Pest + MySQL 8.4 suite
- Seven-viewport visual matrix + eleven-persona RBAC browser matrix
- Large-dataset (>500) certification
- Final acceptance + security/financial certification packs (B2-DOC-01)

## Interim verdict

**C. BATCH 2 BROKER PARTIALLY COMPLETE — APPROVED FUNCTIONAL GAPS REMAIN**

Operational-flow alignment is in progress under PO-LGC-017. Do not begin Batch 3.
