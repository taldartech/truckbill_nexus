# I — Phase 3 Design Review Checklist

**Package status:** READY FOR PRODUCT OWNER DESIGN REVIEW  
**Formal approval:** _Not granted_ — awaiting Product Owner sign-off  
**Production UI:** Remains blocked until formal design approval + separate implementation authorization

## Review items

| #   | Item                                                                    | PO decision                                      |
| --- | ----------------------------------------------------------------------- | ------------------------------------------------ |
| 1   | Branch Masters IA (hub + Parties/Vehicles/Accounts/Stations/Catalogues) | ☐ Approve / ☐ Approve with exceptions / ☐ Revise |
| 2   | Documents embedded on parents (no global file dump)                     | ☐ Approve / ☐ Revise                             |
| 3   | Shared vs branch scope badges without inventing visibility settings UI  | ☐ Approve / ☐ Revise                             |
| 4   | Bank account masking presentation                                       | ☐ Approve / ☐ Revise                             |
| 5   | Station owner-share + default transporter helper copy                   | ☐ Approve / ☐ Revise                             |
| 6   | Owner Subscription overview/catalogue/pricing/draft/credits             | ☐ Approve / ☐ Revise                             |
| 7   | AUD-CON-006: informational only; no invented locks                      | ☐ Confirm                                        |
| 8   | Billing screens gated behind PO-P3-PAY; prefer hide nav                 | ☐ Approve / ☐ Prefer visible disabled            |
| 9   | Component reuse (no new package)                                        | ☐ Confirm                                        |
| 10  | Static prototypes acceptable as visual baseline                         | ☐ Approve / ☐ Revise                             |
| 11  | Responsive card-list on mobile for dense tables                         | ☐ Approve / ☐ Revise                             |

## Unresolved design decisions (do not invent)

| ID      | Topic                                       | Status                       |
| ------- | ------------------------------------------- | ---------------------------- |
| P3-D-01 | Company Data Visibility settings UI         | Deferred (Phase 2 exception) |
| P3-D-02 | Full bank account reveal-on-edit            | Proposal — needs PO          |
| P3-D-03 | Billing nav visible vs hidden pre-G-PAY     | Proposal                     |
| P3-D-04 | AUD-CON-006 entitlement UX                  | Pending                      |
| P3-D-05 | GST/TDS Customer-facing read pickers timing | After transaction modules    |

## Sign-off block (Product Owner)

| Field              | Value                                                            |
| ------------------ | ---------------------------------------------------------------- |
| Decision           | _Pending_                                                        |
| Date               |                                                                  |
| Exceptions         |                                                                  |
| Next authorization | Production UI implementation requires separate explicit approval |

**Do not** mark this checklist Approved automatically.

## Visual QA closure (22 November 2026)

Independent cloud-browser findings QA-01…QA-09 corrected in static prototypes. See `VISUAL_QA_CLOSURE_REPORT.md`.

| #   | Item                                                                      | PO decision                                      |
| --- | ------------------------------------------------------------------------- | ------------------------------------------------ |
| 12  | Accept corrected prototypes as visual baseline for formal design approval | ☐ Approve / ☐ Approve with exceptions / ☐ Revise |

**Formal approval still Pending Product Owner sign-off.**
