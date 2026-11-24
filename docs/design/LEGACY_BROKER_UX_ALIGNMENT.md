# Legacy Broker UX Alignment (PO-LGC-017)

**Date:** 24 November 2026  
**Decision:** **PO-LGC-017 — Accepted** — `legacy/truckbill_broker` is the primary reference for **operator interaction and operational flow**; Nexus security, tenancy, and financial immutability rules remain authoritative.  
**Legacy evidence:** `legacy/truckbill_broker` @ `6a90e955bd7a05497f60a6d0bd72891c880e6d14` (read-only under Nexus repo)  
**Nexus baseline (Batch 2 UX pass):** `4b3b874` (parent commit pending)  
**Verification:** **Source-verified** unless a row explicitly says browser-verified.

---

## Alignment principle

| Layer            | Authority                            | Alignment goal                                      |
| ---------------- | ------------------------------------ | --------------------------------------------------- |
| Business rules   | Governing docs + Nexus domain        | No silent weakening of freeze, audit, or tenancy    |
| Operator journey | Legacy broker Blade + DataTables     | Same mental model: list → create → confirm → pay    |
| UI mechanics     | Nexus design system (Inertia/React)  | Parity of **fields shown**, **order**, and **CTAs** |
| Navigation       | Legacy sidebar IA (Operations-first) | Map to Branch sidebar (`app-sidebar.tsx`)           |

Nexus **does not** copy legacy float math, voucher destroy, or unrestricted hire PATCH. Those remain **Intentional Difference** or **PO Decision** (PO-LGC-003, PO-LGC-007).

---

## Information architecture comparison

| Legacy section (`layouts/app.blade.php`)                  | Legacy primary routes                | Nexus Branch nav (`app-sidebar.tsx`)                           | Alignment notes                                     |
| --------------------------------------------------------- | ------------------------------------ | -------------------------------------------------------------- | --------------------------------------------------- |
| Operations → Bilty                                        | `lorry_receipts`                     | Bilty Register `/lorry-receipts`                               | List + create; Nexus adds workflow context dropdown |
| —                                                         | _(no Enquiry menu)_                  | Enquiries `/enquiries`                                         | **Intentional Difference** — Nexus-only pre-LR step |
| Operations → Lorry Hire                                   | `freight_bookings`                   | Lorry Hires `/broker/lorry-hires`                              | Create from LR; show + adjustments (not full edit)  |
| Operations → Voucher Payments                             | `voucher_payments`, `daily-payments` | Vouchers + Daily Payments under `/broker/*`                    | Hire-deep-link preselect shipped (Batch 2)          |
| Operations → Invoices                                     | `invoices`, `custom-invoices`        | Broker Invoices `/broker/invoices` + unified customer invoices | Custom invoice **PO-LGC-001**                       |
| Parties & Fleet → Transporter / Owner / Truck / Customers | Split masters                        | Party Master + Vehicle Master                                  | **Intentional Difference** — unified Party roles    |
| Setup → Master (geo, goods, truck, pump)                  | `master/*`                           | Branch catalogues (`/goods-types`, etc.)                       | Owner vs branch scope per Nexus rules               |
| Insights → Reports (22+)                                  | `reports/*`                          | Broker Registers hub `/broker/registers`                       | **Deferred** Phase 11 — subset hub only             |
| Financials → Accounting (BETA)                            | `accounting/*`                       | _(none in Branch ops)_                                         | **Deferred** ADR-010                                |

Legacy company panel is **ops-first** (Bilty → Hire → Vouchers → Invoices before masters). Nexus Branch sidebar follows the same ordering with Enquiries and registers inserted after unified Invoices.

---

## Form interaction patterns (legacy → Nexus)

| Pattern                    | Legacy behaviour (source)            | Nexus target (Batch 2)                                                         | Status (baseline `4b3b874`)                               |
| -------------------------- | ------------------------------------ | ------------------------------------------------------------------------------ | --------------------------------------------------------- |
| Save with review           | Inline/modals on heavy forms         | `FormConfirmationDialog` before POST                                           | Shipped on Enquiry, Bilty, Hire, Voucher, Invoice, Linked |
| Party pickers at scale     | DataTables + server lists            | `SearchablePartySelect` + `GET branch.parties.options`                         | **Implemented** (B2-PS-01/02)                             |
| Hire create payee clarity  | Transporter vs Owner labels in UI    | `role_label` chip + margin amber when TR freight > customer                    | **Implemented** (B2-FH-UX)                                |
| Voucher from hire          | Context on hire show / payment flows | `?lorry_hire_id=` preselect + hire context panel                               | **Improved** (B2-VP-01)                                   |
| Record voucher CTA         | Legacy hire/payment navigation       | Hire show → voucher create with hire id                                        | **Implemented**                                           |
| Bilty confirmation density | Review before save on LR forms       | Expanded `FieldReview`: parties, stations, goods rows, EWB, charges, MT totals | **Implemented** (B2-CF-02)                                |
| Linked bilty payment       | Combined payment on linked group     | Linked show pay + confirmation                                                 | Partial confirm polish (B2-CF-03)                         |
| Memo / delivery / POD      | Memo on `freight_bookings` + reports | Delivery / Memo `/broker/deliveries`                                           | Partial UX parity                                         |

---

## Recent UX landings (do not re-implement)

Documented here so Batch 2 certification does not duplicate work:

1. **Hire create** — Owner/Agency `role_label` chip; amber warning when transporter freight exceeds customer freight (`lorry-hires/create.tsx`).
2. **Voucher create** — Query `lorry_hire_id` preselect; hire context panel (freight, TDS, net, outstanding, allocatable, proposed balance).
3. **Hire show** — “Record voucher” CTA → voucher create with hire id.
4. **Bilty confirmation** — Expanded confirmation fields; billing customer name resolved from remote select option text when not in initial options.

---

## Intentional Nexus differences (not UX defects)

| Topic              | Legacy                                      | Nexus                                         |
| ------------------ | ------------------------------------------- | --------------------------------------------- |
| Master model       | Separate transporter/owner/consignee tables | Unified `Party` + role filters                |
| Payee              | Implicit from transporter/owner context     | Explicit payee on Lorry Hire                  |
| Weights            | Single-field habits                         | MT actual/charged + KG Wt./Unit (PO-LGC-011…) |
| Linked bilty       | `lorry_receipts/create-linked` at LR time   | Post-hire `linked-bilties` grouping           |
| Hire edit          | `FreightBookingController::update`          | Adjustments + cancel only (**PO-LGC-007**)    |
| Voucher mutability | edit/destroy routes                         | Status workflow (**PO-LGC-003** pending)      |
| Number assignment  | Legacy number modes                         | Nexus serial policy (**PO-P4-NUMBER**)        |
| Subscription gate  | Per-app Razorpay in broker app              | Owner subscription resolver in unified app    |

---

## Related documents

- Screen mapping: `LEGACY_BROKER_SCREEN_MAPPING.md`
- Operational spine: `BROKER_OPERATIONAL_FLOW_STANDARD.md`
- Gap tracking: `docs/engineering/BATCH_2_BROKER_GAP_REGISTER.md`
- Audit spine: `docs/audit/LEGACY_BROKER_WORKFLOW_ALIGNMENT.md`
