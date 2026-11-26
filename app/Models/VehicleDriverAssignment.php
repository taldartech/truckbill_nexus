<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $assigned_from
 * @property Carbon|null $assigned_to
 * @property bool $is_primary
 */
class VehicleDriverAssignment extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'vehicle_id',
        'driver_party_id',
        'assigned_from',
        'assigned_to',
        'is_primary',
        'remarks',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'assigned_from' => 'date',
            'assigned_to' => 'date',
            'is_primary' => 'boolean',
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

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'driver_party_id');
    }

    /**
     * @param  Builder<VehicleDriverAssignment>  $query
     */
    public function scopeOverlappingPrimaryPeriod(
        Builder $query,
        string $periodFrom,
        ?string $periodTo = null,
    ): void {
        $query->where('is_primary', true)
            ->whereNull('deleted_at')
            ->where(function (Builder $overlap) use ($periodFrom, $periodTo): void {
                if ($periodTo !== null) {
                    $overlap->whereDate('assigned_from', '<=', $periodTo);
                }

                $overlap->where(function (Builder $endBound) use ($periodFrom): void {
                    $endBound->whereNull('assigned_to')
                        ->orWhereDate('assigned_to', '>=', $periodFrom);
                });
            });
    }

    /**
     * @param  Builder<VehicleDriverAssignment>  $query
     */
    public function scopeActivePrimaryOn(Builder $query, ?string $asOfDate = null): void
    {
        $asOf = $asOfDate ?? Carbon::today()->toDateString();

        $query->where('is_primary', true)
            ->whereNull('deleted_at')
            ->whereDate('assigned_from', '<=', $asOf)
            ->where(function (Builder $dateQuery) use ($asOf): void {
                $dateQuery->whereNull('assigned_to')
                    ->orWhereDate('assigned_to', '>', $asOf);
            });
    }

    public function statusOn(string $asOfDate): string
    {
        $from = $this->assigned_from->toDateString();
        $to = $this->assigned_to?->toDateString();

        if ($from > $asOfDate) {
            return 'scheduled';
        }

        if ($to !== null && $to <= $asOfDate) {
            return 'ended';
        }

        return 'active';
    }
}
