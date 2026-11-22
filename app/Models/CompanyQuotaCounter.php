<?php

namespace App\Models;

use Database\Factories\CompanyQuotaCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CompanyQuotaCounter extends Model
{
    /** @use HasFactory<CompanyQuotaCounterFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'quota_type',
        'period_start',
        'period_end',
        'included_units',
        'used_units',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'included_units' => 'decimal:4',
            'used_units' => 'decimal:4',
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
}
