# Unified Batch 2 & 3 — Gap Register

**Date:** 24 November 2026  
**Authority:** Combined Batch 2 Broker UI/UX + Batch 3 OwnFleet alignment  
**Baseline HEAD:** `c4fec19` (docs) / `196a7ae` (UI reopen foundations)  
**Status:** Active — not closed

## Classification

| ID                     | Gap                                                          | Class             | Status                          |
| ---------------------- | ------------------------------------------------------------ | ----------------- | ------------------------------- |
| U23-BILTY-OWN-PANEL    | Own vehicle panel shows Primary Driver; hide Pay To          | Vehicle-dependent | Aligned                         |
| U23-SAVE-TRIP          | Save & Manage Expenses → `redirect_to_trip`                  | Vehicle-dependent | Aligned                         |
| U23-QC-OWN-VEHICLE     | Inline Own Vehicle quick-create (type/tyre/driver)           | Shared            | Aligned                         |
| U23-COMBOBOX           | Single searchable combobox standard                          | Shared            | Aligned (196a7ae+)              |
| U23-PAY-TO             | Market Pay To Owner\|Agency + intended payee                 | Market            | Aligned (196a7ae+)              |
| U23-HIRE-COMPACT       | Hire create compact chrome + numbered sections               | Market visual     | Aligned (this pass)             |
| U23-REGISTER-COMPACT   | Bilty Register compact chrome                                | Shared visual     | Aligned                         |
| U23-VOUCHER-COMPACT    | Voucher create compact chrome                                | Market visual     | Aligned                         |
| U23-VOUCHER-SEARCH     | Searchable hire + account selectors on voucher create        | Market            | Aligned (this pass)             |
| U23-LINKED-LAYOUT      | Linked Bilty create two-panel Document \| Vehicle & Payee    | Market visual     | Aligned (this pass)             |
| U23-HIRE-SHOW          | Hire show compact financial cockpit                          | Market visual     | Partial (compact done; density) |
| U23-HIRE-HERO          | Legacy Freight Booking linked-bilty hero / hire charges grid | Market visual     | Open                            |
| U23-TRIP-ODO           | Odometer on trip create (legacy Vue) vs dispatch on show     | Own intentional   | Retain Nexus (dispatch)         |
| U23-MULTI-POINT        | Multi-point pickup/drop UI polish                            | Own               | Open / verify existing domain   |
| U23-EXPENSE-UX         | Manage Expenses visual parity                                | Own visual        | Partial (CTA + tab rename)      |
| U23-INVOICE-UNIFIED    | Already unified invoice — visual polish                      | Shared            | Partial                         |
| U23-LEGACY-SCREENSHOTS | Three-way visual certification artifacts                     | Certification     | Open                            |
| U23-FULL-REGRESSION    | Full PG + MySQL + Playwright matrix                          | Certification     | Open                            |

## Intentional approved differences

- Stations master FKs for OwnFleet (vs legacy free-text cities).
- Separate Bilty vs Lorry Hire / Trip entities.
- Intended payee does not auto-create Hire.
- Odometer captured at Trip dispatch/complete, not bilty create.
- No Co-driver.
- Own Vehicle Pay To / Agency hidden.
