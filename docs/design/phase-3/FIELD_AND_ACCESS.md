# C — Field Specifications and Access Matrix

**Status:** Proposed for Product Owner design review  
**Source of truth:** Batch A/B domain services and models. Do not invent fields.

## 1. Access / action matrix

| Surface                     | Owner                   | Member Admin (Create/Edit/Delete) | Member Viewer                     | Internal                      |
| --------------------------- | ----------------------- | --------------------------------- | --------------------------------- | ----------------------------- |
| Party CRUD                  | Yes                     | Per PM                            | View only                         | No customer masters           |
| Vehicle CRUD                | Yes                     | Per PM                            | View only                         | No                            |
| Bank/Cash CRUD              | Yes                     | Per PM                            | View only; numbers masked         | No                            |
| Station CRUD                | Yes (owner-scoped)      | View; no create                   | View                              | No                            |
| Station default transporter | Yes                     | No                                | No                                | No                            |
| Owner catalogues            | Yes                     | View                              | View                              | No                            |
| Payment modes               | Read                    | Read                              | Read                              | Seed/manage Internal only     |
| Documents on parent         | Mutate if parent mutate | Per PM                            | Download if View + file available | Blocked (PO-P2-FILE-INTERNAL) |
| Subscription overview       | Yes                     | No Owner Panel                    | No                                | Platform ops Internal         |
| Pricing / draft contract    | Yes                     | No                                | No                                | Yes (platform)                |
| GST/TDS mutate              | No                      | No                                | No                                | Yes + manage_settings         |
| Billing / Razorpay          | Gated                   | —                                 | —                                 | Gated                         |

Hide unavailable actions; never imply Delete from Admin label alone (TB-RBAC-001).

## 2. Visibility semantics (Deferred company setting)

Company Data Visibility UI remains **Deferred**. Server semantics for design:

| `branch_id` | Meaning in UI                          |
| ----------- | -------------------------------------- |
| `null`      | Shared — company-wide; badge “Shared”  |
| set         | Branch-restricted; badge “This branch” |

List pages show Shared + current-branch records. Create form: toggle “Shared across company” vs “This branch only”. **Design interpretation** of Rules §5; no invented visibility settings screen.

## 3. Party fields (BP-M-02 / 04)

### Common

| Field                  | Required     | Notes                                      |
| ---------------------- | ------------ | ------------------------------------------ |
| name                   | Yes*         | Role-dependent mandatory sets per Rules §6 |
| contact_person         | Role         |                                            |
| contact_number         | Role         |                                            |
| email                  | Optional     |                                            |
| gstin                  | Optional     |                                            |
| pan                    | Role         | Transporter mandatory                      |
| is_gst_applicable      | Default Yes  |                                            |
| state / city / address | Role         |                                            |
| branch scope           | Explicit     | Shared vs branch                           |
| roles[]                | ≥1 on create | Multi-select + role attribute panels       |

### Role-specific (stored in `role_attributes`)

| Role                             | Key attributes (Rules §6)                                                                          |
| -------------------------------- | -------------------------------------------------------------------------------------------------- |
| Customer / Consignor / Consignee | Contact number*, state*, city*                                                                     |
| Transporter                      | PAN*, PAN status*, declaration*, TDS%, bank optional, short name, beneficiary, goods vehicle count |
| Driver                           | Permanent/Market; mobile*; license for Permanent; joining/Aadhaar/PAN/bank Permanent-only          |
| General Vendor                   | Contact person*, contact number*                                                                   |
| Pump Vendor                      | Contact person*, contact number*                                                                   |

No mandatory uniqueness across GST/PAN/mobile/email. **Approved requirement.**

### List columns

Name · Roles (chips) · Contact · City · Scope · Active roles count · Updated · Actions

Filters: search (name/contact/email/pan/gstin) · role · scope.

## 4. Vehicle fields (BP-M-11 / 12)

| Field                          | Required     | Notes                                   |
| ------------------------------ | ------------ | --------------------------------------- |
| registration_number            | Yes          | Unique per company; display uppercase   |
| vehicle_type                   | Optional     | Free text or Truck Type catalogue later |
| make / model                   | Optional     |                                         |
| manufacture_year               | Optional     |                                         |
| chassis_number / engine_number | Optional     |                                         |
| capacity_mt                    | Optional     |                                         |
| is_active                      | Default true |                                         |
| branch scope                   | Explicit     | Shared vs branch                        |

**Out of scope UI:** trips, maintenance, tyre, battery.

List: Registration · Type · Make/Model · Capacity · Scope · Active · Actions  
Filters: search · type · active.

## 5. Bank / Cash (BP-M-20 / 21)

| Field               | Bank     | Cash                    |
| ------------------- | -------- | ----------------------- |
| account_kind        | bank     | cash                    |
| name                | Required | Required                |
| bank_name           | Optional | Hidden                  |
| account_number      | Required | Hidden — **mask in UI** |
| ifsc                | Optional | Hidden                  |
| account_holder_name | Optional | Hidden                  |
| is_active           | Yes      | Yes                     |
| branch scope        | Yes      | Yes                     |

Presentation uses `account_number_masked` only.

## 6. Station (BP-M-30 / 31 / 32)

| Field               | Notes                                                                            |
| ------------------- | -------------------------------------------------------------------------------- |
| code                | Owner-unique; uppercase                                                          |
| name                | Required                                                                         |
| state / city        | Optional                                                                         |
| is_active           | Default true                                                                     |
| default transporter | Per company + optional branch; changing default does not rewrite historical txns |

## 7. Supporting catalogues (BP-M-40 / 41)

Types: `goods_type`, `truck_type`, `tyre_layout`, `document_type`, `expense_category`, `package_type`.

| Field                  | Notes                                    |
| ---------------------- | ---------------------------------------- |
| code                   | Owner+type unique                        |
| name                   | Required                                 |
| attributes             | Optional — no invented schema keys in UI |
| is_active / sort_order |                                          |

Payment modes: read-only (Cash, Bank Transfer, UPI, Cheque, NEFT, RTGS, IMPS, Other).

## 8. Documents (BP-D-*)

| Field / state         | Notes                                                                     |
| --------------------- | ------------------------------------------------------------------------- |
| Max size              | 2 MB                                                                      |
| Formats               | Approved private-file set only                                            |
| document_type / label | Optional                                                                  |
| version               | Auto                                                                      |
| scan_status           | pending / scanning / available / rejected — text + icon, not colour alone |
| counts_toward_storage | Soft-deleted retained still count when flagged                            |
| Download              | Only when scan available; private stream; no public URL                   |

## 9. Subscription / pricing (OP-S-*)

| Element                             | Show                                                      | Hide / Pending                       |
| ----------------------------------- | --------------------------------------------------------- | ------------------------------------ |
| Common renewal date                 | Yes                                                       |                                      |
| Active modules / add-ons            | Yes                                                       |                                      |
| List price / discount breakdown     | Yes (base→bundle→tenure→renewal→coupon; 30% monetary cap) |                                      |
| Draft contract + snapshot           | Yes                                                       |                                      |
| Credits / quota meters              | Yes (foundations)                                         |                                      |
| Trial/grace/restricted action locks | Informational label only if Confirmed                     | Exact matrix AUD-CON-006 **Pending** |
| Live pay / mandate                  | —                                                         | G-PAY                                |

## 10. GST / TDS (Internal)

GST: code, name, rate%, effective_from/to, version, active.  
TDS: code, name, priority, AND/OR, conditions[], rate%, active.  
No production statutory-compliance badge. Footer: external legal validation Pending (TB-TAX-001).
