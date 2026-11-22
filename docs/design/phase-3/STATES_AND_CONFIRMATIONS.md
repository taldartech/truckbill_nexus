# E — Loading, Empty, Error, and Confirmation States

**Status:** Proposed for Product Owner design review

## 1. Loading

| Context           | Pattern                                                          |
| ----------------- | ---------------------------------------------------------------- |
| List first paint  | Table skeleton rows (`ui/skeleton`) — 5 rows                     |
| Form submit       | Primary button spinner + disabled controls                       |
| Document scan     | Inline status “Scanning…” with spinner; poll or deferred refresh |
| Pricing calculate | Inline panel skeleton; keep prior result until replaced          |

## 2. Empty

Reuse `ShellEmptyState` + primary CTA when permitted.

| Screen          | Copy (proposal)                                                 |
| --------------- | --------------------------------------------------------------- |
| Parties         | “No parties yet. Create a party to use in future consignments.” |
| Vehicles        | “No vehicles in this branch scope.”                             |
| Bank/Cash       | “No bank or cash accounts.”                                     |
| Stations        | “No stations for this owner.”                                   |
| Attachments     | “No documents attached.”                                        |
| Draft contracts | “No draft contracts. Run the pricing calculator to create one.” |
| Credits         | “No credit movements yet.”                                      |

## 3. Errors

| Type                               | UI                                                                |
| ---------------------------------- | ----------------------------------------------------------------- |
| Validation                         | `InputError` under fields; focus first invalid                    |
| Domain (403/deny)                  | Inline alert or redirect to no-access; never expose other tenants |
| File reject                        | Toast + retain form; storage unchanged                            |
| TDS no-match (Internal test tools) | Explicit config error banner                                      |
| Network                            | Sonner error; retry where safe                                    |

## 4. Confirmations

| Action                        | Requires remark | Copy highlights                                        |
| ----------------------------- | --------------- | ------------------------------------------------------ |
| Soft-delete master            | Yes             | “This hides the record. Restoration is not available.” |
| Remove party role             | Yes             | “Role details are retained for audit.”                 |
| Replace document              | Optional label  | “Current file stays in history until policy cleanup.”  |
| Soft-delete attachment        | Yes             | “Retained files may still count toward storage.”       |
| Deactivate GST/TDS (Internal) | Yes             | “New transactions only; history unchanged.”            |

## 5. Permission-dependent chrome

| State            | Behaviour                                   |
| ---------------- | ------------------------------------------- |
| No Create        | Hide Create button; empty state without CTA |
| No Edit          | Detail read-only; hide Edit                 |
| No Delete        | Hide delete; no overflow item               |
| Viewer documents | Download if available; no upload/replace    |

## 6. Status badges (not colour alone)

| Status                                         | Text                       | Icon hint                     |
| ---------------------------------------------- | -------------------------- | ----------------------------- |
| Shared / This branch                           | Label                      | Building / Pin                |
| Active / Inactive                              | Label                      | Check / Pause                 |
| Scan pending / scanning / available / rejected | Label                      | Clock / Spinner / Check / Ban |
| Contract draft                                 | Label                      | File                          |
| Subscription informational                     | e.g. “Active” if Confirmed | Never invent Restricted locks |
