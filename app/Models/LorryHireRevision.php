<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Immutable record of one Lorry Hire edit: before/after financial snapshot and the edit remark.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $lorry_hire_id
 * @property string $remark
 * @property array<string, mixed> $before
 * @property array<string, mixed> $after
 * @property string $freight_delta
 * @property string $tds_delta
 * @property int|null $created_by_user_id
 * @property-read User|null $createdBy
 */
class LorryHireRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'lorry_hire_id', 'remark',
        'before', 'after', 'freight_delta', 'tds_delta', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'freight_delta' => 'decimal:2',
            'tds_delta' => 'decimal:2',
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

    /** @return BelongsTo<LorryHire, $this> */
    public function lorryHire(): BelongsTo
    {
        return $this->belongsTo(LorryHire::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
