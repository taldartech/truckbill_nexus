<?php

namespace App\Support\Audit\Harness;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 1 foundation harness model for audit atomicity tests.
 * Not a product business entity.
 *
 * @property int $id
 * @property string $name
 * @property string $status
 * @property string|null $notes
 * @property string|null $secret_token
 */
class AuditHarnessRecord extends Model
{
    protected $table = 'audit_harness_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
        'notes',
        'secret_token',
    ];
}
