<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Platform City Master — belongs to a State.
 *
 * @property string $id
 * @property string $state_id
 * @property string $name
 * @property string $name_normalized
 * @property bool $is_active
 */
class City extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'state_id',
        'name',
        'name_normalized',
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
        static::creating(function (City $city): void {
            if (! filled($city->getAttribute('id'))) {
                $city->setAttribute('id', (string) Str::uuid());
            }

            if (! filled($city->getAttribute('name_normalized')) && filled($city->getAttribute('name'))) {
                $city->setAttribute('name_normalized', self::normalizeName((string) $city->getAttribute('name')));
            }
        });

        static::updating(function (City $city): void {
            if ($city->isDirty('name')) {
                $city->setAttribute('name_normalized', self::normalizeName((string) $city->getAttribute('name')));
            }
        });
    }

    public static function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
