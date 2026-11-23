<?php

namespace App\Domains\Tax;

/**
 * Server-authoritative transporter TDS calculation outcome.
 */
final readonly class TransporterTdsCalculationResult
{
    public function __construct(
        public string $calculatedRate,
        public string $applicableRate,
        public ?string $matchedRuleId,
        public ?string $ruleCode,
        public ?int $rulePriority,
        public ?string $panStatus,
        public string $declarationStatus,
        public ?int $goodsVehicleCount,
        public bool $overrideState,
        public string $explanation,
    ) {}

    /**
     * @return array{
     *     calculated_rate: string,
     *     applicable_rate: string,
     *     matched_rule_id: string|null,
     *     rule_code: string|null,
     *     rule_priority: int|null,
     *     pan_status: string|null,
     *     declaration_status: string,
     *     goods_vehicle_count: int|null,
     *     override_state: bool,
     *     explanation: string
     * }
     */
    public function toArray(): array
    {
        return [
            'calculated_rate' => $this->calculatedRate,
            'applicable_rate' => $this->applicableRate,
            'matched_rule_id' => $this->matchedRuleId,
            'rule_code' => $this->ruleCode,
            'rule_priority' => $this->rulePriority,
            'pan_status' => $this->panStatus,
            'declaration_status' => $this->declarationStatus,
            'goods_vehicle_count' => $this->goodsVehicleCount,
            'override_state' => $this->overrideState,
            'explanation' => $this->explanation,
        ];
    }
}
