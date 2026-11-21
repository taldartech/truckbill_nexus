# ADR-012: Cursor Project Rule Versioning

| Field  | Value                          |
| ------ | ------------------------------ |
| ADR ID | ADR-012                        |
| Title  | Cursor Project Rule Versioning |
| Status | Accepted                       |
| Date   | 2026-11-21                     |

## Context

`.cursor/` was fully gitignored, so `truckbill-project.mdc` was local-only (AUD-REPO-012). Team consistency requires the TruckBill always-applied project rule to be version-controlled without tracking private Cursor state.

## Decision (DEC-008)

- Version-control `.cursor/rules/truckbill-project.mdc`.
- Keep other `.cursor/` contents ignored (skills local copies, mcp.json, caches, private state).
- `.gitignore` uses selective un-ignore:
    - `.cursor/*`
    - `!.cursor/rules/`
    - `.cursor/rules/*`
    - `!.cursor/rules/truckbill-project.mdc`
- Version-control the shared Cursor project rule. Local commits follow the repository Git Policy: local commits by default after completed implementation or documentation tasks, subject to verification and explicit Product Owner opt-out. Remote pushing and publishing are strictly prohibited; the Product Owner handles all remote publishing.

## Alternatives

1. Commit entire `.cursor/` — rejected (private/local state risk).
2. Keep rule local-only — rejected (DEC-008).
3. Duplicate rule into `AGENTS.md` only — insufficient for alwaysApply Cursor rule semantics.

## Consequences

- Teammates cloning the repo can receive the TruckBill rule once committed.
- Future additional shared rules require explicit gitignore exceptions.

## Security implications

Avoid committing secrets, machine-specific MCP configs or local agent stores under `.cursor/`.

## Related requirements

TB-PLT-002

## Related audit findings

AUD-REPO-012, AUD-CON-007
