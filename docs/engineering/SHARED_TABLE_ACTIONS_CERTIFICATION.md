# Shared Table Actions — Certification (Batches E–H)

**Date:** 24 November 2026  
**Component:** `resources/js/components/app-icon-action.tsx`  
**Table shell:** `ResponsiveDataTable` (`actions` render prop)

## Certified patterns

| Pattern            | Variant                           | Usage                                     |
| ------------------ | --------------------------------- | ----------------------------------------- |
| View / open record | `info`                            | `Eye` — default list open                 |
| Edit               | `warning`                         | `Pencil` — mutable masters & LR           |
| Manage settings    | `info`                            | `Settings2` — owner branch row            |
| Enter workspace    | POST via `WorkspaceContextAction` | `LogIn` — tenant context (preserved)      |
| Download           | `outline`                         | `Download` — subscription invoice HTML    |
| Email (POST)       | `secondary`                       | `Mail` — Inertia `Form` + `type="submit"` |
| Back navigation    | `outline`                         | `ArrowLeft` — detail shells               |

## Portals certified in E–H

- **Owner:** company users list, branches list, subscription invoice rows (preview/download/email).
- **Internal:** CRM dashboard quick link; identity → company open icons (leads/tickets already from A–D).
- **Branch:** LR list, masters (parties/vehicles/stations/bank-cash), OwnFleet trips, trip invoices legacy table.
- **Broker:** lorry hires, vouchers, daily payments, customer invoices, linked bilties; detail cross-links on hire/voucher show.

## Automated checks

| Check                        | Result                                                                          |
| ---------------------------- | ------------------------------------------------------------------------------- |
| `npm run types:check`        | Pass (post E–H)                                                                 |
| `npm run check` (`vp check`) | Pass                                                                            |
| Playwright owner smoke       | Existing `tests/e2e/owner/global-panel.spec.ts` — not extended in E–H (Batch I) |

## Remaining gaps (honest)

- Raw HTML tables without `ResponsiveDataTable` on Broker registers and transporter balances — **Open**.
- Owner subscription checkout/drafts screens — no row actions; layout density **Open**.
- SA subscription invoice admin list — **Open**.
- Visual regression screenshots — **Batch I**.

## Security

- `WorkspaceContextAction` unchanged: POST-only context switches with CSRF; no GET tenant mutation.
- Icon actions use Inertia `Link` for same-origin navigations; download URLs remain GET (read-only).
