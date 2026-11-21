# TruckBill Requirements Traceability — Starter Register

This register is a control artefact, not a replacement for the six governing documents. Expand it during Phase 0 before coding.

| ID | Requirement area | Governing document | Status | Phase | Verification |
|---|---|---|---|---|---|
| TB-SEC-001 | Tenant/company/branch isolation | Architecture, Rules | Confirmed | 1–2 | Cross-tenant negative tests |
| TB-AUTH-001 | Internal/Customer identity separation | PRD, Rules | Confirmed | 2 | Identity/session tests |
| TB-OWN-001 | Owner Panel and ownership transfer | PRD, Rules | Confirmed | 2 | Owner lifecycle tests |
| TB-RBAC-001 | Separate permission domains and explicit Delete | PRD, Rules | Confirmed | 2 | Role/action matrix tests |
| TB-AUD-001 | Mandatory edit/delete remarks and immutable audit | Rules, Architecture | Confirmed | 1 | Atomic audit tests |
| TB-ENQ-001 | Website/manual enquiry and whole conversion | PRD, Rules | Confirmed | 4 | Enquiry journey tests |
| TB-LR-001 | Unified LR foundation | PRD, Rules | Confirmed | 4 | LR workflow tests |
| TB-OWNF-001 | One LR = one OwnFleet Trip | PRD, Rules | Confirmed | 5 | Trip lifecycle tests |
| TB-BRK-001 | Lorry Hire, TDS, vouchers and adjustments | PRD, Rules | Confirmed | 6 | Settlement tests |
| TB-PTL-001 | Partload Challan/Inward/DR lifecycle | PRD, Rules | Confirmed | 7 | Challan/DR tests |
| TB-ACC-001 | Integrated double-entry accounting | PRD, Rules, Architecture | Confirmed | 8 | Debit/credit/reversal tests |
| TB-SUB-001 | Common company renewal date | PRD, Rules | Confirmed | 3 | Proration test vectors |
| TB-SUB-002 | Manual Razorpay renewals in Phase 1 | Rules, Architecture | Confirmed | 3 | Webhook/payment tests |
| TB-COM-001 | In-app notifications and communications | PRD, Rules, Architecture | Confirmed | 9 | Recipient/delivery tests |
| TB-ASSET-001 | Vehicle documents/tyre/battery/maintenance | PRD, Rules | Confirmed | 10 | Asset lifecycle tests |
| TB-INV-001 | Spare Parts/Store/Job Cards | PRD, Rules | Confirmed | 10 | Stock/cost reconciliation |
| TB-DES-001 | Poppins and exact brand palette | Design | Confirmed | 1 | Visual QA |
| TB-REP-001 | Reports deferred until source workflows stable | PRD, Phases | Confirmed | 11 | Release gate |
| TB-PTL-EXP-001 | Partload Expense Management excluded | PRD, Rules | Confirmed | N/A | Scope review |

Add one row per atomic requirement, field group, calculation, transition, permission, dependency and exception. Each row must include an acceptance-test ID before implementation.
