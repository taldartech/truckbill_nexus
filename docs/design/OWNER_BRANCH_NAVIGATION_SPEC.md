# Owner & Branch Navigation Specification

**Status:** Implemented (navigation shell)  
**Date:** 23 November 2026  
**Decisions:** Owner Panel is global list-driven; Branch Panel is operations-only with a single Owner return action.

---

## Owner Panel

- No `CompanySwitcher` on any Owner route (`/owner/**`).
- Global sidebar: Overview (Dashboard) + Management (Companies, Branches, Users, Subscriptions, Audit).
- Company identity on company-scoped pages is **read-only** (breadcrumb / title), never a switcher.
- Entering global Owner routes clears active branch workspace session.

## Branch Panel

- Sidebar groups: Overview, Operations (implemented modules only), Masters, Branch Settings.
- `CompanySwitcher` allowed for operational company/branch context only.
- Designated Owners see exactly one footer action: **Return to Owner Panel** → `/owner`.
- Non-owners must not see that action and receive 403 on `/owner` URLs.

## Security

UI visibility does not replace server authorization, ownership checks, or tenant isolation.
