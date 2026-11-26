<?php

namespace App\Models;

use App\Domains\OwnFleet\Enums\DriverAttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $attendance_date
 * @property DriverAttendanceStatus $status
 */
class VehicleDriverAttendance extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'vehicle_id',
        'attendance_date',
        'primary_driver_party_id',
        'status',
        'temporary_driver_party_id',
        'remarks',
        'marked_via',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => DriverAttendanceStatus::class,
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
    public function primaryDriver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'primary_driver_party_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function temporaryDriver(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'temporary_driver_party_id');
    }
}
