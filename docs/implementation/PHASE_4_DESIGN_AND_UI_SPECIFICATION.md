# Phase 4 Design & UI Specification — Enquiry & Unified LR

**Status:** **Draft for Product Owner review** — not formally approved  
**Gate:** G-DESIGN-P4 / PO-P4-DESIGN-FORMAL required before product UI implementation  
**Authority:** Design.md §§5–6, §12–14; brand DEC-007 (Poppins); gradient #2FB36F→#2D8CFF  
**Reuse:** Existing Branch Panel shell, shared React/Inertia components, document drawer, table/filter patterns from Phase 2–3

**This document is a specification for review.** Draft ≠ Approved.

---

## 1. Navigation (Branch Panel)

```text
Branch Panel
 ├── Enquiry
 │    ├── All Enquiries          /branch/.../enquiries
 │    ├── Unassigned Queue       /branch/.../enquiries/unassigned  (company-authorized)
 │    └── [Create]
 └── Operations
      └── Consignment / LR       /branch/.../lorry-receipts
```

Owner Panel: no Phase 4 operational CRUD (company admin/billing only). Super Admin: no customer enquiry/LR data paths.

Deep links from future notifications must respect permissions (Design §9).

---

## 2. Screen inventory

| ID    | Screen                    | Route intent          | Primary actors               | Batch |
| ----- | ------------------------- | --------------------- | ---------------------------- | ----- |
| ENQ-L | Enquiry List              | index                 | Branch users with view       | B/G   |
| ENQ-U | Unassigned Enquiry Queue  | unassigned            | Assign-capable company users | B/G   |
| ENQ-C | Create Enquiry            | create                | create permission            | B     |
| ENQ-E | Edit Enquiry              | edit                  | edit; not Converted          | B     |
| ENQ-D | Enquiry Detail            | show                  | view                         | C/G   |
| ENQ-A | Assignment & Follow-ups   | detail tabs / modals  | assign                       | C     |
| QT-E  | Quotation Entry           | detail / modal        | quotation create             | D     |
| QT-C  | Quotation Comparison      | compare               | view/select                  | D     |
| QT-S  | Quotation Selection       | action on compare     | select                       | D     |
| CNV-P | Conversion Preview        | convert               | convert                      | F     |
| LR-L  | LR List                   | index                 | lr.view                      | E/G   |
| LR-F  | Unified LR Form           | create/edit           | lr.create/edit               | E     |
| LR-D  | LR Detail                 | show                  | lr.view                      | E     |
| LR-N  | LR Numbering              | settings / adjust     | number.adjust                | E     |
| LR-P  | LR Print Preview          | print                 | view                         | G     |
| LR-X  | LR Cancellation & History | actions + history tab | cancel                       | E/G   |

---

## 3. Per-screen specifications

### ENQ-L — Enquiry List

| Aspect     | Spec                                                                                                                                               |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nav        | Branch → Enquiry → All                                                                                                                             |
| Access     | `enquiry.view` (or PO-approved equivalent); company+branch scope                                                                                   |
| Columns    | Source, Status, Enquiry No, Customer, Route (pickup→delivery), Goods/Weight summary, Vehicle type×qty, Expected loading, Branch, Assignee, Updated |
| Filters    | Source, status, customer, route text, date range (created/loading), branch, assignee                                                               |
| Actions    | Create; open detail; (row) assign if permitted                                                                                                     |
| Empty      | Guidance + Create CTA                                                                                                                              |
| Loading    | Table skeleton                                                                                                                                     |
| Error      | Inline toast + retry                                                                                                                               |
| Responsive | Horizontal scroll table ≥ tablet; stacked summary cards on narrow critical path                                                                    |
| A11y       | Sortable headers announced; status as text not colour-only                                                                                         |

### ENQ-U — Unassigned Queue

