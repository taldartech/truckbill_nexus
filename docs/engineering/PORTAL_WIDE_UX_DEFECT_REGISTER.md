# Portal-Wide UX Defect Register

**Date:** 24 November 2026  
**Baseline:** `fef49e6` → remediation tip tracked in commits after GA #88  
**Authority:** Product Owner portal UX remediation directive (24 Nov 2026)

| ID     | Portal | Screen / component    | Defect                                            | Severity | Status  | Evidence                                                      |
| ------ | ------ | --------------------- | ------------------------------------------------- | -------- | ------- | ------------------------------------------------------------- |
| UX-001 | Owner  | Companies index       | Context actions all default outline (same colour) | High     | Fixed   | `WorkspaceContextAction` variant + companies semantic colours |
| UX-002 | Owner  | Global Users          | Text “Manage” link instead of icon action         | High     | Fixed   | `AppIconAction` Settings2 info; `global-users-desktop.png`    |
| UX-003 | Owner  | Global Audit          | Text “Company audit” link                         | High     | Fixed   | `AppIconAction` company audit; `global-audit-desktop.png`     |
| UX-004 | Shared | `ResponsiveDataTable` | Expand control when no hidden content at viewport | Critical | Fixed   | CSS expand visibility by max priority; Playwright desktop     |
| UX-005 | Branch | Supporting Catalogues | Six masters in one type dropdown                  | High     | Fixed   | `51b5fc5`; six routes; `goods-types-branch.png`               |
| UX-006 | Branch | Partload bilty form   | Read-only Workflow field                          | High     | Fixed   | `51b5fc5`; `partload-create-form.png`                         |
| UX-007 | Branch | Nav                   | Separate OwnFleet / Broker invoice sections       | High     | Fixed   | `aea9599`; `unified-invoices-list.png`                        |
| UX-008 | Branch | `/payments`           | OwnFleet-only payment surface naming              | Medium   | Fixed   | Customer Invoice Receipt vs Transporter Voucher Pay           |
| UX-009 | All    | Tables / lists        | Text-only actions on remaining screens            | High     | Partial | E–I prioritized lists Fixed; rare detail CTAs remain text     |
| UX-010 | All    | Layout                | Excessive empty space / sparse forms              | Medium   | Partial | List/filter densified; tall LR/Trip forms deferred            |
| UX-011 | All    | Copy                  | Technical workflow/module language in UI          | Medium   | Fixed   | Partload + invoice source labels use business language        |
| UX-012 | CI     | MySQL migrate         | Composite payee FK SET NULL                       | Critical | Fixed   | GA #88 remediation `1dae925`                                  |

Screenshot evidence: `docs/engineering/portal-ux-screenshots/` and `PORTAL_PLAYWRIGHT_VISUAL_CERTIFICATION.md`.
