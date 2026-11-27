<?php

namespace App\Domains\Tax;

/**
 * Human-readable summaries of structured TDS rule conditions for admin UI.
 */
final class TdsConditionPresenter
{
    private const FIELD_LABELS = [
        'pan_status' => 'PAN status',
        'pan_fourth_char' => 'PAN 4th character',
        'declaration_valid' => 'TDS declaration received',
        'goods_vehicle_count' => 'Goods vehicle count',
        'entity_type' => 'Entity type',
    ];

    /**
     * @param  list<array{field?: mixed, operator?: mixed, value?: mixed}>|mixed  $conditions
     */
    public function summarize(mixed $conditions, string $logic = 'AND'): string
    {
        if (! is_array($conditions) || $conditions === []) {
            return 'No conditions';
        }

        $parts = [];
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $parts[] = $this->summarizeOne($condition);
        }

        if ($parts === []) {
            return 'No conditions';
        }

        $joiner = strtoupper($logic) === 'OR' ? ' or ' : ' and ';

        return implode($joiner, $parts);
    }

    /**
     * @param  list<array{field?: mixed, operator?: mixed, value?: mixed}>|mixed  $conditions
     */
    public function previewSentence(mixed $conditions, string $logic, string $ratePercent, string $ruleName = ''): string
    {
        $summary = $this->summarize($conditions, $logic);
        $rate = rtrim(rtrim((string) $ratePercent, '0'), '.');
        if ($rate === '') {
            $rate = '0';
        }

        $prefix = $ruleName !== ''
            ? 'Apply '.$rate.'% TDS for “'.$ruleName.'” when '
            : 'Apply '.$rate.'% TDS when ';

        return $prefix.$summary.'.';
    }

    /**
     * @param  array{field?: mixed, operator?: mixed, value?: mixed}  $condition
     */
    private function summarizeOne(array $condition): string
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = strtolower((string) ($condition['operator'] ?? 'eq'));
        $value = $condition['value'] ?? null;
        $label = self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field);

        if ($field === 'declaration_valid') {
            $yes = $this->isTruthy($value);

            return $yes ? 'TDS declaration is received' : 'TDS declaration is not received';
        }

        if ($field === 'pan_status') {
            return 'PAN is '.$this->formatValue($value);
        }

        if ($field === 'pan_fourth_char' && is_array($value)) {
            $joined = implode('/', array_map(fn ($v) => (string) $v, $value));

            return 'PAN 4th character is one of '.$joined;
        }

        if ($field === 'goods_vehicle_count') {
            return match ($operator) {
                'lte', '<=' => 'goods vehicles ≤ '.$this->formatValue($value),
                'lt', '<' => 'goods vehicles < '.$this->formatValue($value),
                'gte', '>=' => 'goods vehicles ≥ '.$this->formatValue($value),
                'gt', '>' => 'goods vehicles > '.$this->formatValue($value),
                default => 'goods vehicles '.$operator.' '.$this->formatValue($value),
            };
        }

        $opLabel = match ($operator) {
            'eq', '=' => 'is',
            'neq', '!=', 'ne' => 'is not',
            'in' => 'is one of',
            'truthy' => $this->isTruthy($value) ? 'is true' : 'is false',
            default => $operator,
        };

        if ($operator === 'truthy') {
            return $label.' '.$opLabel;
        }

        return $label.' '.$opLabel.' '.$this->formatValue($value);
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        if ($value === null) {
            return '—';
        }

        return (string) $value;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return (bool) $value;
    }
}
