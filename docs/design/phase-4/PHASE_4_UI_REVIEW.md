# Phase 4 UI Review Package — Batch B Enquiry Surfaces

**Status:** Ready for Product Owner formal review — **NOT Approved**  
**Gate:** `PO-P4-DESIGN-FORMAL` remains **Pending** until explicit PO decision  
**Baseline backend:** Phase 4 Batch B (`ea66c3aa…` lineage + DEC-003 goods soft-delete fix)  
**Date:** 23 November 2026

This package supports formal design sign-off for **Batch B Enquiry product UI only**. It does **not** authorize Assignment, Quotation, LR, or Conversion screens (Batches C–F).

---

## 1. Approval request (PO action required)

**Decision ID:** `PO-P4-DESIGN-FORMAL`  
**Requested outcome:** Approve / Approve with amendments / Reject

**Scope for this approval slice:**

| Screen                                                        | Ready for formal review?                              |
| ------------------------------------------------------------- | ----------------------------------------------------- |
| Enquiry List                                                  | Yes                                                   |
| Enquiry Create                                                | Yes                                                   |
| Enquiry Edit                                                  | Yes                                                   |
| Enquiry Detail (read + history; future tabs disabled)         | Yes                                                   |
| Unassigned Website Enquiry Queue (Owner / company-level only) | Yes — access rules documented                         |
| Assignment / Follow-up / Quotation / Conversion tabs          | **Out of scope** — show disabled/hidden per UX policy |
| Batch C–F screens                                             | **Not included**                                      |

**Agents must not mark this gate Approved.**

---

## 2. Navigation design

| Context          | Item                                                  | Visibility                                                     |
| ---------------- | ----------------------------------------------------- | -------------------------------------------------------------- |
| Owner Panel      | Enquiries                                             | After design formal: link under company ops nav                |
| Branch Panel     | Enquiries                                             | After design formal: branch-scoped list only                   |
| Unassigned queue | Owner / users with company-wide enquiry read          | Never expose company-wide unassigned list to Branch-only users |
| Sidebar          | No Enquiry entry until `PO-P4-DESIGN-FORMAL` Approved | Current app correctly omits                                    |

**Permission keys (authoritative catalogue):** `enquiry.view`, `enquiry.create`, `enquiry.edit`, `enquiry.assign` (assign unused until Batch C).  
**Do not invent `enquiry.update`** — Batch B / `PermissionCodes::ENQUIRY_EDIT` use `enquiry.edit`.

**Visual prototypes:** `docs/design/phase-4/prototypes/enquiry-batch-b.html` (static review only).

---

## 3. Screen inventory & fields

### 3.1 Enquiry List

| Element    | Spec                                                                                                             |
| ---------- | ---------------------------------------------------------------------------------------------------------------- |
| Pagination | Server-side                                                                                                      |
| Search     | Enquiry number, customer name                                                                                    |
| Filters    | Status, source (`website` / `manual` / …), expected loading date range, assignee (if authorized)                 |
| Columns    | Number, customer, route (pickup → delivery), truck type, vehicle qty, status, expected loading, branch, assignee |
| Actions    | View; Edit (non-terminal + permission); Create                                                                   |
| Empty      | “No enquiries match filters”                                                                                     |
| Loading    | Table skeleton                                                                                                   |

### 3.2 Enquiry Create

| Field                 | Required | Notes                                                |
| --------------------- | -------- | ---------------------------------------------------- |
| Customer Party        | Yes      | Party master picker (customer role)                  |
| Pickup location       | Yes      | Text / structured per backend contract               |
| Delivery location     | Yes      |                                                      |
| Goods rows (1+)       | Yes      | Goods type, package type, qty, actual wt, charged wt |
| Truck type            | Yes      | Exactly one                                          |
| Vehicle quantity      | Yes      | Positive integer                                     |
| Expected loading date | Yes      | Company timezone display                             |
| Instructions          | No       |                                                      |
| Submit                | —        | Disable while processing; preserve input on 422      |

### 3.3 Enquiry Edit

| Rule            | Spec                                                                  |
| --------------- | --------------------------------------------------------------------- |
| Prefill         | All mutable fields + goods rows (active only)                         |
| Edit remark     | Required meaningful remark                                            |
| Terminal status | Block mutations; show reason                                          |
| Goods history   | Soft-deleted prior rows retained (DEC-003); UI shows active grid only |
| Concurrency     | Rely on approved backend conflict contract                            |

### 3.4 Enquiry Detail

