# Portal-Wide UX Defect Register

**Date:** 24 November 2026  
**Baseline:** `fef49e6` → remediation tip tracked in commits after GA #88  
**Authority:** Product Owner portal UX remediation directive (24 Nov 2026)

| ID     | Portal | Screen / component    | Defect                                            | Severity | Status | Evidence                                                      |
| ------ | ------ | --------------------- | ------------------------------------------------- | -------- | ------ | ------------------------------------------------------------- |
| UX-001 | Owner  | Companies index       | Context actions all default outline (same colour) | High     | Fixed  | `WorkspaceContextAction` variant + companies semantic colours |
| UX-002 | Owner  | Global Users          | Text “Manage” link instead of icon action         | High     | Fixed  | `AppIconAction` Settings2 info                                |
| UX-003 | Owner  | Global Audit          | Text “Company audit” link                         | High     | Fixed  | `AppIconAction` Building2 info                                |
| UX-004 | Shared | `ResponsiveDataTable` | Expand control when no hidden content at viewport | Critical | Fixed  | CSS expand visibility by max priority                         |
| UX-005 | Branch | Supporting Catalogues | Six masters in one type dropdown                  | High     | Fixed  | `51b5fc5`                                                     |
| UX-006 | Branch | Partload bilty form   | Read-only Workflow field                          | High     | Fixed  | `51b5fc5`                                                     |
| UX-007 | Branch | Nav                   | Separate OwnFleet / Broker invoice sections       | High     | Fixed  | Batch D commit TBD                                            |
| UX-008 | Branch | `/payments`           | OwnFleet-only payment surface naming              | Medium   | Fixed  | Batch D commit TBD                                            |
| UX-009 | All    | Tables / lists        | Text-only actions on remaining screens            | High     | Open   | Batches E–H                                                   |
| UX-010 | All    | Layout                | Excessive empty space / sparse forms              | Medium   | Open   | Batches E–H                                                   |
| UX-011 | All    | Copy                  | Technical workflow/module language in UI          | Medium   | Fixed  | `51b5fc5` (partial — Batches E–H for remaining screens)       |
| UX-012 | CI     | MySQL migrate         | Composite payee FK SET NULL                       | Critical | Fixed  | GA #88 remediation `1dae925`                                  |

Screenshot and Playwright columns are filled in `PORTAL_PLAYWRIGHT_VISUAL_CERTIFICATION.md` as browser evidence is captured.
