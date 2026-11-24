# Batch 2 — UI/UX Acceptance Reopened

**Date:** 24 November 2026  
**Authority:** Product Owner rejection of visual/interaction parity verdict  
**Baseline HEAD:** `edbc718`  
**Correction HEAD (this pass):** `196a7ae`  
**Status:** **REOPENED — IN PROGRESS** (do not declare Batch 2 complete)

## Rejection summary

Manual browser testing showed the Nexus Bilty creation screen remains substantially different from legacy Broker in layout, information hierarchy, field grouping, selector interaction, and Pay To behavior.

Functional equivalence is **not** accepted as UI/UX parity.

## Mandatory correction themes

1. Single-control searchable combobox (not search + dropdown + link stack)
2. Compact Bilty page header matching legacy toolbar density
3. Receipt Details two-panel layout (Document | Vehicle & Payee)
4. Market-vehicle **Pay To: Truck Owner | Agency** with TDS/Driver preview
5. Parties & Route two-sided FROM → TO layout
6. Inline quick-create modal/drawer preserving in-progress form
7. Extend the same methodology across remaining Broker screens

## Progress (verified this session)

| Theme                     | Status  | Evidence                                                                                                                                               |
| ------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| SearchableCombobox        | Done    | `resources/js/components/searchable-combobox.tsx`; party/vehicle wrappers                                                                              |
| Compact Bilty header      | Done    | `PageShell` `chrome="compact"`; title “Add New Good Receipt (Bilty)”                                                                                   |
| Receipt Details two-panel | Done    | Document \| Vehicle & Payee cards                                                                                                                      |
| Pay To Owner \| Agency    | Done    | Intended payee on LR; hire payee preview prefers intended; TDS via `/parties/tds-for-payee`                                                            |
| Parties & Route FROM/TO   | Done    | Appears after market vehicle selection                                                                                                                 |
| Quick-create drawer       | Done    | Party + Market vehicle sheets; JSON store responses; wired on Bilty / Hire / Linked / Enquiry                                                          |
| Remaining Broker screens  | Partial | Combobox + quick-create wired on Hire, Linked Bilty, Enquiry; register/details/voucher/etc. still need layout pass                                     |
| Screenshot certification  | Partial | Nexus captures under `storage/visual-certification/batch2-uiux/`; legacy app not launched this session — use Blade `create.blade.php` + PO screenshots |

### Nexus screenshots (1440×900 / 1920×1080)

- `storage/visual-certification/batch2-uiux/02-nexus-bilty-create-1440.png`
- `storage/visual-certification/batch2-uiux/03-nexus-bilty-create-1920.png`
- `storage/visual-certification/batch2-uiux/04-nexus-bilty-payto-1440.png` (market truck selected → Pay To pills)

### Observed corrected differences vs prior Nexus

- Removed stacked search + dropdown + deep-link Create controls
- Compact heading with Back + Add Customer (inline quick create)
- Two-panel Receipt Details; Customer no longer isolated on empty row
- Explicit Pay To Truck Owner \| Agency for market vehicles
- FROM/TO Parties & Route with connector after vehicle context
- Add beside labels opens Sheet (does not abandon Bilty draft)

### Intentional approved Nexus differences (preserved)

Unified Party/Vehicle/Bilty/Invoice; MT weights; optional goods description; blank bilty = auto number; financial freeze; separate Bilty vs Lorry Hire (Payee intent does **not** create Hire/payable); Market quick-create vehicle requires eligible Truck Owner (Own fleet remains full Vehicle form).

## Acceptance gate

Do **not** declare Batch 2 complete until legacy vs Nexus screenshots are compared side-by-side for every corrected screen and unnecessary differences are corrected.
