# Unified Broker & OwnFleet UI Standard

**Date:** 24 November 2026  
**Authority:** Combined Batch 2/3 PO authorization

## Product principle

One TruckBill Nexus workspace. Vehicle ownership drives vehicle-dependent UI. Do not ship separate Broker and OwnFleet product shells for common operations.

## Shared foundations

- `PageShell` compact chrome for operational create/detail screens
- `SearchableCombobox` for master and hire selection
- Inline quick-create sheets (Party, Market Vehicle, Own Vehicle)
- Shared confirmation dialogs
- Unified Bilty Register as primary operational entry
- Unified Customer Invoice / Receipt / Documents

## Conditional surfaces

| Trigger        | Show                                           | Hide                            |
| -------------- | ---------------------------------------------- | ------------------------------- |
| Market vehicle | Pay To, Agency, TDS preview, Hire continuation | Own driver / Trip-only controls |
| Own vehicle    | Primary/Substitute Driver, Trip continuation   | Pay To / Agency / Hire          |

## Intentional Nexus differences

Stations as master FKs; separate Bilty vs Hire/Trip entities; intended payee does not auto-create Hire; no co-driver; financial freeze policies stronger than legacy.
