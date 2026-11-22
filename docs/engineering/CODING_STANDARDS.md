# TruckBill Nexus — Coding Standards

**Status:** Mandatory for all implementation, maintenance, refactoring and bug-fix work  
**Cursor enforcement:** `.cursor/rules/truckbill-clean-code.mdc` (`alwaysApply: true`)  
**Authority:** Complements Architecture.md, Rules.md, Design.md and ADRs — does not override governing product decisions

---

## 1. Purpose

Production-quality, maintainable code is a permanent TruckBill Nexus requirement. Agents and humans must apply these standards on every change, not only during dedicated cleanup tasks.

---

## 2. Principles

### 2.1 SOLID

| Principle | Requirement                                                                                                                                                |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **SRP**   | Clear responsibility per class/service/controller/component. Do not mix validation, domain rules, persistence, authorization and presentation in one unit. |
| **OCP**   | Extend approved behaviour via focused interfaces/strategies when extension is genuinely required. Avoid special-case chains across unrelated modules.      |
| **LSP**   | Implementations honour their contracts.                                                                                                                    |
| **ISP**   | Prefer small focused interfaces over fat unrelated contracts.                                                                                              |
| **DIP**   | Depend on domain abstractions at architectural boundaries. Do **not** invent interfaces with no real substitution need.                                    |

### 2.2 DRY

Reuse existing domain services and shared infrastructure for business rules, authorization, tenant resolution, validation, financial calculations, numbering, state transitions, error handling and frontend components.

Do not duplicate the same business rule. Avoid premature abstraction of code that only looks similar but means different things.

### 2.3 KISS

Prefer the simplest design that meets approved requirements. Avoid excessive abstraction, unnecessary inheritance, overengineered patterns, deep nesting, redundant wrappers and framework complexity without demonstrated need.

### 2.4 YAGNI

Do not build speculative futures. Extension seams only where approved architecture requires them. No unused config/interfaces, premature event consumers, unrequested flags, placeholder workflows or general-purpose frameworks for one use case. Honour phase boundaries.

### 2.5 Readability

Meaningful names; small cohesive methods; explicit return types; consistent conventions; minimal hidden side effects; business terminology; comments for non-obvious intent only. Split by responsibility — no arbitrary line-count quotas.

---

## 3. Laravel / PHP

### Controllers

Resolve authorized context → validate (Form Requests / conventions) → call domain/application services → return response. No business calculations, large queries or complex state machines in controllers.

### Domain services

Keep rules inside bounded contexts (Enquiry, Consignment/LR, Masters, Subscription, Payments, Accounting, Documents, Identity/Authorization). Cross-context writes use approved services or events (`MODULAR_MONOLITH_LAYOUT.md`, ADR-001).

### Models

Persistence, relationships, casts and cohesive model behaviour. Not oversized application services. Prevent unsafe mass assignment and cross-tenant association.

### Validation

Server-side always. Centralize reusable rules. Prefer Form Requests / existing conventions.

### Transactions

Explicit transactions for atomic multi-write operations: rollback, audit atomicity, idempotency, locking, safe retries, appropriate isolation.

### Exceptions

- Do **not** use broad `catch (Throwable)` to disguise infrastructure failures as business validation/conflict errors.
- Catch specific expected exceptions (e.g. `UniqueConstraintViolationException` for duplicate races).
- Distinguish unique / check / FK violations from unrelated failures.
- Preserve previous exception cause; do not leak secrets in messages.

### Types

Strict PHP types; enums/DTOs/value objects where they clarify contracts. Avoid deep associative arrays for complex domain payloads. No DTOs that merely wrap a trivial scalar.

### Dependencies

Prefer Laravel and existing packages. **No new Composer/NPM packages without Product Owner approval (PO-P1-DEPS).**

---

## 4. React / TypeScript (when UI is authorized)

- Strict TypeScript; typed props; reusable components; clear responsibilities
- Controlled forms; loading/error states; accessibility
- Server-authoritative business logic — do not reimplement backend calculations in the client
- Follow existing Inertia/design conventions

Avoid unjustified `any`, giant pages, duplicated validation, unnecessary `useEffect` chains, needless prop drilling and unapproved state libraries.

**Phase 4 product UI remains gated by PO-P4-DESIGN-FORMAL.**

---

## 5. PostgreSQL / data

Meaningful names; FKs; tenant-safe relationships; precise numeric money/weights; indexes; constraints for critical invariants; explicit transactions; safe concurrency; no avoidable N+1.

Prefer testable application services over burying rules in DB procedures or opaque model events.

Never blindly edit already-applied migrations when a forward migration is required. Never run destructive migrate/seed against production or shared data-bearing databases.

---

## 6. Mandatory pre-commit review

Before every local commit, review the diff against:

1. SOLID
2. DRY
3. KISS
4. YAGNI
5. Naming / readability
6. Separation of concerns
7. Exception handling
8. Transaction / concurrency safety
9. Tenant isolation
10. Authorization / security
11. Maintainability / testability
12. Compatibility with existing architecture

Remove duplication, dead code and accidental complexity introduced by the current task. Do not perform large unrelated refactors. Do not change approved behaviour for style alone.

---

## 7. Permanent workflow

**Understand → Inspect Existing Code → Design Minimally → Implement → Review Clean Code → Test → Refactor Safely → Document → Format → Validate → Commit → Verify**

Inspect existing implementations before creating new classes or services. Clean Code review is mandatory before commit. Preserve repository formatting/validation order and the absolute **no-push** policy.
