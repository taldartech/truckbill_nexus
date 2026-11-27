<?php

namespace App\Domains\Tax;

use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Tax\Enums\PanStatus;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Models\TdsRule;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centrally managed TDS rule definitions (Rules §6).
 *
 * Not a production statutory-compliance claim — external legal validation Pending.
 */
final class TdsRuleService
{
    /** @var list<string> */
    public const CONDITION_FIELDS = [
        'pan_status',
        'declaration_valid',
        'goods_vehicle_count',
        'pan_fourth_char',
        'entity_type',
    ];

    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes, string $remark = 'Create TDS rule'): TdsRule
    {
        $this->assertPlatformMutate($actor);
        $this->validatePayload($attributes);

        return $this->withPlatformTenant(function () use ($attributes, $remark): TdsRule {
            return $this->auditedTransaction->run(function () use ($attributes, $remark): TdsRule {
                $rule = TdsRule::query()->create([
                    'id' => (string) Str::uuid(),
                    'code' => strtoupper(trim((string) $attributes['code'])),
                    'name' => trim((string) $attributes['name']),
                    'priority' => (int) $attributes['priority'],
                    'is_active' => $attributes['is_active'] ?? true,
                    'condition_logic' => strtoupper((string) ($attributes['condition_logic'] ?? 'AND')),
                    'conditions' => $attributes['conditions'],
                    'rate_percent' => $attributes['rate_percent'],
                    'description' => $attributes['description'] ?? null,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    TdsRule::class,
                    $rule->id,
                    remark: $remark,
                    before: null,
                    after: $rule->toArray(),
                );

                return $rule;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, TdsRule $rule, array $attributes, string $remark): TdsRule
    {
        $this->assertPlatformMutate($actor);

        if (isset($attributes['condition_logic']) || isset($attributes['conditions'])) {
            $this->validatePayload(array_merge($rule->toArray(), $attributes));
        }

        return $this->withPlatformTenant(function () use ($rule, $attributes, $remark): TdsRule {
            return $this->auditedTransaction->run(function () use ($rule, $attributes, $remark): TdsRule {
                $before = $rule->toArray();
                $rule->fill(collect($attributes)->only([
                    'name',
                    'priority',
                    'is_active',
                    'condition_logic',
                    'conditions',
                    'rate_percent',
                    'description',
                ])->all());

                if ($rule->isDirty('condition_logic')) {
                    $rule->condition_logic = strtoupper((string) $rule->condition_logic);
                }

                $rule->save();

                $this->audit->write(
                    AuditAction::Updated,
                    TdsRule::class,
                    $rule->id,
                    remark: $remark,
                    before: $before,
                    after: $rule->fresh()?->toArray(),
                );

                return $rule->fresh() ?? $rule;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validatePayload(array $attributes): void
    {
        $logic = strtoupper((string) ($attributes['condition_logic'] ?? 'AND'));
        if (! in_array($logic, ['AND', 'OR'], true)) {
            throw ValidationException::withMessages(['condition_logic' => 'condition_logic must be AND or OR.']);
        }

        $conditions = $attributes['conditions'] ?? null;
        if (! is_array($conditions) || $conditions === []) {
            throw ValidationException::withMessages(['conditions' => 'At least one condition is required.']);
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! isset($condition['field'], $condition['operator'], $condition['value'])) {
                throw ValidationException::withMessages(['conditions' => 'Each condition requires field, operator and value.']);
            }

            if (! in_array($condition['field'], self::CONDITION_FIELDS, true)) {
                throw ValidationException::withMessages(['conditions' => 'Unsupported TDS condition field: '.$condition['field'].'.']);
            }

            if ($condition['field'] === 'pan_status') {
                $values = is_array($condition['value']) ? $condition['value'] : [$condition['value']];
                foreach ($values as $value) {
                    if (PanStatus::tryFrom(strtolower(trim((string) $value))) === null) {
                        throw ValidationException::withMessages(['conditions' => 'PAN status conditions must be Active or Inactive.']);
                    }
                }
            }
        }
    }

    private function assertPlatformMutate(User $actor): void
    {
        if (! $actor->isInternal()) {
            throw new TaxDomainException('Only internal platform operators may mutate central TDS rules.');
        }

        if (! $this->authorizer->allowsInternal($actor, PermissionCodes::PLATFORM_MANAGE_SETTINGS)) {
            throw new TaxDomainException('Not authorized to manage central TDS rules.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
