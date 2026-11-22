# Phase 3 Execution Plan — Masters / Documents / Subscription

**Status:** **AUTHORIZED** — PO-P3-AUTH APPROVED (22 November 2026); subject to per-workstream gates  
**Authority:** `docs/governing/Phases.md` (sole phase-numbering and Phase 3 scope authority)  
**Related:** `docs/engineering/PHASE_3_READINESS_SUMMARY.md`, `docs/engineering/PHASE_2_ACCEPTANCE_RECORD.md` (PO-P2-FINAL-ACCEPT)  
**Baseline tip at planning:** `020b55509c40171dc8d2b93829670ab3cb24a712` (+ CI format correction commit)

**PO-P3-AUTH APPROVED.** Implement within this plan and per-workstream gates. Preserve Phase 2 exceptions (AUD-CON-006 Pending; legal/tax company fields Pending; visibility/numbering/documents settings Deferred; membership reactivation UI Deferred; PO-P1-DEPLOY Deferred). Do not invent subscription entitlements.

---

## 1. Scope

### In scope (Phases.md)

1. Party, Vehicle, Bank/Cash Accounts, Station, Goods and supporting masters
2. GST Rate Master; TDS Rule Master
3. Common document service (on Phase 1 private-file foundation)
4. Subscription catalogue, contracts, entitlements, trial, capacity, quotas, credits
5. Initial **manual** Razorpay renewals (no auto-renew)
6. Invoice-number approval; Owner billing surfaces

### Exit criteria (Phases.md)

- Pricing calculation
- Webhook idempotency
- Entitlement enforcement (within approved / non-Pending rules)
- Storage and credit reconciliation
- Immutable contracts

### Explicitly out of scope

- Broker / OwnFleet / Partload operational modules (Phases 4–7)
- Automatic Razorpay mandates / auto-renew
- Live production payment processing without explicit PO authorization
- Invented AUD-CON-006 trial/grace/restricted/suspended entitlement matrix
- ADR-010 accounting posting architecture (Pending)
- Sanctum / mobile token auth (ADR-006 web-only until revised)
- Hard delete / restore; public self-registration
- Sibling `website/` changes
- New Composer/NPM packages without **PO-P1-DEPS** case-by-case approval

### Reuse first (Phase 2 / Phase 1)

| Capability       | Reuse                                                                  |
| ---------------- | ---------------------------------------------------------------------- |
| Tenant isolation | `CompanyAccessService`, route binding, `RejectClientTenantIdentifiers` |
| RBAC             | Permission Masters, `PermissionAuthorizer`, immediate invalidation     |
| Workspace        | Owner/Branch panels, MRU, company switcher                             |
| Audit            | `AuditedTransaction`, append-only audit                                |
| Files            | `PrivateFileService`, scan lifecycle, soft-delete retention            |
| Async            | Outbox + idempotency consumers                                         |
| Sessions         | Fortify web sessions (ADR-006)                                         |

---

## 2. Authorization and design gates

| Gate          | Requirement                                                                                        | Status                                 |
| ------------- | -------------------------------------------------------------------------------------------------- | -------------------------------------- |
| G-P3-AUTH     | Explicit **PO-P3-AUTH** authorizing this plan                                                      | **Satisfied**                          |
| G-DESIGN-P3   | Design artefacts for Phase 3 product UI reviewed/approved before those screens                     | **Ready for PO review** (not approved) |
| G-AUD-CON-006 | Exact trial/grace/restricted/suspended entitlement matrix (TB-SUB-STATE-001)                       | **Pending** — do not invent            |
| G-PAY         | Razorpay non-prod credentials + webhook verification approach for manual renewals                  | **Open**                               |
| G-DEPS        | New packages (e.g. Razorpay SDK) via PO-P1-DEPS                                                    | **Case-by-case**                       |
| G-CI          | Every workstream: migrate + Pest (Postgres) + Pint + Larastan + FE check/types/build as applicable | Ongoing                                |

