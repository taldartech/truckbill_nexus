# F — Responsive Rules

**Status:** Proposed for Product Owner design review  
**Reuse:** Phase 2 sidebar collapse, sheet mobile nav, stacked headers.

## Breakpoints

| Viewport | Width      | Layout                                                                                     |
| -------- | ---------- | ------------------------------------------------------------------------------------------ |
| Desktop  | ≥1024px    | Persistent sidebar; full tables; dual-column forms (label left / fields right optional)    |
| Tablet   | 768–1023px | Collapsible sidebar; tables with horizontal scroll; forms single column                    |
| Mobile   | <768px     | Sheet nav; stacked page header; card-list alternative for dense tables; sticky primary CTA |

## Patterns

| Pattern           | Desktop                        | Mobile                                           |
| ----------------- | ------------------------------ | ------------------------------------------------ |
| Master lists      | Full table + filter bar        | Card list: primary field + chips + overflow menu |
| Filters           | Inline row                     | Sheet “Filters”                                  |
| Party role panels | Side-by-side tabs              | Accordion                                        |
| Document drawer   | Right drawer 420px             | Full-screen sheet                                |
| Pricing breakdown | Two columns (inputs / summary) | Stacked summary first                            |
| Confirmations     | Center dialog                  | Bottom sheet or full dialog                      |

## Touch / a11y

- Hit targets ≥44px on mobile actions
- Focus rings visible (Design.md §13)
- Status never by colour alone
- Tables: `scope` headers; sortable columns keyboard reachable when implemented

## Prototype frames

Static prototypes include desktop canvas (~1280) and a “Mobile frame” toggle section on key screens (parties list, subscription overview, documents).

## Prototype breakpoint alignment (Visual QA)

Static prototypes implement:

| Width      | Behavior                                                                                    |
| ---------- | ------------------------------------------------------------------------------------------- |
| ≥1024px    | Persistent sidebar; Menu hidden                                                             |
| 768–1023px | Sidebar hidden; Menu opens accessible nav sheet; tables retained with scroll hint           |
| &lt;768px  | Menu sheet; card lists for dense masters; document drawer full-width; pricing summary first |

Verified against QA-01 (cloud-browser review).