| Aspect     | Spec                                                                           |
| ---------- | ------------------------------------------------------------------------------ |
| Nav        | Enquiry → Unassigned                                                           |
| Access     | Company-level assign permission; `branch_id IS NULL` only                      |
| Columns    | Source (Website), Received at, Customer/contact, Route, Vehicle, Goods summary |
| Actions    | Assign branch (+ optional user); open detail (read)                            |
| Validation | Branch required; target branch in company                                      |
| Empty      | “No unassigned enquiries”                                                      |

### ENQ-C / ENQ-E — Create / Edit Enquiry

| Aspect     | Spec                                                                                                                                                                                                                     |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Fields     | Enquiry No (auto/manual per policy), Date, Customer (Party Customer or free-text contact for website), Pickup location, Delivery location, Expected loading date, Instructions, Source (manual fixed), Assignee optional |
| Goods grid | Multiple rows: goods type, package, units, weights; add/remove rows                                                                                                                                                      |
| Vehicle    | Exactly one Truck Type + quantity ≥ 1                                                                                                                                                                                    |
| Documents  | Shared document drawer (2 MB / formats Design §12)                                                                                                                                                                       |
| Validation | Required locations; ≥1 goods row; vehicle type+qty; company catalogues                                                                                                                                                   |
| Actions    | Save; Save & assign; Cancel                                                                                                                                                                                              |
| Edit lock  | Converted / Cancelled read-only except permitted status notes                                                                                                                                                            |
| Errors     | Field-level Inertia errors                                                                                                                                                                                               |

### ENQ-D — Enquiry Detail

| Aspect   | Spec                                                                                                     |
| -------- | -------------------------------------------------------------------------------------------------------- |
| Header   | No, status badge, source, customer, route, vehicle, branch, assignee                                     |
| Tabs     | Overview · Goods · Follow-ups · Assignment history · Quotations · Attachments · Conversion               |
| Actions  | Edit; Assign; Add follow-up; Request quotations; Convert (when eligible); Hold/Reject/Cancel with remark |
| Statuses | Full PRD set; colour + text                                                                              |
| History  | Append-only lists; never editable                                                                        |

### ENQ-A — Assignment & Follow-ups

| Aspect    | Spec                                                                                           |
| --------- | ---------------------------------------------------------------------------------------------- |
| Assign    | Branch select; user select (authorized membership of branch); mandatory remark on reassignment |
| Follow-up | Due date; remarks; completed flag optional                                                     |
| History   | Timestamp, actor, from→to, remark                                                              |
| Authz     | Cannot assign outside company; cannot assign user without branch access                        |

### QT-E — Quotation Entry

| Aspect      | Spec                                                                                                                                                      |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fields      | Transporter (Party Transporter), freight, rate basis, vehicle type/qty, advance, payment terms, delivery duration, validity, extra charges, remarks, docs |
| Validation  | Unique transporter per enquiry; positive freight rules as confirmed                                                                                       |
| Own Vehicle | Hide/disable quotation UI; CTA to conversion when ready                                                                                                   |
| Actions     | Save; Edit with remark                                                                                                                                    |

### QT-C / QT-S — Comparison & Selection

| Aspect  | Spec                                                                                         |
| ------- | -------------------------------------------------------------------------------------------- |
| Layout  | Comparison table: transporter, rate basis, total, advance, terms, duration, validity, status |
| Actions | Select preferred (confirm); mark others Not Selected automatically                           |
| History | Selection history panel                                                                      |
| Empty   | Prompt to enter quotations or broadcast request                                              |

### Broadcast request (within QT)

| Aspect   | Spec                                                                                             |
| -------- | ------------------------------------------------------------------------------------------------ |
| UI       | Multi-select transporters; channel checkboxes (WhatsApp/SMS/Email) as **intent**; Submit request |
| Feedback | “Queued for Communications (Phase 9)” — do not claim Sent/Delivered unless Phase 9 wired         |
| History  | Request time, targets, channels, stub status                                                     |

### CNV-P — Conversion Preview