**Backend-before-UI rule:** Schema, domain services, policies, jobs, and Pest suites for a workstream may proceed after **PO-P3-AUTH** without product UI, except where the workstream’s acceptance explicitly requires UI. Product screens require G-DESIGN-P3 for that surface.

---

## 3. Workstreams

### P3-WS-0 — Authorization, decisions and design intake

| Field             | Content                                                                                                         |
| ----------------- | --------------------------------------------------------------------------------------------------------------- |
| Objective         | Record PO-P3-AUTH; inventory binding decisions; open Phase 3 design register                                    |
| Scope             | Authorization record; decision register; design deliverables inventory; Memory alignment; **no** product schema |
| Traceability      | DEC-004; Design.md §14; Phases.md §3                                                                            |
| Prerequisites     | PO-P2-FINAL-ACCEPT (satisfied)                                                                                  |
| Backend before UI | N/A (docs only)                                                                                                 |
| Blocked by        | PO-P3-AUTH                                                                                                      |
| Tests             | Documentation consistency only                                                                                  |
| Acceptance        | Written PO-P3-AUTH; design inventory listed; Pending items not reclassified                                     |
| **Status**        | **Complete** — PO-P3-AUTH recorded (`PHASE_3_AUTHORIZATION_RECORD.md`)                                          |

---

### P3-WS-1 — Master data foundation (Party, Vehicle, Bank/Cash, Station, Goods+)

| Field             | Content                                                                                                                                                              |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective         | Company/branch/Owner-scoped masters with Soft-delete, audit, visibility rules                                                                                        |
| Scope             | Party (multi-role); Vehicle; Bank/Cash Accounts; Station (Owner-shared); Goods Type and supporting Owner-specific masters per Rules §5; Payment Modes seed (central) |
| Traceability      | Rules §§5–6; PRD company/master sections; TB-SEC-001                                                                                                                 |
| Schema            | Tenant columns (`company_id` / `branch_id` / `owner_user_id` as applicable); soft-delete; role join for Party; bank fields; station defaults keyed by company/branch |
| Authz             | Branch Panel CRUD per Permission Master; Owner Panel company settings remain deferred legal/tax fields (Pending)                                                     |
| Audit             | Create/update/deactivate/role-change with remarks                                                                                                                    |
| Backend before UI | **Yes** — models, factories, services, policies, HTTP API or Inertia controllers with minimal shells                                                                 |
| UI                | Master list/form screens after G-DESIGN-P3                                                                                                                           |
| Tests             | Tenant isolation; visibility Shared vs Branch-restricted; Station Owner-share; duplicate Party across branches allowed; soft-delete no restore                       |
| Acceptance        | Masters enforce Rules §5 ownership/visibility; cross-tenant negatives pass                                                                                           |
| **Status**        | **Backend complete (Batch A+B)** — Party, Vehicle, Bank/Cash, Station, Owner catalogues, Payment Modes seed; Pest; product UI blocked on G-DESIGN-P3                 |

---

### P3-WS-2 — GST Rate Master and TDS Rule Master

| Field             | Content                                                                                                                                                                                              |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective         | Central GST rates and structured TDS rules with snapshots for later transactions                                                                                                                     |
| Scope             | GST Rate Master (active rates); TDS Rule Master (AND/OR, priority, active/inactive); no production tax legal validation claim (TB-TAX-001 remains Pending)                                           |
| Traceability      | Rules §6; TB-TAX-001 Pending external validation                                                                                                                                                     |
| Schema            | Central tables; version/effective dating as needed for snapshots                                                                                                                                     |
| Backend before UI | **Yes**                                                                                                                                                                                              |
| UI                | Internal/central admin surfaces as designed; Customer read of active rates where required                                                                                                            |
| Tests             | No-match TDS = config error; inactive rules excluded; changes apply to new transactions only                                                                                                         |
| Acceptance        | Engine selectable rates/rules without inventing production legal certainty                                                                                                                           |
| **Status**        | **Backend complete (Batch B)** — GST rate versioning/snapshots; TDS rule CRUD + AND/OR evaluator + no-match; Pest; TB-TAX-001 legal validation remains Pending                                       |
| Note              | Company GST registration/tax liability fields remain Pending schema/policy from Phase 2 exceptions — do not invent company master tax UI beyond approved Phase 2 name-only settings until PO unlocks |

