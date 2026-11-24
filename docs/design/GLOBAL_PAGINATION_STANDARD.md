# Global Pagination Standard

**Component:** `resources/js/components/table-pagination.tsx`

## Controls

First / Previous / numbered (ellipsis) / Next / Last · Rows per page (10/25/50/100) · `Showing X–Y of Z records`

## Behaviour

- Inertia `router.get` preserves filters via `query` prop
- Page size change resets to page 1
- Server-side `paginate()` remains authoritative; max page size enforced in controllers

## Wired listings (initial)

Party Master, Vehicle Master, Bilty Register, Lorry Hires, Enquiries

Remaining Owner/Internal/Broker lists tracked in `PAGINATION_COVERAGE_REGISTER.md`.
