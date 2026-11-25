# OwnFleet Operator Guide (Nexus)

**Audience:** Operators familiar with legacy TruckBill OwnFleet  
**Scope:** Authorized Batch 3 OwnFleet operations inside unified Nexus  
**Entry principle:** Select an Own Vehicle on the unified Bilty form — OwnFleet activates automatically.

## Daily path (single-point)

1. **Login** → enter Company → **Enter Branch Panel**.
2. **Dashboard** → OwnFleet operations cards (awaiting Trip, active trips, vehicles).
3. **Sidebar → Bilty Register** (or **OwnFleet — Start Trip** for awaiting Own Bilties).
4. **Create Bilty** → choose an **Own** truck → Customer, stations, goods (Units + Wt./Unit MT), freight → **Save Bilty** (or Save & continue to Trip).
5. On Bilty show / register row → **Start Trip / Expenses**.
6. Confirm vehicle/driver → **Create Trip** (or **Create Trip & Multi-point route**).
7. **Manage Expenses** workspace → record Diesel and other expenses.
8. **Trip workspace** → Next actions: Multi-point route (if needed) → Dispatch (starting odometer) → Complete (ending odometer).
9. **Invoices** → create OwnFleet customer invoice for the completed Bilty/Trip.
10. On invoice → **Record payment** (partial/full) until balance clears.

## Multi-point

1. After Trip create, open **Multi-point route** (`/trips/{id}/multi-point`) or use **Create Trip & Multi-point route**.
2. Add pickups (station, weight MT, date) and drops.
3. Allocate weight/freight until reconciled.
4. Continue to **Manage Expenses** / **Trip Operations**.

## Discoverable OwnFleet entry points

| Place                  | Action                                                    |
| ---------------------- | --------------------------------------------------------- |
| Dashboard              | OwnFleet metrics + Start Trip / Active Trip Ops           |
| Sidebar                | OwnFleet — Start Trip · OwnFleet — Active Trips           |
| Bilty Register filters | Ownership = Own · OwnFleet ops = Awaiting / Has Trip      |
| Bilty row              | Start Trip / Expenses · Trip Operations · Manage Expenses |
| Trip workspace         | Multi-point route · Manage expenses · Dispatch · Complete |

## Notes

- Market Vehicle → Broker path (Hire / Voucher). Do not mix.
- Overlap protection: one active Pending/In Progress Trip per Own vehicle (demo `MH12PEND*` reserved).
- Prefer demo `MH12FREE*` / unlocked Own vehicles for new journeys.
