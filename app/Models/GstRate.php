<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\GstRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $rate_percent
 * @property CarbonInterface $effective_from
 * @property CarbonInterface|null $effective_to
 * @property int $version
 * @property bool $is_active
 */
class GstRate extends Model
{
    /** @use HasFactory<GstRateFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rate_percent',
        'effective_from',
        'effective_to',
        'version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:3',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'is_active' => 'boolean',
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
     * Snapshot-safe payload for future transactions (immutable reference).
     * Historical invoice JSON may still contain legacy code/name keys — do not rewrite those.
     *
     * @return array{gst_rate_id: string, rate_percent: string, version: int, effective_from: string}
     */
    public function toSnapshot(): array
    {
        return [
            'gst_rate_id' => $this->id,
            'rate_percent' => (string) $this->rate_percent,
            'version' => (int) $this->version,
            'effective_from' => $this->effective_from->toDateString(),
        ];
    }

    public function displayLabel(): string
    {
        $value = rtrim(rtrim((string) $this->rate_percent, '0'), '.');
        if ($value === '' || $value === '-') {
            $value = '0';
        }

        return $value.'%';
    }
}
