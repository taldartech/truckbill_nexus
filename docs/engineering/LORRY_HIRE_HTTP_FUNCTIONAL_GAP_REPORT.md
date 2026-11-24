# Lorry Hire HTTP Functional Gap Report

**Status:** Corrected  
**Date:** 24 November 2026  
**Baseline remote HEAD before work:** `da75be3f3b02b88eb70011bfd8ac0dec31975784`

## Confirmed gaps (pre-fix)

| ID  | Defect                                                        | Correction                                                                             |
| --- | ------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| H1  | Transporter rate submitted as hidden copy of customer freight | Editable `#rate_*` inputs; default empty; Per MT default type                          |
| H2  | `loading_weight` omitted from controller validation           | Validated `goods_lines.*.loading_weight` and passed to `LorryHireService`              |
| H3  | Route props used only station relations                       | `loadingStationLabel` / `unloadingStationLabel` include Broker `from_text` / `to_text` |
| H4  | Rate column showed freight amount                             | Separate customer rate vs amount on create UI                                          |
| H5  | Raw enum strings on show                                      | `rate_type_label` + human labels                                                       |
| H6  | Weak validation feedback                                      | Field-level `InputError` mapped to goods rows                                          |

## Tests

`tests/Feature/LorryHireIndependentFreightTest.php` — independent ₹9,000 vs ₹7,500, persisted loading weight, free-text route on create Inertia props, HTTP store contract.
