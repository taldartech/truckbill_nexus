# Bilty UI Refinement Spec

**Status:** Implemented  
**Date:** 24 November 2026

## Register

Readable operational columns with explicit widths: Bilty No., Date, Truck No., Vehicle Type, Customer, Route (loading → unloading), Weight, Status, Freight (when authorized), Actions.

Compact filter toolbar (Search, Status, Operational type) with Create Bilty in the page header. No “Workflow” label.

## Actions

Horizontal icon buttons via `AppIconAction`: View (info), Edit (when pending), Manage Expenses / Manage Lorry Hire (success), Print. Backend authorization remains authoritative (AUD-013).

## Goods entry (desktop)

Each goods item uses two logical rows:

1. Goods Type, Package Type, No. of Units, Wt./Unit (kg), Actual Weight (kg), Charged Weight (kg)
2. Rate Type, Rate, Freight Amount, Goods Value, Goods Description (optional), Remove

Single DOM form (no duplicate mobile named inputs). Goods Description is optional end-to-end; missing description falls back to Goods Type label on show/print/hire.

## Parties & route

Loading / Unloading groups for consignor/consignee, city/state context, and stations (Broker free-text vs OwnFleet station master preserved).

## Remove controls

E-Way Bill, Additional Charge, and Goods Remove use destructive `Button` with `type="button"`.
