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

## Permanent UI rules (operations)

1. Do not repeat Company/Branch context on each page — show once in `AppTopbar`.
2. Do not show ordinary Active badges unless status materially matters (e.g. deactivated).
3. Do not display Data Visibility explanations on Master list/form pages.
4. Use the top navigation for compact context and functional actions.
5. Keep operational URLs concise and session-based (`/operations`, `/parties`, … — no `/branch` prefix).
6. Avoid redundant headings and empty containers.
7. Use available screen width intelligently (multi-column forms on desktop).
8. Every ordinary table must be responsive without horizontal scrolling (P0–P3).
9. Every displayed action must be functional and provide feedback.
10. Every completed functional batch must include UI, backend, Pest, Playwright and demo seeder updates.

Branding remains: TruckBill logo, Poppins, `#2FB36F`, `#2D8CFF`, “Transport. Billing. Simplified.”

## Explicitly excluded from Phase 1 shell

Broker / OwnFleet / Partload product modules (delivered in later authorized phases), accounting GL, fake dashboards.

## Verification

- `npm run check`
- `npm run types:check`
- `npm run build`
- `tests/Feature/TechnicalUiShellTest.php`
