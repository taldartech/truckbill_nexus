# Bilty Creation UI Spec

**Status:** Product Owner accepted (24 November 2026)  
**Implementation:** `resources/js/pages/branch/lorry-receipts/lr-form.tsx`

## Layout

- Full-width PageShell (not narrow form card only).
- Section A — Bilty details (3-column compact grid).
- Section B — Goods as desktop table / mobile cards.
- E-Way Bills — compact repeatable rows.
- Additional Charges — compact table.
- Financial summary + Save Bilty / Cancel.

## Numbering

| Field              | Behavior                                            |
| ------------------ | --------------------------------------------------- |
| Bilty No.          | Optional. Placeholder: Auto-generated if left blank |
| Blank / whitespace | Server auto-allocates                               |
| Entered value      | Manual allocation + uniqueness validation           |

No Number Mode control.

## Stations

| Context             | Control                                  | Persistence                         |
| ------------------- | ---------------------------------------- | ----------------------------------- |
| OwnFleet / Partload | Station Master selects                   | `from_station_id` / `to_station_id` |
| Broker              | Free-text + station datalist suggestions | `from_text` / `to_text`             |

Labels: **Loading Station**, **Unloading Station**.

## Goods defaults

- Rate type default: `per_mt`
- Wt./Unit: client assist only
- Charged Weight: never auto-overwritten by Wt./Unit sync
- Live freight preview mirrors `FreightCalculator` (backend authoritative)
