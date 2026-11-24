# Unified Batch 2 & 3 — Financial Certification

**Date:** 24 November 2026  
**Status:** Domain preserved; end-to-end re-certification incomplete

## Market Vehicle (expected invariants)

- Customer freight independent of transporter freight
- Intended payee on Bilty does not create Hire/payable
- Hire creates TDS snapshot + net payable
- Voucher allocates against outstanding with concurrency protection
- Invoice/receipt remain on customer side

## Own Vehicle (expected invariants)

- No Lorry Hire / transporter payable from Own vehicle Bilty
- Trip expenses (diesel/other) feed operational profitability only
- Customer invoice/receipt use unified customer finance path
- Not full GL profit (ADR-010 Pending)

## Evidence this pass

Focused Pest for redirect + LR store paths passed (14). Full financial reconciliation browser journeys and dual-DB suites not re-executed in this pass — do not claim Complete.
