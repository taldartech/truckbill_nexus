<?php

namespace App\Domains\Tax;

use App\Domains\Tax\Exceptions\TdsNoMatchException;
use App\Models\TdsRule;
use Illuminate\Support\Collection;

/**
 * Evaluates structured AND/OR TDS rules by priority (Rules §6).
 *
 * Context keys commonly include: pan_status (active|inactive), pan_fourth_char,
 * entity_type, declaration_valid (TDS declaration received) and goods_vehicle_count.
 *
 * Result is snapshot-compatible; does not mutate historical transactions.
 */
final class TdsRuleEvaluator
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{rule: TdsRule, snapshot: array{tds_rule_id: string, code: string, name: string, rate_percent: string, priority: int}, rate_percent: string}
     */
    public function evaluate(array $context, bool $activeOnly = true): array
    {
        $rules = $this->loadRules($activeOnly);

        foreach ($rules as $rule) {
            if ($this->matches($rule, $context)) {
                return [
                    'rule' => $rule,
                    'snapshot' => $rule->toSnapshot(),
                    'rate_percent' => (string) $rule->rate_percent,
                ];
            }
        }

        throw new TdsNoMatchException('No TDS rule matched the provided context. Configuration error.');
    }

    /**
     * @return Collection<int, TdsRule>
     */
    public function loadRules(bool $activeOnly = true): Collection
    {
        $builder = TdsRule::query()->orderBy('priority')->orderBy('code');
        if ($activeOnly) {
            $builder->where('is_active', true);
        }

        return $builder->get();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function matches(TdsRule $rule, array $context): bool
    {
        $conditions = $rule->conditions ?? [];
        if ($conditions === []) {
            return false;
        }

        $results = [];
        foreach ($conditions as $condition) {
            $results[] = $this->evaluateCondition($condition, $context);
        }

        $logic = strtoupper((string) $rule->condition_logic);

        return $logic === 'OR'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array{field: string, operator: string, value: mixed}  $condition
     * @param  array<string, mixed>  $context
     */
    private function evaluateCondition(array $condition, array $context): bool
    {
        $field = (string) $condition['field'];
        $operator = strtolower((string) $condition['operator']);
        $expected = $condition['value'];
        $actual = $context[$field] ?? null;

        return match ($operator) {
            'eq', '=' => $this->normalize($actual) === $this->normalize($expected),
            'neq', '!=' => $this->normalize($actual) !== $this->normalize($expected),
            'in' => is_array($expected) && in_array($this->normalize($actual), array_map(fn ($v) => $this->normalize($v), $expected), true),
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'truthy' => (bool) $actual === (bool) $expected,
            default => false,
        };
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return strtoupper(trim($value));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }
}
