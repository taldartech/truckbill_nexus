# Remaining Legacy Capabilities — Phase Mapping

**Date:** 24 November 2026  
**Authority:** `docs/governing/Phases.md` (DEC-001)  
**Sources:** Merged parity matrix · Broker/OwnFleet peer audits · Nexus-exclusive audit

Maps **every non-Equivalent/non-Improved** legacy capability cluster to the **correct Nexus delivery phase**. Does not invent phase numbers.

---

## Legend

| Mapping                  | Meaning                                                           |
| ------------------------ | ----------------------------------------------------------------- |
| **Close now**            | Approved Phase 1–6 scope; implement in current engineering window |
| **Phase N (authorized)** | Wait for explicit PO-PN-AUTH if not yet granted                   |
| **Deferred**             | Governing doc or ADR defers                                       |
| **Intentional**          | Approved redesign — no port                                       |
| **PO**                   | Product Owner decision before work                                |

---

## 1. Phase 3 (masters) — PO-gated / polish

| Legacy ID       | Capability                                            | Mapping        | Action                                    |
| --------------- | ----------------------------------------------------- | -------------- | ----------------------------------------- |
| BRK-M17, SHR-13 | Masters bulk CSV (parties, vehicles, stations, goods) | **PO-LGC-005** | Decide entity list before build           |
| BRK-M06         | Pump vendor as dedicated master                       | Partial polish | Optional: pump party quick-create UX      |
| BRK-M14, M15    | Transporter/truck-owner bank beneficiary screens      | Partial polish | Enrich party settlement + bank linkage UI |

---

## 2. Phase 5 (OwnFleet) — remediation status

| Legacy ID             | Capability                        | Mapping                         | Action                         |
| --------------------- | --------------------------------- | ------------------------------- | ------------------------------ |
| OF-B15                | Vehicle active-trip guard         | **Closed** Improved `3aa5bfe`   | Done                           |
| OF-B06                | Pickup/drop remove                | **Closed** Equivalent `3aa5bfe` | Done                           |
| OF-C06                | Trip expense bulk import          | **Deferred** Phase 11           | Import pack after spine        |
| OF-B19                | Trip/bilty PDF                    | Partial                         | Reuse document service print   |
| OF-A02, A08, M03, M04 | Vehicle/driver/station/goods bulk | **PO-LGC-005**                  | Same as masters bulk           |
| OF-E09, E12           | Invoice PDF / GST validation      | EX / PO-LGC-009                 | Blocked on production tax gate |

---

## 3. Phase 6 (Broker) — PO / deferred

| Legacy ID | Capability                    | Mapping          | Action                                      |
| --------- | ----------------------------- | ---------------- | ------------------------------------------- |
| BRK-VP03  | Voucher edit                  | **PO-LGC-003**   | Do not implement unsafe edit without policy |
| BRK-VP04  | Voucher void/delete           | **PO-LGC-003**   | Soft-void only if approved                  |
| BRK-VP09  | Bank payout export            | **Deferred P11** | NEFT/RTGS workbook in report/export library |
| BRK-IN03  | Broker invoice edit           | **PO-LGC-002**   | Cancel+reissue is current                   |
| BRK-FH04  | Direct hire field update      | **PO-LGC-007**   | Adjustments-only until decision             |
| BRK-FH07  | Hire settlement statement PDF | **PO-LGC-006**   | Print now vs Phase 11                       |
| BRK-LR08  | LR-list bulk voucher          | Partial polish   | Optional UX from LR index                   |
| BRK-IN04  | Invoice cancel completeness   | Partial polish   | Align copy with Rules                       |

---

## 4. Phase 7 — Partload (not authorized)

All legacy `truckbill_partload` capabilities map **Phase 7** per `Phases.md` §7. **No implementation** until PO-P7-AUTH.

| Cluster                                          | Legacy               |
| ------------------------------------------------ | -------------------- |
| Bilty types, LR, challan, inward, DR, forwarding | Partload app         |
| Partload vouchers/invoices                       | Distinct from Broker |