---

### P3-WS-3 — Common document service

| Field             | Content                                                                                                                                                                       |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective         | Document attachment lifecycle on existing private-file infrastructure                                                                                                         |
| Scope             | Attach to authorized parent records; preview/download; replace with version history; scan-before-available; soft-delete retention counts toward storage; reconciliation hooks |
| Traceability      | TB-DOC-001/002; Rules §14; Architecture § files; ADR-008                                                                                                                      |
| Schema            | Document/attachment metadata linking `stored_files`; version chain; parent polymorphic or typed FKs                                                                           |
| Authz             | CompanyAccess + active-company file policy (PO-P2-FILE-MULTI); Internal blocked (PO-P2-FILE-INTERNAL)                                                                         |
| Backend before UI | **Yes** — service APIs, jobs for scan/cleanup/reconcile                                                                                                                       |
| UI                | Upload/preview/replace components after G-DESIGN-P3                                                                                                                           |
| Tests             | Unauthorized download denied; unscanned unavailable; replace keeps old until new succeeds; soft-delete counts storage; cross-company deny                                     |
| Acceptance        | Document service usable by later modules; storage accounting inputs correct                                                                                                   |
| **Status**        | **Backend complete (Batch B)** — attach/replace/download/soft-delete retention; storage usage + reconcile foundations; Pest security suite; UI blocked on G-DESIGN-P3         |

---

### P3-WS-4 — Subscription foundation (catalogue, contracts, pricing)

| Field             | Content                                                                                                                                                                                                                                                                   |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective         | Configurable catalogue, immutable contract snapshots, company-wide common renewal date, pricing/discount engine                                                                                                                                                           |
| Scope             | Core modules + add-ons (Rules §15 starting catalogue — configurable, not hardcoded forever); contract + items; common renewal date; discount order base→bundle→tenure→renewal→coupon; 30% monetary cap; actual-day proration; capacity/quota/credit ledgers (append-only) |
| Traceability      | TB-SUB-001, 003–005, 009–010; Rules §15; Architecture subscription domain                                                                                                                                                                                                 |
| Schema            | Plans/add-ons/versions; contracts/snapshots/amendments; usage ledger; quota counters rebuildable; credit wallets                                                                                                                                                          |
| Backend before UI | **Yes** — pricing calculator pure domain + Pest vectors                                                                                                                                                                                                                   |
| UI                | Owner subscription/catalogue views after G-DESIGN-P3                                                                                                                                                                                                                      |
| Blocked / partial | Entitlement **UX/enforcement matrix** for grace/restricted/suspended details → AUD-CON-006 Pending; implement only Confirmed Rules §15 behaviours; leave Pending matrix behind feature flags / informational states                                                       |
| Tests             | Discount order vectors; proration leap/boundary; immutable snapshot; common renewal date uniqueness per company                                                                                                                                                           |
| Acceptance        | Pricing calculation exit criterion met for Confirmed rules                                                                                                                                                                                                                |
| **Status**        | **Complete for authorized UI scope (Batch D)** — catalogue, pricing calculator, draft create/edit with immutable snapshots, credit ledgers, Owner subscription UI; payment/issuance remain gated (PO-P3-PAY); AUD-CON-006 Pending                                                                                                      |

---

### P3-WS-5 — Subscription lifecycle and entitlement enforcement

