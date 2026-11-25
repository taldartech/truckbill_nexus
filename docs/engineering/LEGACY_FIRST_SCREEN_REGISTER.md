# Legacy-First Frontend Reconstruction — Screen Register

**Started:** 25 November 2026  
**Baseline HEAD:** `bfaedbf`  
**Approach:** Reconstruct from `legacy/truckbill_broker` + `legacy/truckbill_ownfleet` LRF CSS/Blade; connect existing Nexus backend.

| Screen            | Legacy source                                                                                              | Status                             | Notes                                                                                                       |
| ----------------- | ---------------------------------------------------------------------------------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Bilty create      | Broker `lorry_receipts/create.blade.php` + `lr-form-redesigned.css`; OwnFleet create for Own vehicle panel | IMPLEMENTED — VERIFICATION PENDING | Ported `lrf-page` CSS; `LrfPage` shell; receipt grid; party flow; goods metrics; Own Vehicle & Driver panel |
| Bilty edit        | same form                                                                                                  | IMPLEMENTED — VERIFICATION PENDING | Shares reconstructed create layout                                                                          |
| Bilty register    | Broker/OwnFleet index                                                                                      | NOT STARTED                        |                                                                                                             |
| Bilty detail      | show blades                                                                                                | NOT STARTED                        |                                                                                                             |
| Lorry Hire create | Broker `freight_bookings/create.blade.php`                                                                 | IMPLEMENTED — VERIFICATION PENDING | `LrfLinkedHero` KPI/panels; Hire Charges + Optional LRF sections                                            |
| Lorry Hire detail | FB show                                                                                                    | NOT STARTED                        | U23-HIRE-HERO open                                                                                          |
| Voucher create    | voucher_payments                                                                                           | NOT STARTED                        | prior searchable hire retained                                                                              |
| Linked Bilty      | linked create                                                                                              | NOT STARTED                        | prior two-panel retained                                                                                    |
| Trip / Expenses   | OwnFleet trip show                                                                                         | NOT STARTED                        |                                                                                                             |
| Multi-point       | OwnFleet multi-point                                                                                       | NOT STARTED                        |                                                                                                             |
| Invoice / Receipt | both                                                                                                       | NOT STARTED                        |                                                                                                             |

## Intentional approved differences (preserve)

- Blank bilty number = auto numbering
- MT operational / KG canonical weights; two-row goods with package type (PO-LGC)
- Intended payee does not auto-create Hire
- Stations as master FKs for OwnFleet
- No co-driver

## Legacy browser

Live legacy app launch not completed this pass — structure from Blade/CSS source evidence.
