<?php

namespace App\Domains\Tax;

use App\Domains\Tax\Enums\PanStatus;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\Exceptions\TdsNoMatchException;
use Illuminate\Validation\ValidationException;

/**
 * Builds transporter TDS evaluation context and resolves applicable rates.
 */
final class TransporterTdsCalculationService
{
    public function __construct(
        private readonly TdsRuleEvaluator $evaluator = new TdsRuleEvaluator,
    ) {}

    /**
     * Normalize party + transporter role attributes into TdsRuleEvaluator context.
     *
     * @param  array<string, mixed>  $partyAttrs
     * @param  array<string, mixed>  $roleAttrs
     * @return array{
     *     pan_status: string|null,
     *     pan_fourth_char: string|null,
     *     declaration_valid: bool,
     *     goods_vehicle_count: int|null
     * }
     */
    public function buildContextFromTransporter(array $partyAttrs, array $roleAttrs): array
    {
        $pan = strtoupper(trim((string) ($roleAttrs['pan'] ?? $partyAttrs['pan'] ?? '')));
        $panFourth = strlen($pan) >= 4 ? $pan[3] : null;

        $panStatus = is_string($roleAttrs['pan_status'] ?? null)
            ? PanStatus::tryFrom(strtolower(trim($roleAttrs['pan_status'])))?->value
            : null;

        $goodsCount = $roleAttrs['goods_vehicles_count'] ?? null;
        $goodsVehicleCount = is_numeric($goodsCount) ? (int) $goodsCount : null;

        return [
            'pan_status' => $panStatus,
            'pan_fourth_char' => $panFourth,
            'declaration_valid' => $this->normalizeDeclarationValid($roleAttrs['declaration'] ?? null),
            'goods_vehicle_count' => $goodsVehicleCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function calculate(
        array $context,
        ?string $overrideRate = null,
        ?string $overrideRemark = null,
        bool $allowOverride = false,
    ): TransporterTdsCalculationResult {
        $wantsOverride = $overrideRate !== null && trim($overrideRate) !== '';

        if ($wantsOverride) {
            if (! $allowOverride) {
                throw new TaxDomainException('TDS override is not allowed for this operation.');
            }

            if (! filled($overrideRemark)) {
                throw ValidationException::withMessages([
                    'tds_adjustment_remark' => 'TDS adjustment remark is required when TDS is manually overridden.',
                ]);
            }

            if (! is_numeric($overrideRate)) {
                throw ValidationException::withMessages([
                    'tds_percentage' => 'TDS override rate must be numeric.',
                ]);
            }
        }

        try {
            $matched = $this->evaluator->evaluate($context);
        } catch (TdsNoMatchException $e) {
            throw new TaxDomainException(
                'No TDS rule matched the transporter context. Configuration error.',
                previous: $e,
            );
        }

        $calculatedRate = $this->formatRate((string) $matched['rate_percent']);
        $rule = $matched['rule'];
        $declarationStatus = ! empty($context['declaration_valid']) ? 'valid' : 'invalid';
        $panStatus = isset($context['pan_status']) && is_string($context['pan_status'])
            ? $context['pan_status']
            : null;
        $goodsVehicleCount = isset($context['goods_vehicle_count']) && is_numeric($context['goods_vehicle_count'])
            ? (int) $context['goods_vehicle_count']
            : null;

        if ($wantsOverride) {
            $applicableRate = $this->formatRate((string) $overrideRate);

            return new TransporterTdsCalculationResult(
                calculatedRate: $calculatedRate,
                applicableRate: $applicableRate,
                matchedRuleId: $rule->id,
                ruleCode: $rule->code,
                rulePriority: (int) $rule->priority,
                panStatus: $panStatus,
                declarationStatus: $declarationStatus,
                goodsVehicleCount: $goodsVehicleCount,
                overrideState: true,
                explanation: sprintf(
                    'Manual override to %s%% (rule would apply %s%% via %s). Remark: %s',
                    $applicableRate,
                    $calculatedRate,
                    $rule->code,
                    trim((string) $overrideRemark),
                ),
            );
        }

        return new TransporterTdsCalculationResult(
            calculatedRate: $calculatedRate,
            applicableRate: $calculatedRate,
            matchedRuleId: $rule->id,
            ruleCode: $rule->code,
            rulePriority: (int) $rule->priority,
            panStatus: $panStatus,
            declarationStatus: $declarationStatus,
            goodsVehicleCount: $goodsVehicleCount,
            overrideState: false,
            explanation: sprintf(
                'Matched rule %s (priority %d) at %s%%.',
                $rule->code,
                (int) $rule->priority,
                $calculatedRate,
            ),
        );
    }

    private function normalizeDeclarationValid(mixed $declaration): bool
    {
        if (is_bool($declaration)) {
            return $declaration;
        }

        if (is_string($declaration)) {
            $lower = strtolower(trim($declaration));
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }

            return $declaration !== '';
        }

        if (is_int($declaration) || is_float($declaration)) {
            return (int) $declaration === 1;
        }

        return filled($declaration);
    }

    private function formatRate(string $rate): string
    {
        return number_format((float) $rate, 3, '.', '');
    }
}
