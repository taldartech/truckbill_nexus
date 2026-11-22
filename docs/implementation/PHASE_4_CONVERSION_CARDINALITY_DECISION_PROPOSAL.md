# Phase 4 — Conversion Cardinality Decision Proposal

**Status:** **PENDING** — Product Owner decision required (`PO-P4-CNV-CARDINALITY`)  
**Date:** 23 November 2026  
**Authority:** PRD §9; Rules §7; Phases.md Phase 4–7  
**Constraint:** Do **not** implement conversion behaviour that assumes an option below until approved.

---

## 1. Problem

An enquiry has exactly one vehicle **type** and a **quantity** N ≥ 1 (“all requested vehicles must be arranged”). Conversion must create Unified LR record(s) only (PO-P4-CONVERT Option A). The Product Owner has **not** approved whether that yields 1 LR or N LRs, nor how goods/weight/freight/charges/EWBs are allocated.

---

## 2. Options

| Option                | LR count                                                                 | Goods/weight/freight                                                                                  | Notes                                                     |
| --------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| **A — Single LR**     | Always 1                                                                 | Full enquiry goods/weights/freight on one LR; vehicle qty recorded on LR/header                       | Simple; weaker fit for later “one OwnFleet LR = one Trip” |
| **B — N LRs (clone)** | N                                                                        | Each LR clones full goods/weights/freight                                                             | Risks duplicate revenue/goods totals if summed naively    |
| **C — N LRs (split)** | N                                                                        | Explicit allocation of goods lines/weights/freight/charges/EWBs across LRs; sum reconciles to enquiry | Strongest operational fit; most UI/rules complexity       |
| **D — Hybrid**        | Operator chooses 1 or N at conversion with mandatory allocation when N>1 | Same as C when N>1                                                                                    | Flexible; needs clear defaults                            |

---

## 3. Decision dimensions (must be answered)

1. **LR cardinality** — A / B / C / D (or other)
2. **Per-vehicle goods allocation** — clone vs split vs manual
3. **Weight allocation and total reconciliation** — exact sum rules before convert
4. **Freight allocation** — especially with selected quotation totals
5. **Additional-charge allocation**
6. **EWB reference allocation** — shared vs per-LR
7. **Prevention of duplicate goods and revenue** in reports/invoices later
8. **Compatibility** with OwnFleet (1 LR=1 Trip), Broker Lorry Hire, Partload

---

## 4. Engineering recommendation (non-binding)

Prefer **Option C** (or **D** with default C when N>1) for Phase 5–7 compatibility, with mandatory reconciliation: sum of allocated weights/freight across conversion LRs equals enquiry totals; no silent full-clone of freight onto each LR.

Until approved, Batch A keeps conversion schema **cardinality-neutral** (enquiry ↔ 0..N LR links without fixed N).

---

## 5. Explicit non-assumptions until approval

- Not “always one LR”
- Not “always N LRs”
- No automatic goods/weight/freight duplication or allocation in code