| Section                    | Content                                                                                        |
| -------------------------- | ---------------------------------------------------------------------------------------------- |
| Header                     | Number, status, source, dates                                                                  |
| Customer / route / vehicle | Read-only summary                                                                              |
| Goods                      | Active rows table                                                                              |
| History                    | Authorized status/assignment timeline when available                                           |
| Documents                  | Shared document drawer if parent support exists; else omit                                     |
| Tabs                       | Assignment, Follow-ups, Quotations, Conversion — **disabled** with “Coming in a later release” |

### 3.5 Unassigned queue

| Rule                                  | Spec                                                                           |
| ------------------------------------- | ------------------------------------------------------------------------------ |
| Definition                            | Website (or external) enquiries with no assignee / unassigned flag per backend |
| Audience                              | Company Owner / company-wide enquiry viewers only                              |
| Branch users                          | Must **not** see company-wide unassigned queue                                 |
| If authZ unresolved at implement time | Ship design-approved mockup docs only; **do not** expose data                  |

---

## 4. Workflow wireframes (textual)

```
List ──► Create ──► Save ──► Detail
  │                    ▲
  └──── Edit ◄─────────┘
         │
         └── remark + goods replace (soft-delete prior) ──► Detail
```

Website webhook path: external → backend create → appears on List / Unassigned (no customer UI for webhook itself).

---

## 5. High-fidelity / visual direction

Reuse TruckBill approved system (DEC-007):

- Primary gradient `#2FB36F → #2D8CFF`
- Surfaces `#F8F9FA` / `#F5F7FA`, text `#212529`
- Typography: Poppins
- Light-first; existing table/form/dialog primitives from Phase 2–3

**Component reuse:** `DataTable` patterns from Masters; Party/Truck/Package pickers; shared `DocumentDrawer`; Owner/Branch layout shells.

Prototype fidelity for PO review: this document + `PHASE_4_DESIGN_AND_UI_SPECIFICATION.md` screen sections. Dedicated Figma export optional if PO requests visual amendment pass.

---

## 6. Responsive & accessibility checklist

| Viewport | Requirements                                                          |
| -------- | --------------------------------------------------------------------- |
| Desktop  | Full column set; sticky actions                                       |
| Tablet   | Horizontal scroll or priority columns                                 |
| Mobile   | Stack filters; single-column create form; goods rows as stacked cards |

A11y: labelled inputs; focus rings; dialogs; status not by colour alone; `aria-busy` on submit.

---

## 7. Empty / loading / error

| State          | Behaviour                                            |
| -------------- | ---------------------------------------------------- |
| Loading list   | Skeleton rows                                        |
| Empty list     | CTA Create if permitted                              |
| 422            | Inline field errors; goods row errors indexed        |
| 403            | Toast / forbidden page; hide actions client-side too |
| 409 / conflict | Message + reload prompt                              |
| Network        | Retry affordance                                     |

---

## 8. Permission / action matrix

| Action         | `enquiry.view`      | `enquiry.create` | `enquiry.edit`     | Branch-scoped user          |
| -------------- | ------------------- | ---------------- | ------------------ | --------------------------- |
| List (branch)  | Yes (own branch)    | —                | —                  | Yes                         |
| List (company) | Yes if company-wide | —                | —                  | No unassigned company queue |
| Create         | —                   | Yes              | —                  | Yes if permitted            |
| Edit           | —                   | —                | Yes + non-terminal | Yes if permitted            |
| Detail         | Yes                 | —                | —                  | Yes if in scope             |

Server rechecks every mutation.

---

## 9. Out of scope / do not fake

- Assignment, reassignment, follow-ups (Batch C)
- Quotations / broadcast (Batch D)
- LR UI (Batch E)
- Conversion UI (Batch F) — also gated by `PO-P4-CNV-CARDINALITY`
- Live Razorpay / production invoices

---

## 10. Implementation gate after approval

When PO records **Approved** (or Approved with documented amendments) for this slice:

1. Implement Inertia pages + Wayfinder routes against existing Enquiry services.
2. Full-stack tests (Pest + available frontend/browser).
3. Do **not** wait for Batch G to introduce first Enquiry UI.

Until then: **no Enquiry product UI implementation.**

---

## 11. Related artifacts

- `docs/implementation/PHASE_4_DESIGN_AND_UI_SPECIFICATION.md`
- `docs/implementation/PHASE_4_EXECUTION_PLAN.md` (full-stack Batches C–F)
- `docs/engineering/UI_COMPLETION_AUDIT.md`
- DEC-003 soft-delete goods fix (this backfill)
