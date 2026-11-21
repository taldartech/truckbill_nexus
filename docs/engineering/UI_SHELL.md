# Technical UI Shell (WS-8)

**Status:** Implemented in Phase 1  
**Authority:** Design.md §1; DEC-007; TB-DES-001 / TB-DES-002 boundary

## Delivered

- Poppins as product UI font (Bunny Fonts via Vite).
- TruckBill brand tokens: `#2FB36F`, `#2D8CFF`, green→blue gradient, light surfaces (`#F8F9FA` / `#F5F7FA`), ink `#212529`.
- Light-first default appearance (dark remains optional in settings).
- Official TruckBill logo at `public/brand/truckbill-logo.svg` (and PNG).
- Shared app/auth layouts with focus-visible styles.
- Workspace empty state (`ShellEmptyState`) — no product modules.
- Existing auth surfaces preserved without self-service email change or account deletion.

## Explicitly excluded

Owner Panel, Branch Panel, Broker / OwnFleet / Partload UI, accounting, subscription, membership/RBAC screens, fake dashboards.

## Verification

- `npm run check`
- `npm run types:check`
- `npm run build`
- `tests/Feature/TechnicalUiShellTest.php`
