<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\Consignment\Support\FreightRateTypeCatalog;
use App\Models\LorryReceipt;
use App\Models\LrGoodsRow;
use Illuminate\Validation\ValidationException;

/**
 * Builds server-authoritative transporter freight lines for Lorry Hire create and edit (Rules §11).
 *
 * Weights and rate semantics are inherited from the linked Bilty goods rows. A Custom Amount
 * replaces the calculated freight of a line while its Rate Type and Rate are preserved.
 */
final class LorryHireGoodsLineBuilder
{
    public function __construct(
        private readonly LorryHireCalculationService $calculations = new LorryHireCalculationService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $goodsLinesInput
     * @return list<array{
     *     lr_goods_row_id: string,
     *     rate_type: FreightRateType,
     *     rate: numeric-string,
     *     is_fixed_amount: bool,
     *     calculated_freight_amount: numeric-string,
     *     custom_amount: numeric-string|null,
     *     freight_amount: numeric-string,
     *     loading_weight: numeric-string,
     *     rate_semantics: FreightRateSemantics
     * }>
     */
    public function build(LorryReceipt $lorryReceipt, array $goodsLinesInput): array
    {
        if ($goodsLinesInput === []) {
            throw ValidationException::withMessages([
                'goods_lines' => 'At least one transporter freight goods line is required.',
            ]);
        }

        $lorryReceipt->loadMissing('goodsRows');
        $rowsById = $lorryReceipt->goodsRows->keyBy('id');
        $seen = [];
        $computed = [];

        foreach ($goodsLinesInput as $index => $input) {
            $rowId = (string) ($input['lr_goods_row_id'] ?? '');
            if ($rowId === '' || ! $rowsById->has($rowId)) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.lr_goods_row_id" => 'Goods line must reference an LR goods row.',
                ]);
            }
            if (isset($seen[$rowId])) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.lr_goods_row_id" => 'Duplicate LR goods row on Lorry Hire.',
                ]);
            }
            $seen[$rowId] = true;

            /** @var LrGoodsRow $row */
            $row = $rowsById->get($rowId);

            $rateType = FreightRateType::tryFrom(
                (string) ($input['rate_type'] ?? FreightRateTypeCatalog::default()->value),
            );
            if ($rateType === null) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.rate_type" => 'Invalid transporter freight rate type.',
                ]);
            }
            $isFixedRate = $rateType === FreightRateType::FixedAmount;

            $rate = (string) ($input['rate'] ?? '');
            if ($rate === '' || ! is_numeric($rate)) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.rate" => 'Transporter rate is required.',
                ]);
            }

            $customAmount = $this->customAmount($input['custom_amount'] ?? null, $isFixedRate, $index);

            $actualWeight = $row->actual_weight !== null
                ? $this->formattedWeight((string) $row->actual_weight)
                : '0.000';
            $chargedWeight = $row->charged_weight !== null
                ? $this->formattedWeight((string) $row->charged_weight)
                : $actualWeight;

            $semantics = $row->rate_semantics ?? FreightRateSemantics::WeightKg;

            try {
                $calculatedFreight = $this->calculations->goodsLineFreight([
                    'rate_type' => $rateType,
                    'rate' => $rate,
                    'is_fixed_amount' => $isFixedRate,
                    'package_count' => $row->package_count,
                    'actual_weight' => $actualWeight,
                    'charged_weight' => $chargedWeight,
                    'rate_semantics' => $semantics,
                ]);
            } catch (LorryReceiptDomainException $e) {
                throw ValidationException::withMessages([
                    "goods_lines.{$index}.rate_type" => $e->getMessage(),
                ]);
            }

            $computed[] = [
                'lr_goods_row_id' => $rowId,
                'rate_type' => $rateType,
                'rate' => number_format((float) $rate, 4, '.', ''),
                // Fixed-rate and Custom Amount lines keep their freight when unloading weight changes.
                'is_fixed_amount' => $isFixedRate || $customAmount !== null,
                'calculated_freight_amount' => $calculatedFreight,
                'custom_amount' => $customAmount,
                'freight_amount' => $customAmount ?? $calculatedFreight,
                'loading_weight' => $this->loadingWeightForRateBasis($row, $rateType),
                'rate_semantics' => $semantics,
            ];
        }

        return $computed;
    }

    /**
     * Effective freight of built lines (Custom Amount when entered, otherwise calculated).
     *
     * @param  list<array{freight_amount: numeric-string}>  $lines
     * @return numeric-string
     */
    public function freightTotal(array $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $total = bcadd($total, $line['freight_amount'], 2);
        }

        return $total;
    }

    /**
     * @return numeric-string|null
     */
    private function customAmount(mixed $value, bool $isFixedRate, int|string $index): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || round((float) $value, 2) <= 0.0) {
            throw ValidationException::withMessages([
                "goods_lines.{$index}.custom_amount" => 'Custom Amount must be greater than zero.',
            ]);
        }

        if ($isFixedRate) {
            throw ValidationException::withMessages([
                "goods_lines.{$index}.custom_amount" => 'Fixed rate already uses the entered amount. Turn off Custom Amount or choose another Rate Type.',
            ]);
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Loading weight snapshot (canonical KG) derived from the Bilty goods row by rate basis:
     * Per Actual Weight uses actual weight; every other rate type uses charged weight.
     * Falls back to the other weight when the preferred one is missing or zero.
     *
     * @return numeric-string
     */
    private function loadingWeightForRateBasis(LrGoodsRow $row, FreightRateType $rateType): string
    {
        $candidates = $rateType === FreightRateType::PerActualWeight
            ? [$row->actual_weight, $row->charged_weight]
            : [$row->charged_weight, $row->actual_weight];

        foreach ($candidates as $weight) {
            if ($weight !== null && is_numeric((string) $weight) && bccomp((string) $weight, '0', 3) > 0) {
                return $this->formattedWeight((string) $weight);
            }
        }

        return '0.000';
    }

    /**
     * @return numeric-string
     */
    private function formattedWeight(string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
