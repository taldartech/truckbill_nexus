# Phase 6 Broker — Final Workflow Certification

**Date:** 24 November 2026

## Workflow path

Market Vehicle → Broker LR → Lorry Hire → TDS → Vouchers → Book Balance → Memo/POD → Delivery → Adjustments → Customer Invoice → Payment → Linked Bilty

## Certified

| Gate                                    | Status   | Evidence                         |
| --------------------------------------- | -------- | -------------------------------- |
| Atomic voucher bulk transitions         | Fixed    | `e0ff99a`                        |
| Broker SAMPLE invoice print             | Fixed    | `e0ff99a`                        |
| POD HTTP + UI upload                    | Fixed    | `42e4caa` + Playwright           |
| Playwright complete + Linked Bilty      | Fixed    | `c6ed866`                        |
| Financial invariants / concurrency Pest | Fixed    | `657db4f`                        |
| LorryHire Active unused                 | Deferred | P56-011 — no invented activation |
