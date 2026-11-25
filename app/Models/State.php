<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Platform State Master (India + GST state codes).
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $gst_code
 * @property string $country_code
 * @property bool $is_active
 */
class State extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'code',
        'gst_code',
        'country_code',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (State $state): void {
            if (! filled($state->getAttribute('id'))) {
                $state->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
