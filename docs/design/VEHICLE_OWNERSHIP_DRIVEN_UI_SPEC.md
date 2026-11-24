# Vehicle Ownership–Driven UI Spec

**Status:** Product Owner authorized (combined Batch 2/3, 24 November 2026)  
**Authority:** Explicit PO decision — ownership determines vehicle-dependent operations

## Rule

Authoritative Vehicle Master `ownership` drives workflow classification server-side (`LrVehicleClassificationService`). Client-submitted `workflow_context` is rejected when forged.

| Ownership | Context  | Bilty UI                                                                    | Continuation                                 |
| --------- | -------- | --------------------------------------------------------------------------- | -------------------------------------------- |
| Market    | Broker   | Pay To Owner\|Agency, TDS preview, text stations optional, parties required | Save & Create Lorry Hire                     |
| Own       | OwnFleet | Primary Driver panel, stations required, parties optional, no Pay To        | Save & Manage Expenses → Trip create-from-lr |

## Switching (unsaved form)

- Common values preserved (customer, goods).
- Market → Own: clear intended payee / agency / TDS preview.
- Own → Market: show Pay To; require owner or agency before submit.
- Hidden stale fields must not submit (hidden inputs cleared / omitted).

## Entitlements

Vehicle options are filtered by subscribed modules. Broker-only companies do not receive Own vehicles in operational selectors, and vice versa.
