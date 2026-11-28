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
 * Weights and rate semantics are inherited from the linked Bilty goods rows.
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

            $actualWeight = $row->actual_weight !== null
                ? $this->formattedWeight((string) $row->actual_weight)
                : '0.000';
            $chargedWeight = $row->charged_weight !== null
                ? $this->formattedWeight((string) $row->charged_weight)
                : $actualWeight;

            $semantics = $row->rate_semantics ?? FreightRateSemantics::WeightKg;

            try {
                $freight = $this->calculations->goodsLineFreight([
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
                'is_fixed_amount' => $isFixedRate,
                'freight_amount' => $freight,
                'loading_weight' => $this->loadingWeightForRateBasis($row, $rateType),
                'rate_semantics' => $semantics,
            ];
        }

        return $computed;
    }

    /**
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
     * Loading weight snapshot (canonical KG) derived from the Bilty goods row by rate basis.
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
