# Payee Selection Workflow Spec

**Stage:** Lorry Hire creation (financial obligation creation)  
**Status:** Implemented

## Rules

1. Payee / Transporter must be **explicitly selected** before Lorry Hire create.
2. Vehicle Owner may be **suggested** only when visible in the active Branch and eligible (Transporter role).
3. Invisible / invalid owners are never auto-selected and never disclosed beyond a generic validation message.
4. Selector lists only Parties visible via `visibleInBranch` with active Transporter role.
5. TDS / net payable use the confirmed Payee; historical snapshots unchanged after create.
