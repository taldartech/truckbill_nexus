# Unified Bilty Operational Workspace

**Status:** Product Owner accepted (24 November 2026)

## Primary entry

**Bilty Register** (`/lorry-receipts`) replaces day-to-day navigation to a standalone Trips list.

## Row actions

| Vehicle              | Action            | Destination                                   |
| -------------------- | ----------------- | --------------------------------------------- |
| Own (no Trip)        | Manage Expenses   | `branch.trips.create-from-lr` (explicit init) |
| Own (Trip exists)    | Manage Expenses   | `branch.trips.show` `#expenses`               |
| Market (no Hire)     | Manage Lorry Hire | `branch.broker.lorry-hires.create-from-lr`    |
| Market (Hire exists) | Manage Lorry Hire | `branch.broker.lorry-hires.show`              |

Authorization and lifecycle gates are server-side (`primaryOperationAction`).

## Historical Trips URLs

`GET /trips` redirects to the Bilty Register.  
`GET /trips/{trip}` remains for deep links and invoices.