---

## 5. Phase 8 — Accounting (ADR-010 Pending)

| Legacy ID   | Capability                                                               |
| ----------- | ------------------------------------------------------------------------ |
| BRK-A01…A13 | Day book, ledgers, TB, P&L, BS, Tally XML, GSTR, bank reco, auto-posting |
| BRK-R04     | Party ledger **report** (financial)                                      |

**Action:** No GL UI until ADR-010 Accepted + accountant validation.

---

## 6. Phase 9 — Notifications

| Legacy ID                       | Capability                                                               |
| ------------------------------- | ------------------------------------------------------------------------ |
| BRK-P04, OF-D04, OF-J09, OF-M06 | Email templates, document alerts, invoice reminders, notification centre |

---

## 7. Phase 10 — OwnFleet assets

| Section                          | OF IDs   |              Count |
| -------------------------------- | -------- | -----------------: |
| Attendance & payroll             | F01…F12  |                 12 |
| Tyres                            | G01…G14  |                 14 |
| Batteries                        | H01…H08  |                  8 |
| Maintenance                      | I01…I08  |                  8 |
| Compliance depth (EMI, challan)  | J04, J05 |                  2 |
| Monthly fleet expenses           | K01…K05  |                  5 |
| Vehicle document registry (full) | J01, J03 | Partial → Phase 10 |

---

## 8. Phase 11 — Reports & migration

| Cluster                           | Legacy IDs          | Notes                                      |
| --------------------------------- | ------------------- | ------------------------------------------ |
| Broker `ReportController` exports | BRK-R01…R21         | List/register substitutes Partial today    |
| **Reclassified from Missing**     | BRK-R19, R20        | Transporter export; voucher receipt export |
| OwnFleet trip/fleet reports       | OF-L01…L08, K02…K05 |                                            |
| Tyre/maintenance reports          | OF-G09…G14, I05…I08 |                                            |
| Fleet dashboard stats             | OF-L08, D05, J06    |                                            |
| Legacy ETL                        | Phases §11          | After workflow certification               |

---

## 9. Intentional — do not map to backlog

| IDs                                         | Topic                                                                                            |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| BRK-M01,M02,M08,M11,M20; BRK-LR07; BRK-FH06 | Country/state masters, truck owner table, linked-at-create LR, confirm step, legacy subscription |
| OF-A06,A07,B01,B13,B18,E08                  | Standalone trips, Driver table, archive/restore, vehicle swap, payment destroy                   |
| BRK-P02, SHR-01                             | Multi-app chooser                                                                                |
| OF-M02,M08                                  | Legacy subscription, float math                                                                  |

---

## 10. PO Decision before mapping

| ID                  | Question                                                  | Default recommendation                                                              |
| ------------------- | --------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| BRK-M16             | Platform-wide transporter master registry (cross-company) | Defer unless Internal admin scope approved                                          |
| BRK-IN06            | Custom manual broker invoice                              | **Option A:** strict LR-linked only (Intentional) · **Option B:** port manual lines |
| OF-A11, F08         | Driver advance ledger, payroll settings                   | Defer Phase 10 / out of PRD §11–12                                                  |
| NX-45 / AUD-CON-006 | Entitlement trial/grace cells                             | PO matrix — no engineering invent                                                   |

---

## Summary table

| Phase       | Close-now rows | Deferred rows (indicative) |
| ----------- | -------------: | -------------------------: |
| 3           |              5 |                          0 |
| 5           |              8 |                          0 |
| 6           |              9 |                          0 |
| 7           |              0 |               All Partload |
| 8           |              0 |                        14+ |
| 9           |              0 |                         4+ |
| 10          |              0 |                        57+ |
| 11          |              0 |                        80+ |
| Intentional |              — |                         18 |
| PO          |              4 |                          — |

---

_Engineering queue: `docs/engineering/PHASE_1_6_CONSOLIDATED_GAP_REGISTER.md`._
