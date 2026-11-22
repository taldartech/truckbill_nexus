# Phase 4 — Product Owner Decision Package

**Date:** 22 November 2026  
**Kickoff baseline tip:** `193e6dc68b2509e90de4aa2990b1136260e80746` (Actions SUCCESS on tip)  
**Phase 3 accepted baseline:** `68d11b1ecc267e91d3adb37825d94def71334a3a` (PO-P3-FINAL-ACCEPT; Actions #60)  
**Purpose:** Identify **only genuinely unresolved** decisions required to authorize and freeze Phase 4 delivery.  
**Status:** Awaiting Product Owner decisions — **PO-P4-AUTH not granted**

Phase 3 exceptions EX-01…EX-07 remain unchanged and are **not** reopened here.

---

## 1. Already confirmed (do not reopen)

| Topic                                                                     | Authority               |
| ------------------------------------------------------------------------- | ----------------------- |
| Whole-enquiry conversion only; no partial/split                           | PRD §9; Rules §7        |
| One vehicle type per enquiry; qty may be >1                               | PRD §9; Rules §7        |
| One quotation per transporter/enquiry                                     | PRD §9; Rules §7        |
| Own Vehicle skips quotation                                               | PRD §9; Rules §7        |
| Unified LR one foundation; context From/To/party rules                    | Rules §8                |
| No loading/delivery address on LR                                         | Rules §8                |
| Rate types; actual/charged weight; EWBs; positive charges; no LR discount | Rules §8                |
| Editable until delivery; delivered locks LR                               | Rules §8                |
| No hard delete / restore                                                  | DEC-003                 |
| Web-only auth; no Sanctum                                                 | ADR-006                 |
| Confirmed subscription Rules §15 only; AUD-CON-006 Pending                | EX-01                   |
| No accounting GL                                                          | ADR-010 Pending / EX-05 |
| Phase 5–7 ops not in Phase 4                                              | Phases.md               |
| Full communications providers = Phase 9                                   | Phases.md §9            |

---

## 2. Decision register (Phase 4)

| Decision ID                       | Topic                                                 | Options / recommendation                        | Status                                    | Blocks                                         |
| --------------------------------- | ----------------------------------------------------- | ----------------------------------------------- | ----------------------------------------- | ---------------------------------------------- |
| **PO-P4-AUTH**                    | Authorize Phase 4 implementation under execution plan | Approve / Defer / Reject                        | **Open**                                  | All code/migrations                            |
| **PO-P4-DESIGN-FORMAL**           | Formal design approval for product UI                 | Approve / Approve with exceptions / Defer UI    | **Open** (draft spec ready)               | Batch G product UI; optional earlier UI slices |
| **PO-P4-WEBHOOK** (G-WEBHOOK)     | Website enquiry webhook contract                      | See §3                                          | **Open**                                  | Batch B contract freeze                        |
| **PO-P4-BROADCAST** (G-BROADCAST) | Quotation broadcast vs Phase 9                        | **Recommend Option A** — enqueue + history only | **Open**                                  | Batch D                                        |
| **PO-P4-CONVERT** (G-CONVERT)     | Conversion downstream boundary                        | **Recommend Option A** — Unified LR only        | **Open** (strongly recommended Confirmed) | Batch F                                        |
| **PO-P4-PERM** (G-PERM)           | Enquiry/LR permission codes                           | See §6                                          | **Open**                                  | Policy seed finalize                           |
| **PO-P4-NUMBER** (G-NUMBER)       | Numbering scope vs Data Visibility                    | See §7                                          | **Open / Deferred interaction**           | Batch E freeze                                 |
| **PO-P4-CNV-CARDINALITY**         | LR count when vehicle_quantity > 1                    | **Recommend N LRs** (one per vehicle unit)      | **Open** if not implicit                  | Batch F schema                                 |

---

## 3. External website webhook contract (PO-P4-WEBHOOK)

**Confirmed:** Token-authenticated webhook; unassigned company queue; rate limit; replay protection (Architecture §10; Rules §7).

**Needs PO freeze:**

| Item            | Recommendation                                                                                                                  |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Auth            | Per-company shared secret; store hash; `Authorization: Bearer` or `X-TruckBill-Webhook-Token`                                   |
| Idempotency     | Require `Idempotency-Key` (UUID); 24h retention                                                                                 |
| Replay          | Reject requests with timestamp skew > 5 minutes if signed; or nonce store                                                       |
| Payload v1      | Customer name/phone/email; pickup; delivery; goods[]; vehicle_type code; quantity; expected_loading; instructions; external_ref |
| Files           | **No** multipart upload in v1 (attach later in Branch)                                                                          |
| Rotation        | Dual-token grace 24h                                                                                                            |
| Website project | Sibling `website/` changes out of Nexus scope; contract published for website team                                              |

**PO action:** Approve recommended contract **or** specify deltas before Batch B.

---

## 4. Quotation broadcast boundary (PO-P4-BROADCAST)

| Option | Description                                                                                                                                                                              | Recommendation                            |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| **A**  | Phase 4: select transporters, persist broadcast **request**, enqueue `quotation.broadcast.requested`, show Queued/history stubs. No WhatsApp/SMS/Email provider calls. Phase 9 delivers. | **Recommended**                           |
| B      | Phase 4 implements one provider (requires Phase 9 pull-forward + credentials)                                                                                                            | Not recommended                           |
| C      | Omit broadcast UI entirely until Phase 9                                                                                                                                                 | Weaker vs PRD “broadcast… retain history” |

**PO action:** Confirm Option A (or alternate).

---

## 5. Conversion & downstream ops (PO-P4-CONVERT)

| Option | Description                                                                            | Recommendation                               |
| ------ | -------------------------------------------------------------------------------------- | -------------------------------------------- |
| **A**  | Conversion creates Unified LR record(s) only; no Trip, Lorry Hire, Partload Challan/DR | **Recommended / align Phases.md**            |
| B      | Also create stub Trip/LH shells                                                        | Premature; reject unless PO overrides Phases |

**PO action:** Confirm Option A explicitly for Batch F gate.

**Cardinality (PO-P4-CNV-CARDINALITY):** For enquiry `vehicle_quantity = N`, recommend creating **N** Unified LR rows linked to the enquiry (supports later one OwnFleet LR = one Trip). Alternative: single LR with quantity field — weaker for Ops phases.

---

## 6. Missing permissions (PO-P4-PERM)

Phase 3 Branch masters use coarse `platform.*` codes. Enquiry/LR need clearer module permissions.

**Recommended catalogue (Customer/Branch):**

```text
enquiry.view, enquiry.create, enquiry.edit, enquiry.assign,
enquiry.convert, enquiry.delete,
quotation.view, quotation.create, quotation.edit, quotation.select, quotation.broadcast,
lr.view, lr.create, lr.edit, lr.cancel, lr.delete, lr.number.adjust
```

**PO action:** Approve set (or subset) for seeder/role templates; Owner continues full access pattern.

---

## 7. Numbering scope (PO-P4-NUMBER)

Rules §8: Auto/Manual; scope follows Data Visibility.

| Item                   | Recommendation                                  |
| ---------------------- | ----------------------------------------------- |
| Shared Data Visibility | Company-wide LR/Enquiry sequences               |
| Restricted             | Branch-scoped sequences                         |
| Enquiry numbers        | Company-wide (covers unassigned website intake) |
| Manual duplicates      | Active-only unique per scope                    |

**PO action:** Confirm recommendation or defer exact UI settings while accepting sequence behaviour above for Batch E.

---

## 8. Design approvals required

| Artefact                                 | Status                              |
| ---------------------------------------- | ----------------------------------- |
| `PHASE_4_DESIGN_AND_UI_SPECIFICATION.md` | Draft — **needs PO review**         |
| Static prototypes / visual QA            | Optional follow-on after PO-P4-AUTH |
| **PO-P4-DESIGN-FORMAL**                  | **Not approved**                    |

Backend Batches A–F may proceed after **PO-P4-AUTH** without product UI. Product screens require design formal (or per-surface) approval.

---

## 9. Phase 3 exceptions (unchanged)

| EX    | Topic                  | Status                  |
| ----- | ---------------------- | ----------------------- |
| EX-01 | AUD-CON-006            | Pending — do not invent |
| EX-02 | G-PAY webhook evidence | Open                    |
| EX-03 | PO-P3-PAY-PROD         | Pending                 |
| EX-04 | Issuer/GST             | Pending                 |
| EX-05 | ADR-010                | Pending                 |
| EX-06 | Paid renewal reminders | Option D Deferred       |
| EX-07 | Production PDF         | Deferred                |

Module operations entitlement cells in AUD-CON-006 remain **PROPOSED** — Phase 4 uses Confirmed §15 Branch mutation gates only.

---

## 10. Authorization request pointer

Formal ask: `PHASE_4_AUTHORIZATION_REQUEST.md`.

**Verdict for this package:** Decisions listed above are ready for Product Owner review. No decision is auto-approved.
