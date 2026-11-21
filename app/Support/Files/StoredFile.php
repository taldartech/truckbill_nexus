<?php

namespace App\Support\Files;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Private stored file metadata (ADR-008).
 *
 * @property string $id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property string $company_id
 * @property string|null $branch_id
 * @property string|null $owner_id
 * @property string $scan_status
 * @property string|null $scan_message
 * @property CarbonImmutable|null $scanned_at
 * @property CarbonImmutable|null $available_at
 * @property string|null $replaces_file_id
 * @property CarbonImmutable|null $replaced_at
 * @property bool $pending_cleanup
 */
class StoredFile extends Model
{
    use HasUuids;

    protected $table = 'stored_files';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'disk',
        'path',
        'original_name',
        'extension',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'company_id',
        'branch_id',
        'owner_id',
        'scan_status',
        'scan_message',
        'scanned_at',
        'available_at',
        'replaces_file_id',
        'replaced_at',
        'pending_cleanup',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'pending_cleanup' => 'bool',
            'scanned_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
        ];
    }

    public function scanStatus(): FileScanStatus
    {
        return FileScanStatus::from($this->scan_status);
    }

    public function isDownloadable(): bool
    {
        return $this->scanStatus()->isDownloadable() && ! $this->pending_cleanup;
    }

    /**
     * @return array{company_id: string, branch_id: ?string, owner_id: ?string}
     */
    public function tenantMetadata(): array
    {
        return [
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'owner_id' => $this->owner_id,
        ];
    }
}
