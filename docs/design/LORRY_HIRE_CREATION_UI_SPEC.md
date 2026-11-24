# Lorry Hire Creation UI Spec

**Status:** Implemented  
**Date:** 24 November 2026

## Sections

A. Linked Bilty summary — number, date, parties, truck, route (station master or Broker free-text), customer freight (read-only; No-Freight View respected), goods itemization.  
B. Transporter / payee — resolved payee, role, PAN status label, server TDS percent preview.  
C. Hire charges — per goods line: customer weight/rate/amount, loading weight, transporter rate type, transporter rate, line freight preview. Default transporter rate type Per MT; rate starts empty for operator entry. Fixed amount supported.  
D. Financial summary — customer freight, transporter freight, TDS %, TDS amount, net payable, gross freight margin (`customer − transporter`, not net payable).  
E. Optional remarks.

## Commercial rule

Customer Bilty freight and transporter hire freight are independent. Never auto-equate rates or amounts.
