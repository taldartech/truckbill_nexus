# TruckBill — Phase 0 Final Consistency Report

**Date:** 2026-11-21  
**Repository:** `/Users/ttc/Sites/truckbill/nexus`  
**Scope:** Documentation-only consistency corrections after Phase 0 finalization

> **Phase 0 approval note (21 November 2026):** PO-APPROVE-001 Approved; PO-EXCEPT-001 Accepted; PO-HIST-001 Accepted. Phase 0 formally approved and closed. This consistency report’s technical findings remain valid. Pending/Deferred/Excluded/Proposed statuses were **not** changed by approval. See `PHASE_0_APPROVAL_READINESS_REPORT.md`.

**Re-verification (approval readiness audit):** Traceability re-counted at **76** unique IDs with status totals **55 / 11 / 7 / 2 / 1** (Confirmed / Resolved by User Decision / Pending / Deferred / Excluded). DEC-001–009 unchanged. DEC-004 Phase 0 prerequisite now satisfied by formal approval.

---

## 1. Corrections made

| Area                                    | Correction                                                                                                                                                                               |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Traceability recount                    | Machine-counted all `TB-*` table rows; **76 unique IDs**, zero duplicates                                                                                                                |
| Status totals                           | Recalculated from actual rows; statistics section rewritten so totals equal 76                                                                                                           |
| `TB-PTL-EXP-001`                        | Status changed from incorrectly double-counted Resolved/Excluded narrative to **Excluded** only                                                                                          |
| `TB-DES-002`                            | Restated as Design Deliverables Gate (not Phase 0 approval exit); status **Confirmed** for the gate rule; artefacts remain Not Started                                                   |
| Design / Phases / deliverables register | Same gate language: design artefacts **not** mandatory for Phase 0 approval; **are** required before product-specific Phase 1 UI                                                         |
| Phase 1 UI boundary                     | Phases.md defines technical UI shell vs product-specific screens; DEC-004 unchanged                                                                                                      |
| Company vs Owner deactivation           | PRD §4, Rules §2, Architecture §3, Design §2, Memory distinguish Owner identity deactivation from company deactivation; Owner Panel exception protected from blanket company-status deny |
| Owner Panel action list                 | Remains **Pending** — not invented                                                                                                                                                       |

### Documents updated in this correction pass

- `docs/governing/Requirements-Traceability-Starter.md`
- `docs/governing/PRD.md`
- `docs/governing/Rules.md`
- `docs/governing/Architecture.md`
- `docs/governing/Design.md`
- `docs/governing/Phases.md`
- `docs/governing/Memory.md`
- `docs/implementation/PHASE_0_DELIVERABLES.md`
- `docs/engineering/PHASE_0_FINAL_CONSISTENCY_REPORT.md` (this file)

---

## 2. Traceability row count and verified status totals

**Method:** Parse every markdown table data row whose first cell matches `TB-*`.

| Metric                    | Verified count           |
| ------------------------- | ------------------------ |
| Total requirement rows    | **76**                   |
| Unique requirement IDs    | **76**                   |
| Duplicate IDs             | **0**                    |
| Confirmed                 | **55**                   |
| Resolved by User Decision | **11**                   |
| Pending                   | **7**                    |
| Deferred                  | **2**                    |
| Excluded                  | **1** (`TB-PTL-EXP-001`) |
| Conflict                  | **0**                    |
| **Sum of statuses**       | **76** (= row total)     |

### Prior discrepancy explained

- Reported “75 rows” was wrong; the register already had 76 unique rows.
- Statistics previously listed `TB-PTL-EXP-001` as both Resolved and Excluded; status totals therefore could not reconcile cleanly.
- Classification fix: exclusion rows use status **Excluded**.

### Partload Expense Management classification

- Requirement ID preserved: `TB-PTL-EXP-001`
- Type: Exception
- Status: **Excluded**
- Sources: PRD §§1,14; Rules §12; DEC-005
- OwnFleet expense management remains in scope (not excluded by this row)

---

## 3. Cross-document consistency findings

| Topic                                    | Result                                                                                                                                                                                                   |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DEC-001 phase numbering                  | Consistent — `Phases.md` sole authority; Razorpay = Phase 3                                                                                                                                              |
| DEC-002 company deactivation             | Consistent after correction — Branch Panel blocked; Owner Panel exception; distinct from Owner identity deactivation; Architecture sequence no longer implies blanket company-status deny of Owner Panel |
| DEC-003 no hard delete/restore           | Consistent across PRD/Rules/Architecture/Memory                                                                                                                                                          |
| DEC-004 coding gate                      | Unchanged and consistent — no implementation until Phase 0 formal approval                                                                                                                               |
| DEC-005 Partload Expense exclusion       | Present in PRD, Rules, Phases, Memory, traceability (Excluded)                                                                                                                                           |
| DEC-006 no self-service account deletion | Consistent                                                                                                                                                                                               |
| DEC-007 Poppins                          | Consistent in Design/Memory/traceability                                                                                                                                                                 |
| DEC-008 Cursor rule versioning           | Prior finalization; not re-opened here                                                                                                                                                                   |
| DEC-009 admin-only email change          | Consistent                                                                                                                                                                                               |
| Tenant / branch authorization            | Architecture §3 aligned with DEC-002 exception; RLS still defence-in-depth only                                                                                                                          |
| Identity / ownership / sessions          | Unchanged product rules retained; invitation lifecycle still Pending                                                                                                                                     |
| Accounting immutability                  | Retained; mappings still externally Pending                                                                                                                                                              |
| Subscription / entitlements              | Phase 3 manual Razorpay; state matrix still Pending                                                                                                                                                      |
| Design / typography / UI gates           | Design.md, Phases.md, PHASE_0_DELIVERABLES.md now express the **same** gate                                                                                                                              |

