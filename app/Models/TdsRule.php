<?php

namespace App\Models;

use Database\Factories\TdsRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $priority
 * @property bool $is_active
 * @property string $condition_logic
 * @property list<array{field: string, operator: string, value: mixed}>|null $conditions
 * @property string $rate_percent
 * @property string|null $description
 */
class TdsRule extends Model
{
    /** @use HasFactory<TdsRuleFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'name',
        'priority',
        'is_active',
        'condition_logic',
        'conditions',
        'rate_percent',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'conditions' => 'array',
            'rate_percent' => 'decimal:3',
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
     * @return array{tds_rule_id: string, code: string, name: string, rate_percent: string, priority: int}
     */
    public function toSnapshot(): array
    {
        return [
            'tds_rule_id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'rate_percent' => (string) $this->rate_percent,
            'priority' => (int) $this->priority,
        ];
    }
}
