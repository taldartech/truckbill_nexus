<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $company_subscription_id
 * @property string $change_type
 * @property string $status
 * @property CarbonInterface|null $effective_on
 * @property string|null $catalogue_version_id
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $pricing_breakdown
 * @property string|null $remark
 * @property int|null $requested_by
 */
class SubscriptionChangeProposal extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'company_subscription_id',
        'change_type',
        'status',
        'effective_on',
        'catalogue_version_id',
        'payload',
        'pricing_breakdown',
        'remark',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'payload' => 'array',
            'pricing_breakdown' => 'array',
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
}