| Field             | Content                                                                                                                                                                                                                                                                   |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective         | Trial/active/grace/restricted/suspended transitions; renewal and upgrade/downgrade; enforce entitlements on authorized paths                                                                                                                                              |
| Scope             | First-company 15-day trial; paid grace view-only (configurable 10 days); Owner Panel remains per DEC-002 patterns; quota block at 100% for billable LR/Bilty (when modules exist — enforce at service boundary); storage 80/100; cancellation effective at common renewal |
| Traceability      | TB-SUB-003, 010; TB-SUB-STATE-001 **Pending**; Rules §15                                                                                                                                                                                                                  |
| Backend before UI | **Partial** — state machine + gates for **Confirmed** rules; do **not** invent AUD-CON-006 cells                                                                                                                                                                          |
| UI                | Owner lifecycle/status after G-DESIGN-P3; entitlement-dependent controls wait on AUD-CON-006                                                                                                                                                                              |
| Tests             | Trial clock; grace view-only; storage block; no invented restricted behaviours                                                                                                                                                                                            |
| Acceptance        | Confirmed lifecycle rules tested; Pending matrix explicitly documented as blocked                                                                                                                                                                                         |
| **Status**        | Not started                                                                                                                                                                                                                                                               |

---

### P3-WS-6 — Payments, webhooks, invoices, Owner billing

| Field             | Content                                                                                                                                                                                                                                         |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Objective         | Manual Razorpay renewals; signed webhooks; idempotent activation; invoices; Owner billing UI                                                                                                                                                    |
| Scope             | Create order/payment for manual renewals/top-ups; signature verify; idempotent webhook; payment activates independently of invoice-number approval; Draft→Awaiting Number Approval→Approved/Issued→Emailed; no in-system refunds; no auto-renew |
| Traceability      | TB-SUB-002, 006–008; Rules §15; Architecture payments                                                                                                                                                                                           |
| Schema            | Razorpay orders/payments/webhooks; invoices; number sequences FY-aware                                                                                                                                                                          |
| Authz             | Owner Panel billing; Internal Super Admin invoice issuer config as required                                                                                                                                                                     |
| Backend before UI | **Yes** for webhook consumer, reconciliation, invoice state machine (with test doubles)                                                                                                                                                         |
| UI                | Owner billing after G-DESIGN-P3 + G-PAY                                                                                                                                                                                                         |
| Blocked by        | G-PAY credentials/approach; package approval if SDK added; **no live charges** without explicit PO                                                                                                                                              |
| Tests             | Signature failure reject; duplicate webhook no double credit; activation without invoice approval; refund APIs absent                                                                                                                           |
| Acceptance        | Webhook idempotency + payment-activated subscription exit criteria                                                                                                                                                                              |
| **Status**        | Not started                                                                                                                                                                                                                                     |

---

### P3-WS-7 — Phase 3 exit certification

| Field         | Content                                                                                                                               |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Objective     | Prove Phases.md Phase 3 exit bullets with executed tests                                                                              |
| Scope         | Pricing; webhook idempotency; entitlement (Confirmed only); storage/credit reconciliation; immutable contracts; tenant/file negatives |
| Prerequisites | P3-WS-1–6 for authorized slices                                                                                                       |
| Acceptance    | Certification report + CI green                                                                                                       |
| **Status**    | Not started                                                                                                                           |

---

## 4. Dependency graph and critical path

```text
P3-WS-0 (PO-P3-AUTH + design intake)
    ├─► P3-WS-1 Masters ──────────────────────────┐
    ├─► P3-WS-2 GST/TDS (parallel with WS-1) ─────┤
    ├─► P3-WS-3 Documents (parallel; needs files) ┤
    └─► P3-WS-4 Subscription foundation ──────────┤
            └─► P3-WS-5 Lifecycle (AUD-CON-006 gated for Pending cells)
                    └─► P3-WS-6 Payments (G-PAY) ─┘
                                                    └─► P3-WS-7 Certification
```

### Critical path (recommended)

