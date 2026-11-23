# Company Creation Accessibility & CI Remediation

**Date:** 23 November 2026  
**Authority:** PO-P1-P5-FULLSTACK-AUTH  
**Initial HEAD:** `1662c181af5b25cda043f06c4d2ffddb99008057`  
**Verdict:** **COMPANY CREATION ACCESSIBLE & CI REMEDIATION LOCALLY CERTIFIED**

---

## A. Initial / final HEAD

|                                       | SHA                                        |
| ------------------------------------- | ------------------------------------------ |
| Initial (Remote / local tip at start) | `1662c181af5b25cda043f06c4d2ffddb99008057` |
| Final                                 | _37c4444c858836f8901dda9e878784c1208bab34_ |

## B. Exact cause of missing Create Company navigation

Backend route `GET /owner/companies/create` and Inertia page `owner/companies/create` already existed. Customer navigation did not surface them:

1. **Owner Dashboard** — no Create Company CTA; stale copy claimed profile fields were deferred.
2. **Company Switcher** — returned `null` when the company list was empty; no Create New Company item.
3. **No Access** — controller already passed `canCreateCompany` + `links.createCompany`, but the page rendered Sign Out only.
4. **Select Company** — Create Company was already present; verified unchanged.

## C. UI files corrected

- `resources/js/pages/owner/dashboard.tsx`
- `resources/js/components/company-switcher.tsx`
- `resources/js/pages/workspace/no-access.tsx`
- `resources/js/pages/owner/companies/create.tsx` (digital-asset honesty note)
- `resources/js/types/workspace.ts`
- Shared props: `HandleInertiaRequests` (`can_create_company`, `create_company_url`)
- `OwnerDashboardController` (`links.create_company`)

## D. Company flow verification

Pest + Playwright covered create for existing owners, zero-company customers, switcher entry, and Internal 403. Domain policies preserved (Owner designation, optional branch, first-company trial, no partial create on invalid submit).

## E. PRD §7 field completeness

| Area                                                | Status                                                                                                               |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Legal / contact / address / GST / visibility / bank | Present on Create + Settings                                                                                         |
| Logo / signature columns + PrivateFileService       | Schema/service ready                                                                                                 |
| Logo / signature upload UI                          | **Remaining** — documented on create form and Settings deferred section (tenant-scoped upload after company context) |

Do not claim full PRD §7 UI completion while logo/signature upload controls are absent.

## F. GitHub Actions #72

- **Failing step:** Frontend check
- **Error:** Formatting issues found
- **Files:** `tests/performance/k6/{README.md,baseline.js,soak.js,spike.js,stress.js}`
- **Correction:** `npx vp check --fix tests/performance/k6` (formatter config unchanged; checks not weakened)

## G. Pest results

- `CompanyCreationAccessibilityTest` — 11 passed
- Full suite — **475 passed**, 2 skipped (`php artisan test --compact`)

## H. Playwright results

```
E2E_REQUIRE_COMPANY_CREATE=1 E2E_PASSWORD=password PLAYWRIGHT_USE_SYSTEM_CHROME=1
npx playwright test tests/e2e/company/create-company.spec.ts
→ 4 passed (Scenarios A–D)
```

Requires `php artisan e2e:seed-company-create` beforehand.

## I. Full CI-equivalent validation

| Check                                            | Result            |
| ------------------------------------------------ | ----------------- |
| `composer validate`                              | passed            |
| `composer check-platform-reqs`                   | passed            |
| `php artisan wayfinder:generate --with-form`     | passed            |
| `npm run check`                                  | passed            |
| `npm run types:check`                            | passed            |
| `npm run build`                                  | passed            |
| `vendor/bin/pint --test`                         | passed            |
| `vendor/bin/phpstan analyse --memory-limit=512M` | passed (0 errors) |
| `php artisan test --compact`                     | passed            |

## J. Security review

- Internal identities receive **403** on Customer company create (Pest + Playwright).
- Cross-company Owner Panel access remains forbidden.
- Create Company shared only on active Customer workspace share (Internal gets `workspace: null`).
- No public self-registration; no hard deletion; no live Razorpay; no push/deploy.

## K. Local commits

Focused local commits only; **no git push**.

## L. Remaining defects

1. Logo/signature **upload UI** still follow-on (schema ready).
2. Company Switcher dropdown with many companies can push Create New Company below the fold (Playwright navigates via exposed href after proving the menu item exists).
3. Continue Phase 1–5 / CRM conversion UI and later slices per checkpoint.
