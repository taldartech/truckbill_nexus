# Private File Storage Foundation (WS-5 / ADR-008)

**Status:** Implemented in Phase 1 (remediated)  
**Authority:** ADR-008 Accepted (PO-P1-ADR-008)  
**Related requirements:** TB-DOC-001, TB-DOC-002 (quota/retention counting prepared; full quota Phase 3)

## Configuration

| Item         | Value                                                           |
| ------------ | --------------------------------------------------------------- |
| Disk         | `private_files` (`storage/app/private/files`)                   |
| Visibility   | `private`                                                       |
| Public serve | `false`                                                         |
| Max size     | 2 MiB (`config/files.php` → `max_bytes`)                        |
| Scanner      | `FILES_SCANNER` / `files.scanner`: `pass` \| `fake` \| `reject` |

Allowed extensions: `jpg`, `jpeg`, `png`, `webp`, `pdf`, `doc`, `docx`, `xls`, `xlsx` with matching MIME allow-lists.

### Production scanner requirement (critical)

- `pass`, `fake`, and `reject` are **non-production** adapters only.
- Production resolution via `MalwareScannerFactory` **refuses** those drivers and refuses empty/missing/unknown drivers.
- No approved production malware engine is installed yet. Installing one requires separate `PO-P1-DEPS` approval.
- Until an approved production driver exists, production private-file operations **fail closed** at scanner resolution (before any file becomes available).
- Local/test defaults may use `FILES_SCANNER=pass`. Never rely on a silent production pass-through.

## Schema (`stored_files`)

| Column                                                                     | Purpose                                                 |
| -------------------------------------------------------------------------- | ------------------------------------------------------- |
| `id` (uuid)                                                                | Stable file identity (also used in object path)         |
| `disk` / `path`                                                            | Private object location                                 |
| `original_name`, `extension`, `mime_type`, `size_bytes`, `checksum_sha256` | Validated upload metadata                               |
| `company_id`, `branch_id`, `owner_id`                                      | Tenant metadata from `TenantStorageMetadata`            |
| `scan_status`                                                              | `pending_scan` → `scanning` → `available` \| `rejected` |
| `scan_message`, `scanned_at`, `available_at`                               | Scan outcome / sanitized diagnostics                    |
| `replaces_file_id`, `replaced_at`, `pending_cleanup`                       | Safe replacement lifecycle + retryable cleanup          |

## Lifecycle

1. Authenticated actor + non-empty tenant context required.
2. Resolve malware scanner (fail closed if unsafe/unconfigured).
3. Validate size, extension, and MIME (detected + client-declared).
4. Store object on the private disk only.
5. Persist metadata as `pending_scan`. If metadata persistence fails, attempt object cleanup and rethrow (no silent untracked object).
6. Transition to `scanning`, invoke `MalwareScanner`.
7. On clean → `available` (+ `available_at`); downloadable only in this state.
8. On unclean result → `rejected`, object deleted, not downloadable.
9. On scanner **exception** → mark `rejected` (never leave indefinitely `scanning`), sanitize diagnostic message, delete object, rethrow fail-closed. Cleanup failure after reject still leaves the file non-downloadable.

Upload success alone never grants downloadability.

## Authorization (supported vs blocked)

Uses existing `TenantContextHolder` + `TenantStorageMetadata::assertSameTenant()` (WS-3).

### Supported (Phase 1)

| Case                                                                                   | Result |
| -------------------------------------------------------------------------------------- | ------ |
| Same company + matching branch                                                         | Allow  |
| Same company + Owner-scope + matching `owner_id` (branch-scoped file)                  | Allow  |
| Same company + company-scoped file (`branch_id` null) with owner constraints satisfied | Allow  |
| Authenticated download of `available` file under matching tenant                       | Allow  |

### Denied / fail closed

| Case                                                                | Result                |
| ------------------------------------------------------------------- | --------------------- |
| Missing tenant context                                              | Deny                  |
| Unauthenticated actor                                               | Deny                  |
| Cross-company                                                       | Deny                  |
| Cross-branch without Owner-scope                                    | Deny                  |
| Owner-scope without `owner_id`                                      | Deny                  |
| Owner-scope with mismatched `owner_id`                              | Deny                  |
| Internal Team (`is_internal`) without matching branch / Owner-scope | Deny (**Pending PO**) |
| Multi-company Owner semantics beyond same-company Owner-scope       | Deny (**Pending PO**) |
| `pending_scan`, `scanning`, `rejected`, or missing object           | Not downloadable      |
| Public disk / public serve path                                     | Not used              |

Pending Product Owner decisions must not be implemented by assumption. Do not grant broader Internal Team privileges or infer cross-company Owner permissions.

## Safe replacement (post-commit cleanup)

1. Validate and store the replacement privately.
2. Complete scan; replacement must become `available`.
3. In a DB transaction only: link `replaces_file_id`, mark old file `rejected` + `pending_cleanup` + clear `available_at`.
4. **Commit** the transaction.
5. **After commit**, delete the old filesystem object, then delete old metadata.
6. If filesystem deletion fails after commit: keep `pending_cleanup`, retain `path`, keep non-downloadable; cleanup is retryable via `cleanupReplaced()`.
7. If the DB transaction fails: original remains intact and downloadable; unused replacement is discarded via the same retryable cleanup rules below.
8. If replacement scan fails/throws: original remains downloadable; rejected replacement cleanup follows the same rules.

### Discard / rejected-upload cleanup (no orphan objects)

Unused or rejected uploads are discarded only through retryable cleanup:

1. Mark the artefact `rejected`, non-downloadable, and `pending_cleanup = true` (path retained).
2. Attempt filesystem object deletion.
3. **Only if deletion succeeds**, delete metadata.
4. If deletion fails: keep metadata + path + `pending_cleanup`, record a sanitized diagnostic, and surface the failure to the caller.
5. Retry later with `cleanupReplaced()` under the same tenant authorization rules.

Never delete metadata while a private object may still exist. A cleanup failure for a replacement artefact must not make the original unavailable.

No outbox/queue is used for cleanup in WS-5 (WS-6 owns that foundation). Cleanup is synchronous with retryable `pending_cleanup` state.

## Scanner interface

```php
interface MalwareScanner
{
    /** @return array{clean: bool, message: string} */
    public function scan(string $absolutePath, string $originalName): array;
}
```

| Driver   | Behavior                                                  |
| -------- | --------------------------------------------------------- |
| `pass`   | Always clean (local/dev only; refused in production)      |
| `fake`   | Rejects when name/content contains `MALWARE` (tests only) |
| `reject` | Always unclean (negative tests only)                      |

Resolved by `MalwareScannerFactory` (production-aware).

## Service API

`App\Support\Files\PrivateFileService`:

- `store(UploadedFile): StoredFile`
- `replace(StoredFile, UploadedFile): StoredFile`
- `download(StoredFile): StreamedResponse`
- `assertCanAccess(StoredFile): void`
- `cleanupReplaced(StoredFile): void` (retryable when `pending_cleanup` is true)

No product document UI or HTTP upload routes in WS-5 — foundation service + Pest suite only.

## Tests

- `tests/Feature/FileSecurityTest.php` — validation, scan lifecycle, tenant denial, public-serve absence, replacement preserve/cleanup, storage-write failure, Internal Team fail-closed.
- `tests/Feature/FileSecurityRemediationTest.php` — production scanner refusal, scanner exceptions, metadata/object cleanup, post-commit cleanup failure + retry, tenant-safe cleanup.
