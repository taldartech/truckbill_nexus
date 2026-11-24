# Unified Customer Invoice Architecture (Batch D)

**Date:** 24 November 2026  
**Status:** Implemented (read facade + workspace UI)

## Problem

Branch operators saw two competing navigation entries — **OwnFleet Invoices** (`/trip-invoices`) and **Broker Customer Invoices** (`/broker/invoices`) — for the same business concept: billing customers after carriage.

## Approach

A **read facade** consolidates list, filter, and create-routing behaviour without merging `trip_invoices` and `broker_invoices` tables or duplicating calculation services.

| Layer                                  | Responsibility                                                                 |
| -------------------------------------- | ------------------------------------------------------------------------------ |
| `TripInvoiceService`                   | Own Fleet invoice lifecycle, amounts, eligibility                              |
| `BrokerInvoiceService`                 | Broker invoice lifecycle, amounts, eligibility                                 |
| `UnifiedCustomerInvoiceReadFacade`     | Permission-aware union list, filters, LR source resolution, eligible-LR picker |
| `CustomerInvoiceController`            | Inertia workspace `/invoices`, create picker, route-create redirect            |
| Existing show/cancel/payment endpoints | Unchanged per source (`branch.trip-invoices.*`, `branch.broker.invoices.*`)    |

## Workspace (`/invoices`)

- **List:** number, date, customer, source (Own Fleet / Broker), LR refs, freight-gated amounts, status, link to source show screen.
- **Filters:** search (invoice number), customer, source, payment status, invoice status.
- **Create:** user selects eligible LRs; `resolveSingleSource()` rejects mixed Own Fleet + Broker selections with a validation message; redirect continues to the correct source create flow.

## Redirects

- `GET /trip-invoices` → `/invoices?source=own_fleet` (when authorized).
- `GET /broker/invoices` → `/invoices?source=broker` (when authorized).

Detail, print, cancel, and payment recording remain on source-specific routes to preserve settlement rules.

## Customer invoice receipts vs voucher payments

- `/payments` lists **customer invoice receipts** (Own Fleet `TripInvoicePayment` and Broker `BrokerInvoicePayment` when permitted).
- **Transporter voucher payments** stay under Broker → Voucher Payments (unchanged settlement domain).

## Authorization

Facade methods check `INVOICE_*` and `BROKER_INVOICE_*` permissions independently; unified list shows only sources the actor may view; create routing enforces source-specific create permissions.
