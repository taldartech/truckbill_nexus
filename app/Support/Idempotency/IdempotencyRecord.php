<?php

namespace App\Support\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $scope
 * @property string $idempotency_key
 * @property string $company_id
 * @property string|null $branch_id
 * @property string|null $owner_id
 * @property string|null $request_hash
 * @property string $status
 * @property array<string, mixed>|null $result_payload
 * @property CarbonImmutable|null $locked_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 */
class IdempotencyRecord extends Model
{
    use HasUuids;

    protected $table = 'idempotency_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'scope',
        'idempotency_key',
        'company_id',
        'branch_id',
        'owner_id',
        'request_hash',
        'status',
        'result_payload',
        'locked_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
            'locked_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
