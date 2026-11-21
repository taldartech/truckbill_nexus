# Private File Storage Foundation (WS-5 / ADR-008)

**Status:** Implemented in Phase 1  
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

No real external malware product is installed. Production scanner clients require separate `PO-P1-DEPS` approval.

## Schema (`stored_files`)

| Column                                                                     | Purpose                                                 |
| -------------------------------------------------------------------------- | ------------------------------------------------------- |
| `id` (uuid)                                                                | Stable file identity (also used in object path)         |
| `disk` / `path`                                                            | Private object location                                 |
| `original_name`, `extension`, `mime_type`, `size_bytes`, `checksum_sha256` | Validated upload metadata                               |
| `company_id`, `branch_id`, `owner_id`                                      | Tenant metadata from `TenantStorageMetadata`            |
| `scan_status`                                                              | `pending_scan` → `scanning` → `available` \| `rejected` |
| `scan_message`, `scanned_at`, `available_at`                               | Scan outcome                                            |
| `replaces_file_id`, `replaced_at`, `pending_cleanup`                       | Safe replacement lifecycle                              |

## Lifecycle

1. Authenticated actor + non-empty tenant context required.
2. Validate size, extension, and MIME (detected + client-declared).
3. Store object on the private disk only.
4. Persist metadata as `pending_scan`.
5. Transition to `scanning`, invoke `MalwareScanner`.
6. On clean → `available` (+ `available_at`); downloadable only in this state.
7. On unclean / scanner failure semantics → `rejected`, object deleted, not downloadable (fail closed).

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

## Safe replacement

1. Validate and store the replacement privately.
2. Complete scan; replacement must become `available`.
3. Link `replaces_file_id`, mark old file for cleanup.
4. Delete old object + metadata only after replacement is available.
5. If replacement scan fails: delete replacement artefact; **original remains downloadable**.

## Scanner interface

```php
interface MalwareScanner
{
    /** @return array{clean: bool, message: string} */
    public function scan(string $absolutePath, string $originalName): array;
}
```

| Driver   | Behavior                                             |
| -------- | ---------------------------------------------------- |
| `pass`   | Always clean (local/dev default)                     |
| `fake`   | Rejects when name/content contains `MALWARE` (tests) |
| `reject` | Always unclean (negative tests)                      |

## Service API

`App\Support\Files\PrivateFileService`:

- `store(UploadedFile): StoredFile`
- `replace(StoredFile, UploadedFile): StoredFile`
- `download(StoredFile): StreamedResponse`
- `assertCanAccess(StoredFile): void`
- `cleanupReplaced(StoredFile): void`

No product document UI or HTTP upload routes in WS-5 — foundation service + Pest suite only.

## Tests

`tests/Feature/FileSecurityTest.php` — validation, scan lifecycle, tenant denial, public-serve absence, replacement preserve/cleanup, storage-write failure, Internal Team fail-closed.