### Residual documentation risks (not silent resolutions)

- Exhaustive Owner Panel actions while company deactivated: **Pending**
- Subscription state matrix (trial/grace/restricted/suspended/deactivated): **Pending**
- Design artefacts themselves: **Not Started** (gate documented; artefacts missing)

---

## 4. Remaining Pending decisions

| ID / topic              | Notes                                                                          |
| ----------------------- | ------------------------------------------------------------------------------ |
| TB-OWN-006              | Exhaustive Owner Panel actions while company Deactivated                       |
| TB-SUB-STATE-001        | Entitlement/UX matrix for trial / grace / restricted / suspended / deactivated |
| TB-ACC-003              | Accountant/tax-advisor validation of posting maps                              |
| TB-TAX-001              | Legal/tax validation of GST/TDS rules                                          |
| TB-NFR-001              | Load personas / RPS / SLOs for 5,000 concurrent objective                      |
| TB-NFR-002              | Numeric RPO/RTO and restore drills                                             |
| TB-INVITE-001           | Customer invitation / CRM lead → identity provisioning lifecycle               |
| ADR-006                 | Web/mobile authentication strategy                                             |
| ADR-010                 | Accounting sync vs async posting                                               |
| AUD-GAP-004/005/006     | Mobile auth detail, enquiry webhook contract, QR/Exchange abuse controls       |
| Formal Phase 0 approval | Product Owner only                                                             |

---

## 5. Remaining Deferred items

| ID / topic             | Notes                                                       |
| ---------------------- | ----------------------------------------------------------- |
| TB-SUP-001             | Internal Support dashboard / Support module phase placement |
| TB-DEF-001             | Volumetric weight and automated routing                     |
| Infra candidates       | ECS/Octane/RDS Proxy/region/Valkey — validation deferred    |
| Playwright/k6 installs | Documented in test strategy; not installed (DEC-004)        |

---

## 6. External validations required

| Area                        | Requirement                                                               |
| --------------------------- | ------------------------------------------------------------------------- |
| Accounting posting maps     | External accountant/tax-advisor validation before production (TB-ACC-003) |
| GST / TDS / transporter tax | Professional legal/tax validation before production (TB-TAX-001)          |

---

## 7. Design deliverables completion status

| Item                                          | Status                                                    |
| --------------------------------------------- | --------------------------------------------------------- |
| Deliverables register                         | Present and aligned                                       |
| All listed artefacts                          | **Not Started**                                           |
| Design Guideline PDF in repo                  | **Not Started**                                           |
| Design review                                 | **Not Started**                                           |
| Mandatory for Phase 0 approval?               | **No** (register must exist; artefacts may be incomplete) |
| Mandatory before product-specific Phase 1 UI? | **Yes** (Design Deliverables Gate)                        |

---

## 8. Confirmation: no application implementation or Git publish

| Check                                                                 | Result |
| --------------------------------------------------------------------- | ------ |
| Application PHP / React / business logic modified                     | **No** |
| Database schema / migrations / dependencies / runtime config modified | **No** |
| Phase 1 implementation started                                        | **No** |
| Git commit created by this assignment                                 | **No** |
| `git push` / force-push / publish branch/tag/release                  | **No** |

Documentation and the design-deliverables register were updated only.

---

## 9. Final recommendation

**Historical recommendation:** Ready for Product Owner Approval (with exceptions).

**Subsequent Product Owner action (21 November 2026):** Phase 0 formally approved and closed (PO-APPROVE-001), with exceptions accepted (PO-EXCEPT-001) and historical residual risk accepted (PO-HIST-001). Design artefacts remain Not Started and gated before product-specific UI. Pending/Deferred/Proposed items remain open. DEC-004 Phase 0 prerequisite satisfied; Phase 1 implementation requires separate authorization.

---

## Appendix — Pending requirement IDs (verified)

1. `TB-OWN-006`
2. `TB-SUB-STATE-001`
3. `TB-ACC-003`
4. `TB-TAX-001`
5. `TB-NFR-001`
6. `TB-NFR-002`
7. `TB-INVITE-001`

## Appendix — Deferred requirement IDs (verified)

1. `TB-SUP-001`
2. `TB-DEF-001`

## Appendix — Excluded requirement IDs (verified)

1. `TB-PTL-EXP-001`
