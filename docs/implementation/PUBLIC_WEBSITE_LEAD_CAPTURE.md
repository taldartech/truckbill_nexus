# Public website → Nexus CRM Lead capture

## Architecture

The marketing site (`website/`) collects enquiry forms in the browser and posts to **`submit.php`** (server-side PHP). That gateway forwards JSON to Nexus:

`POST /api/v1/public/leads`

Authentication: **`X-Api-Key`** matching `WEBSITE_LEAD_API_KEY` (never shipped in browser JavaScript). Optional **`Idempotency-Key`** for retry safety.

This is separate from:

- Operational freight **enquiry webhook** (`POST /webhooks/enquiries`)
- Owner **onboarding** (`/api/v1/public/onboarding/{token}`)

## Server-controlled CRM fields

Public requests cannot set status, assignee, creator, conversion, or company linkage.

| Field      | Value                                 |
| ---------- | ------------------------------------- |
| Status     | `new`                                 |
| Source     | `website`                             |
| Assignment | Unassigned (no invented round-robin)  |
| Geography  | Text `state` / `city` (no Master FKs) |

Detailed website channel (`website_demo`, `website_contact`, …) is stored in `source_channel`. Customer message is stored in `remarks` and as a `website_enquiry` activity (system actor).

## Spam / abuse controls

- API key required
- Per-IP + key rate limit (`public_leads.rate_limit_per_minute`)
- Route throttle
- Field length / prohibited privileged attributes
- Website honeypot + origin guard + form rate limit in `submit.php`
- Idempotency + open-duplicate enquiry append (no silent overwrite of assignment)

## Environment

**Nexus**

- `WEBSITE_LEAD_API_KEY`
- `WEBSITE_LEAD_RATE_LIMIT_PER_MINUTE` (optional)
- `WEBSITE_URL` / `LEAD_ONBOARDING_FRONTEND_URL` (welcome + demo links)

**Website**

- `NEXUS_API_URL` (e.g. `http://127.0.0.1:8000`)
- `WEBSITE_LEAD_API_KEY` (same secret)

## Lead geography

CRM Leads store **text** `state` / `city`. Internal Create/Edit use `StateCityFields` with `valueMode="names"` (Master search + custom text + quick-create). Changing Master names does not rewrite historical Lead text.
