<?php

namespace App\Support\Outbox\Harness;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_id
 * @property string $company_id
 * @property string $effect_key
 * @property int $apply_count
 */
class OutboxHarnessEffect extends Model
{
    use HasUuids;

    protected $table = 'outbox_harness_effects';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'company_id',
        'effect_key',
        'apply_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'apply_count' => 'integer',
        ];
    }
}
