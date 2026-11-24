# Unified Bilty Operational Flow

**Date:** 24 November 2026

## Entry

Bilty Register → Add New Good Receipt (Bilty).

## Create sequence

1. Receipt Details (Document | Vehicle context)
2. Select Truck → ownership resolves workflow
3. Parties & Route (rules differ by ownership)
4. Goods & Freight (shared calculator; MT operational / KG canonical)
5. Optional E-Way / charges / remarks / documents
6. Confirmation
7. Continuation:
    - Market → Save & Create Lorry Hire
    - Own → Save & Manage Expenses (Trip create-from-lr)

## Downstream

Market: Hire → Voucher → Delivery/Memo → Invoice → Receipt  
Own: Trip movement → Diesel/Expenses → Complete → Profitability → Invoice → Receipt