| Aspect  | Spec                                                                                                                                           |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Shows   | Enquiry summary; vehicle qty; selected quotation or Own Vehicle; preview of LR(s) to create; workflow context selector if multi-module company |
| Gates   | All vehicles arranged; status Selected (Market) or eligible Own Vehicle; not already Converted                                                 |
| Actions | Confirm convert (idempotent); Cancel                                                                                                           |
| Errors  | Concurrent conversion conflict message; validation failures                                                                                    |
| Result  | Redirect to created LR(s) + enquiry Converted                                                                                                  |

### LR-L — LR List

| Aspect  | Spec                                                                            |
| ------- | ------------------------------------------------------------------------------- |
| Columns | LR No, Date, Workflow, From→To, Parties, Vehicle, Status, Freight total, Branch |
| Filters | Status, workflow, date, party, station/text, number                             |
| Actions | Create; open                                                                    |

### LR-F — Unified LR Form

| Aspect         | Spec                                                                                                                     |
| -------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Context banner | Broker / OwnFleet / Partload — shows required From/To/party rules                                                        |
| Fields         | Number auto/manual; date; From/To (text and/or Station); Consignor/Consignee; goods grid; EWBs; additional charges; docs |
| Goods          | Type, package, units, actual/charged weight, rate type, rate, freight (calc), optional goods value                       |
| Charges        | Description + positive amount; no discount control                                                                       |
| Validation     | Context-aware required fields; EWB number + Valid Until; freight ≥0 rules                                                |
| Actions        | Save; Print preview                                                                                                      |

### LR-D — LR Detail

| Aspect  | Spec                                                                                         |
| ------- | -------------------------------------------------------------------------------------------- |
| Tabs    | Overview · Goods · EWB · Charges · Documents · History · (future Trip/LH placeholder closed) |
| Actions | Edit (until Delivered); Cancel; Soft-delete if Pending + dependencies clear; Print           |
| Lock    | Delivered: fully read-only                                                                   |

### LR-N — Numbering

| Aspect   | Spec                                                                       |
| -------- | -------------------------------------------------------------------------- |
| Settings | Auto vs Manual default; scope display (company/branch per Data Visibility) |
| Adjust   | Next sequence; mandatory remark; audit                                     |
| Access   | Elevated permission                                                        |

### LR-P — Print Preview

| Aspect | Spec                                                                |
| ------ | ------------------------------------------------------------------- |
| Layout | Print-friendly; company logo if configured; correct scope numbering |
| A11y   | Semantic structure; sufficient contrast                             |

### LR-X — Cancellation & History

| Aspect      | Spec                                                                                   |
| ----------- | -------------------------------------------------------------------------------------- |
| Cancel      | Reason mandatory; blocked if future active Trip/LH (Phase 4: always clear unless stub) |
| Soft-delete | Pending only; dependency message if blocked                                            |
| History     | Status, edits, number adjustments, cancels                                             |

---

## 4. Shared UX rules

- Poppins; brand gradient accents sparingly on primary CTAs.
- No colour-only status; badges include text.
- Dates/currency/weights: company timezone display; UTC storage.
- Document drawer: progress, scan state, private download only.
- Loading skeletons on deferred Inertia props.
- Keyboard: focus visible; forms operable without mouse.
- Critical mobile: list → detail → primary action; full grids may require horizontal scroll with notice.
- Error envelopes consistent with existing Branch Panel.

---

## 5. Design artefacts for PO review

| Artefact                                                              | Purpose                              |
| --------------------------------------------------------------------- | ------------------------------------ |
| This specification                                                    | Interaction + field contracts        |
| Optional wireframes / static HTML prototypes (post-auth design batch) | Visual QA before PO-P4-DESIGN-FORMAL |
| Permission label map                                                  | Align G-PERM with UI affordances     |

**Do not** treat HTML prototypes as approved until formal design decision.

---

## 6. Out of UI scope (Phase 4)

- OwnFleet Trip detail operational panels (Design §6 Trip) — Phase 5
- Broker Lorry Hire / vouchers / Memo — Phase 6
- Partload Challan / Inward / DR — Phase 7
- Communications provider configuration UI — Phase 9
- Accounting vouchers — Phase 8
