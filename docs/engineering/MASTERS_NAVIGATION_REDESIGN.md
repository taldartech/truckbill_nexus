# Masters Navigation Redesign (Batch C)

**Date:** 24 November 2026  
**Status:** Implemented in Branch portal UX Batch C

## Summary

Supporting catalogue masters are no longer combined behind `/catalogues?type=…`. Each `OwnerCatalogueType` has a dedicated clean URL and Inertia screen under **Masters** in the Branch sidebar.

## Routes

| Master             | Path                  | Route name                        |
| ------------------ | --------------------- | --------------------------------- |
| Goods types        | `/goods-types`        | `branch.goods-types.index`        |
| Truck types        | `/truck-types`        | `branch.truck-types.index`        |
| Tyre layouts       | `/tyre-layouts`       | `branch.tyre-layouts.index`       |
| Document types     | `/document-types`     | `branch.document-types.index`     |
| Expense categories | `/expense-categories` | `branch.expense-categories.index` |
| Package types      | `/package-types`      | `branch.package-types.index`      |

Legacy `/catalogues` redirects to `/goods-types` (`branch.catalogues.index` → `branch.goods-types.index`).

## Server rules

- `CatalogueController` binds catalogue type from the route; POST bodies cannot forge a different `catalogue_type`.
- `OwnerCatalogueService` remains the write authority; payment modes are not shown on catalogue master pages (payment mode master remains separate).
- Item update/delete continues on `/catalogues/{catalogueItem}` for stable action URLs.

## UI

- Shared page: `branch/masters/catalogue-type/index.tsx`
- Title, description, search, owner-only create, `AppIconAction` deactivate, `ResponsiveDataTable` list.