`P3-WS-0 → P3-WS-4 (pricing/contracts) → P3-WS-5 (Confirmed lifecycle) → P3-WS-6 (manual Razorpay) → P3-WS-7`

Masters (WS-1/2) and Documents (WS-3) proceed in **parallel** after WS-0 — they unblock later operational phases and storage reconciliation.

### Recommended first implementation slice (after PO-P3-AUTH)

**P3-WS-4 pricing calculator + immutable contract snapshot schema** (backend-only Pest vectors) **in parallel with P3-WS-1 Party master vertical slice** (schema + tenant tests).  
Rationale: proves commercial math early; proves master tenancy patterns; neither requires AUD-CON-006 inventing nor live Razorpay.

---

## 5. Backend vs UI / design prerequisites

| Workstream         | Backend without product UI   | Product UI requires                               |
| ------------------ | ---------------------------- | ------------------------------------------------- |
| P3-WS-1 Masters    | Yes                          | G-DESIGN-P3 master screens                        |
| P3-WS-2 GST/TDS    | Yes                          | G-DESIGN-P3 admin/read screens                    |
| P3-WS-3 Documents  | Yes                          | G-DESIGN-P3 upload/preview patterns (reuse shell) |
| P3-WS-4 Foundation | Yes                          | G-DESIGN-P3 Owner subscription                    |
| P3-WS-5 Lifecycle  | Partial (Confirmed only)     | G-DESIGN-P3 + AUD-CON-006 for Pending cells       |
| P3-WS-6 Billing    | Webhook/domain yes (doubles) | G-DESIGN-P3 + G-PAY; no live pay without PO       |

Design artefacts: reuse Phase 2 Owner/Branch chrome, forms, tables, empty states. Produce a compact Phase 3 design package (IA + key screens for masters, documents, subscription, billing) under `docs/design/phase-3/` when authorized — do not wait for a full redesign.

---

## 6. Testing and acceptance criteria (every implementing WS)

1. Cross-tenant / cross-branch negative tests on new HTTP, jobs, files
2. Authorization: identity → company/branch → **subscription entitlement (Confirmed)** → permission → business state
3. Audit atomicity for mutations
4. No hard delete/restore
5. Idempotency for payments/webhooks/credit reservations
6. Money/rates as decimal; snapshots immutable
7. CI: Postgres migrate + Pest, Pint, Larastan, frontend checks as applicable

Phase 3 exit suite must execute Phases.md exit bullets — not checklists alone.

---

## 7. Product Owner decisions required

| Decision ID (suggested)            | Topic                                                                                              | Blocks                                      |
| ---------------------------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| **PO-P3-AUTH**                     | Authorize Phase 3 under this plan                                                                  | All implementation                          |
| **AUD-CON-006 / TB-SUB-STATE-001** | Exact entitlement matrix for trial/grace/restricted/suspended/deactivated UX                       | Full entitlement enforcement / dependent UI |
| **PO-P3-DESIGN** (formal)          | Approve Phase 3 design artefacts                                                                   | Product UI screens                          |
| **PO-P3-PAY**                      | Non-prod Razorpay + webhook approach; when live processing allowed                                 | P3-WS-6 live paths                          |
| **PO-P1-DEPS**                     | Approve any new packages (e.g. Razorpay SDK)                                                       | Package installs                            |
| Optional unlocks                   | Legal/tax/contact company fields; visibility/numbering/document **settings** Deferred from Phase 2 | Related company settings UI                 |

Do **not** treat PO-P3-AUTH as approval of AUD-CON-006 or live payments.

---

## 8. Memory and traceability

After authorized implementation, update `Memory.md` and `Requirements-Traceability-Starter.md` with verified facts only. Keep AUD-CON-006 / TB-SUB-STATE-001 / TB-TAX-001 Pending until PO resolves.

---

## 9. Stop condition

This plan is an implementation package for Product Owner review. **No Phase 3 application code, migrations, or payment transactions are authorized by this document alone.**
