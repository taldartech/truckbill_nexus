<?php

namespace App\Models;

use App\Support\Files\StoredFile;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $expires_on
 */
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'parent_type',
        'parent_id',
        'stored_file_id',
        'document_type',
        'label',
        'document_number',
        'issued_on',
        'expires_on',
        'version',
        'supersedes_attachment_id',
        'counts_toward_storage',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'issued_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'counts_toward_storage' => 'boolean',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<DocumentAttachment, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_attachment_id');
    }
}
