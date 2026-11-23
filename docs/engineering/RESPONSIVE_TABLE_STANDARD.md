# Responsive Table Standard

**Status:** Accepted (Product Owner directive, 23 November 2026)  
**Applies to:** All ordinary Owner, Branch, and Internal list tables.

## Principle

Tables must remain usable **without horizontal scrolling** at supported viewport sizes. As width shrinks, lower-priority columns hide; primary identifiers and Actions remain visible. Hidden attributes stay reachable via row expand/detail.

## Column priority model

| Priority | Meaning                        | Visibility     |
| -------- | ------------------------------ | -------------- |
| **P0**   | Essential (primary identifier) | Always         |
| **P1**   | Important (status)             | ≥640px (`sm`)  |
| **P2**   | Secondary                      | ≥768px (`md`)  |
| **P3**   | Supplementary                  | ≥1280px (`xl`) |

Actions are always available (icon buttons on wider screens; may collapse secondary actions below `sm`).

## Shared component

Use `ResponsiveDataTable` from `@/components/data-table`.

Do not introduce a second table framework. Do not use `overflow-x-auto` as the normal mobile solution for management lists.

## Permanent rules

1. Every new ordinary data table must define column priorities.
2. Every newly implemented action must have a tested success path, failure path, and observable user feedback.
3. Do not introduce new session-routing behavior without migrating all affected form actions and regression tests in the same batch.

## Coverage

See `GLOBAL_UI_CONSISTENCY_AUDIT.md` table matrix and `ACTION_REGRESSION_CERTIFICATION.md` for certification evidence.
