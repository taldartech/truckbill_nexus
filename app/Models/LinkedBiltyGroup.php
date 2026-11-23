<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $group_date
 */
class LinkedBiltyGroup extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'branch_id', 'group_number', 'group_date', 'vehicle_id', 'payee_party_id',
        'driver_name', 'reference', 'status', 'total_amount', 'remarks', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'group_date' => 'date',
            'total_amount' => 'decimal:2',
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

    /** @return HasMany<LinkedBiltyGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(LinkedBiltyGroupMember::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'payee_party_id');
    }
}
