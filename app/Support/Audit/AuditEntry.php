<?php

namespace App\Support\Audit;

use App\Support\Audit\Exceptions\AuditImmutabilityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Append-only audit entry (ADR-003). No ordinary update or delete.
 *
 * @property int $id
 * @property string $uuid
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $company_id
 * @property string|null $branch_id
 * @property string|null $owner_id
 * @property bool $is_internal
 * @property bool $is_owner_scope
 * @property string $subject_type
 * @property string $subject_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $remark
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancellation_at
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    public $timestamps = false;

    protected $table = 'audit_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new AuditImmutabilityException('Audit entries cannot be updated.');
        });

        static::deleting(function (): void {
            throw new AuditImmutabilityException('Audit entries cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'is_internal' => 'bool',
            'is_owner_scope' => 'bool',
            'cancellation_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Controlled insert path used only by AuditWriter.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function insertAppendOnly(array $attributes): self
    {
        $entry = new self;
        $entry->forceFill([
            ...$attributes,
            'uuid' => $attributes['uuid'] ?? (string) Str::uuid(),
            'created_at' => $attributes['created_at'] ?? now(),
        ]);
        $entry->save();

        return $entry;
    }
}
