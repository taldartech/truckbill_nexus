<?php

namespace App\Models;

use App\Domains\Numbering\Enums\DocumentNumberAllocationMode;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use Database\Factories\DocumentNumberAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DocumentNumberAllocation extends Model
{
    /** @use HasFactory<DocumentNumberAllocationFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'document_type', 'scope_type', 'branch_id', 'number',
        'allocation_mode', 'allocated_to_id', 'allocated_to_type',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentNumberType::class,
            'scope_type' => DocumentNumberScopeType::class,
            'allocation_mode' => DocumentNumberAllocationMode::class,
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

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
