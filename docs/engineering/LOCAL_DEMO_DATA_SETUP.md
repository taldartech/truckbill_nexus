# Local Demo Workspace Data Setup

**Authoritative demo seeder (only one):** `Database\Seeders\LocalDemoWorkspaceSeeder`

`LocalDemoUserSeeder` has been **removed**. Do not add module-specific demo seeders (`CrmDemoSeeder`, `OwnFleetDemoSeeder`, etc.). Every authorized feature batch must extend this seeder with valid fixtures in the same delivery.

**Command:**

```bash
LOCAL_DEMO_PASSWORD='your-local-secret' \
LOCAL_DEMO_PROFILE=standard \
php artisan db:seed --class=LocalDemoWorkspaceSeeder
```

**Safety:** Runs only in `local` or `testing`. Refuses production. **Never** called by `DatabaseSeeder`. Does not call Razorpay, send mail/SMS, or print passwords. Idempotent and deterministic.

---

## Prerequisites

1. PostgreSQL configured (see `.env.example`).
2. Migrations applied (`php artisan migrate`).
3. `LOCAL_DEMO_PASSWORD` set in the shell (or exported). Do not commit the value.
4. Optional `LOCAL_DEMO_PROFILE`: `small` | `standard` (default) | `large`.

---

## What is seeded

| Area         | Content                                                                                                                                                                        |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Foundations  | `RbacSeeder`, `SubscriptionCatalogueSeeder`                                                                                                                                    |
| Demo roles   | `demo.enquiry_manager`, `demo.enquiry_operator`, `demo.enquiry_viewer` (enquiry.* + platform ops; **no** delete / LR number adjust; **no** blanket grants on `customer.admin`) |
| Company A    | `Demo Logistics Alpha` — Mumbai HO + Pune Branch                                                                                                                               |
| Company B    | `Demo Freight Beta` — Ahmedabad HO + Surat Branch (tenant isolation)                                                                                                           |
| Users        | Owners, manager/operator/viewer/restricted (A), no-access user                                                                                                                 |
| Masters      | Parties (incl. customer/transporter), vehicles, bank/cash, owner stations/catalogues                                                                                           |
| Subscription | Trial (from company create) + draft contract where catalogue plans exist                                                                                                       |
| Enquiries    | Manual branch enquiries + website unassigned enquiry with durable external reference                                                                                           |

---

## Demo personas (emails)

Password = value of `LOCAL_DEMO_PASSWORD` (same for all demo users).

| Email                        | Purpose                                        |
| ---------------------------- | ---------------------------------------------- |
| `owner.alpha@demo.test`      | Company A Owner                                |
| `manager.alpha@demo.test`    | Branch manager with enquiry create/edit/assign |
| `operator.alpha@demo.test`   | Enquiry create/view                            |
| `viewer.alpha@demo.test`     | Enquiry view                                   |
| `restricted.alpha@demo.test` | Viewer–No Freight default role                 |
| `noaccess.demo@demo.test`    | Customer with no company membership            |
| `owner.beta@demo.test`       | Company B Owner                                |

---

## Idempotency

Re-running the seeder updates demo user passwords to the current `LOCAL_DEMO_PASSWORD` and skips creating duplicate companies, parties, catalogues, and enquiries (stable emails / fixture markers / external references).

---

## Example scenarios

1. Log in as `owner.alpha@demo.test` → Owner Panel → Branches / Users / Subscription.
2. Enter Mumbai HO → Masters → Parties / Vehicles.
3. Enquiry **API** (UI gated until `PO-P4-DESIGN-FORMAL`):

```bash
# After authenticating in browser/session, or via feature tests:
# GET/POST branch.enquiries.* JSON routes
```

4. Confirm Company B owner cannot see Company A parties/enquiries.
5. Website unassigned enquiry exists with `branch_id` null and an `enquiry_external_references` row.

---

## Deferred / excluded

- Phase 4 Enquiry **product UI** until design formal approval.
- Assignment, quotations, LR, conversion fixtures.
- `company_record` document UI.
- Production Razorpay charges, legally operative invoices, accounting postings.
- Document file binaries (upload via UI against masters when needed).

---

## Verification tests

```bash
php artisan test --compact tests/Feature/LocalDemoWorkspaceSeederTest.php
```

Covers production refusal, password requirement, isolation, idempotency, no mail/HTTP outbound, and no default-role enquiry blanket grants.

## Relation to default `DatabaseSeeder`

`DatabaseSeeder` seeds RBAC, geography, payment modes, GST rates, TDS rules, subscription catalogue, and the initial Internal Super Admin only. It never calls this demo seeder. Run demo seeding explicitly as shown above after `migrate --seed` when local demo personas are required.
