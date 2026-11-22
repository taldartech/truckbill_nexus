<?php

namespace App\Models;

use Database\Factories\DocumentNumberAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DocumentNumberAdjustment extends Model
{
    /** @use HasFactory<DocumentNumberAdjustmentFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'sequence_id', 'previous_next_value', 'new_next_value',
        'actor_user_id', 'remark', 'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_next_value' => 'integer',
            'new_next_value' => 'integer',
            'adjusted_at' => 'immutable_datetime',
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

    /** @return BelongsTo<DocumentNumberSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(DocumentNumberSequence::class, 'sequence_id');
    }
}
