# H — Billing Future Screens (Implementation Gated)

**Status:** Design specification only  
**Implementation gate:** **PO-P3-PAY** / G-PAY (Razorpay non-prod credentials + webhook approach) + Product Owner design approval  
**Do not** build production routes until gates clear.

## Inventory (gated)

| ID      | Screen                  | Purpose                                                                               | Gate      |
| ------- | ----------------------- | ------------------------------------------------------------------------------------- | --------- |
| OP-B-01 | Billing overview        | Renewal date, last payment summary, outstanding draft invoices                        | PO-P3-PAY |
| OP-B-02 | Invoices list           | Filter by status Draft / Awaiting Number Approval / Approved / Issued / Emailed       | PO-P3-PAY |
| OP-B-03 | Invoice detail          | Line items, tax display, PDF preview placeholder                                      | PO-P3-PAY |
| OP-B-04 | Invoice number approval | Internal/Owner flow per Rules §15; payment activates independently of number approval | PO-P3-PAY |
| OP-B-05 | Manual renewal payment  | Create Razorpay order; return/callback UX; **no auto-renew**                          | PO-P3-PAY |
| OP-B-06 | Payment history         | Idempotent webhook results; failed/duplicate handling messaging                       | PO-P3-PAY |

## Explicit non-goals in this design

- Auto-renew / mandates UI
- In-system refunds
- Live production charges without explicit PO
- Invented entitlement lockdown screens (AUD-CON-006)

## Prototype treatment

`prototypes/billing-preview.html` is watermarked **FUTURE — PO-P3-PAY REQUIRED** and is not part of the implementable Phase 3 UI scope until gated approval.

## Owner nav proposal until gated

**Prefer hide** Billing nav item. Alternative: show disabled “Billing (setup pending)” only if PO prefers discoverability — mark as **Proposal**.
