# Phase 3 Batch D — UI Completion, Functional QA & Certification

**Date:** 22 November 2026  
**Authority:** PO-P3-AUTH · PO-P3-DESIGN-FORMAL · PO-P3-UI-AUTH  
**Initial HEAD:** `dde52a944cfcb60507cf02a06f17b2adec08af52` (GitHub Actions #52 SUCCESS)  
**Final HEAD:** _(recorded after local commit)_  
**Verdict:** **COMPLETE — READY FOR PO ACCEPTANCE**

This report does **not** grant Product Owner acceptance. No push, no deployment, no live Razorpay.

---

## 1. Draft edit functionality (D1)

| Item | Result |
| --- | --- |
| Service | `CompanySubscriptionContractService::updateDraftContract` |
| Rules | Draft-only; same catalogue version; server recalculation; item reconcile; before/after immutable snapshots; audit; lockForUpdate; optimistic lock via `expected_snapshot_count` (second-precision timestamps are unreliable) |
| UI | `owner/subscription/draft-edit` + Edit link on drafts list |
| Pricing labels | Human-readable via `resources/js/lib/pricing-breakdown-labels.ts` (no raw keys) |
| Activation | Never activates; PO-P3-PAY remains gated |

## 2. Document drawer integrations (D2)

| Parent | Drawer |
| --- | --- |
| Party show | Integrated |
| Vehicle show | Integrated |
| Bank/Cash show | Integrated |
| Station show | Integrated |

Shared component: `resources/js/components/masters/document-drawer.tsx` (Radix Sheet).  
Backend reuse: `DocumentAttachmentService` + `PresentsDocumentDrawer` trait.  
Dedicated documents route preserved as full-manager fallback.

## 3. Updated routes and components

**Routes**

- `owner.subscription.drafts.edit` (GET)
- `owner.subscription.drafts.update` (PUT)

**Key components/pages**

- `resources/js/pages/owner/subscription/draft-edit.tsx`
- `resources/js/pages/owner/subscription/drafts.tsx` (edit + labelled breakdown)
- `resources/js/pages/owner/subscription/catalogue.tsx` (labelled breakdown + checkbox names)
- `resources/js/components/masters/document-drawer.tsx`
- Parent show pages under `resources/js/pages/branch/masters/*/show.tsx`
- `resources/js/pages/branch/masters/documents/index.tsx` (uses shared drawer)

## 4. Backend / domain changes

- `CompanySubscriptionContractService::updateDraftContract`
- `OwnerSubscriptionController::{editDraft,updateDraft}` + payload fields (`catalogue_version_id`, `snapshot_count`, plan/addon ids)
- `PresentsDocumentDrawer` concern; Party/Vehicle/BankCash/Station show inject `documentDrawer`

## 5. Browser QA matrix and screenshots

Real Laravel/Inertia app at `http://localhost:8000` (not static prototypes).

| Viewport | Areas exercised | Evidence |
| --- | --- | --- |
| 1280×800 | Owner dashboard, subscription overview, catalogue, draft edit | `phase-3-batch-d-evidence/*-1280x800.png` |
| 768×1024 | Party detail + document drawer | `party-document-drawer-768x1024.png` |
| 375×812 | Draft edit, Masters hub; no horizontal overflow (`scrollWidth === clientWidth`) | `*-375x812.png` |

Additional matrix sizes (320, 390, 820, 1023, 1024, 1440) sampled via CDP device metrics during the same session; no unintended horizontal overflow observed on draft-edit and masters surfaces. Representative screenshots stored under `docs/implementation/phase-3-batch-d-evidence/`.

## 6. Accessibility review (D4)

| Check | Result |
| --- | --- |
| Form labels | Present on draft edit, catalogue tenure/date/remark |
| Checkbox names | `aria-label` on catalogue/draft-edit plan & add-on checkboxes |
| Document drawer | Radix Sheet dialog; titled “Documents”; Escape closes; focus enters drawer |
| Automated axe-core | **Not installed** — no new dependency; not claimed as full automated WCAG certification |
| Manual/browser a11y | Dialog present; empty/loading/error states present; touch targets use standard Button sizes |

## 7. Security regression (D5)

Covered by existing + new Pest cases:

- Owner-only subscription screens; non-owner forbidden
- Cross-company draft edit/update → 404
- Draft-only edits; finalized/active rejected
- Invalid plans / same-module rejection
- Concurrent edit via stale snapshot count → 403
- Document company isolation & scan gating (existing Phase3DocumentsUiTest)
- No client-controlled catalogue version swap on draft update

## 8. Full validation

| Gate | Result |
| --- | --- |
| Wayfinder generate | Pass |
| `npm run check` | Pass (after format fix) |
| `npm run types:check` | Pass |
| `npm run build` | Pass |
| Pint (`--dirty`) | Pass |
| Larastan | Pass (0 errors) |
| Pest | **345 tests · 343 passed · 2 skipped · 1752 assertions** |
| Disposable `migrate:fresh` | Exercised via Pest testing database + `DatabaseSeederTest`; **not** run against the local working `nexus` database |

## 9. Defects fixed during Batch D

1. Missing draft update workflow → implemented end-to-end
2. Raw pricing-breakdown keys in UI → labelled display helper
3. Party intermediate documents dialog → shared accessible Sheet drawer
4. Catalogue/plan checkboxes lacked accessible names → `aria-label`
5. Timestamp-based optimistic lock unreliable at second precision → snapshot-count lock
6. PHPStan null-coalesce noise in `PresentsDocumentDrawer` → fixed

## 10. Remaining blockers (not Batch D scope)

- AUD-CON-006 entitlement matrix — Pending
- PO-P3-PAY / live Razorpay — not authorized
- ADR-010 accounting posting — Pending
- Contract issuance / activation transitions — gated
- Full automated axe-core suite — requires dependency approval

## 11. Local commits

See git history after commit. **No push performed.**

## 12. Readiness verdict

**COMPLETE — READY FOR PO ACCEPTANCE**

Awaiting explicit Product Owner acceptance decision. Agents must not auto-accept.
