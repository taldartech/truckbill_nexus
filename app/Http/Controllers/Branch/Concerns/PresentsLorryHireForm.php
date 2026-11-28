<?php

namespace App\Http\Controllers\Branch\Concerns;

use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Support\FreightRateTypeCatalog;
use App\Models\LorryReceipt;
use App\Models\LrGoodsRow;
use Illuminate\Validation\Rule;

/**
 * Shared Inertia props for the Lorry Hire create / edit form (inherited Bilty data is read only).
 */
trait PresentsLorryHireForm
{
    /**
     * @return array{
     *     lorryReceipt: array{
     *         id: string,
     *         lr_number: string|null,
     *         lr_date: string|null,
     *         customer_name: string|null,
     *         vehicle_registration: string|null,
     *         vehicle_owner_name: string|null,
     *         loading_station: string|null,
     *         unloading_station: string|null
     *     },
     *     goodsRows: list<array{
     *         id: string,
     *         label: string,
     *         package_count: mixed,
     *         actual_weight: mixed,
     *         charged_weight: mixed,
     *         rate_semantics: string
     *     }>,
     *     rateTypeOptions: list<array{value: string, label: string}>
     * }
     */
    protected function lorryHireFormProps(LorryReceipt $lorryReceipt): array
    {
        $lorryReceipt->loadMissing([
            'fromStation',
            'toStation',
            'billingCustomer',
            'vehicle.ownerParty',
            'goodsRows.goodsType',
        ]);

        return [
            'lorryReceipt' => [
                'id' => $lorryReceipt->id,
                'lr_number' => $lorryReceipt->lr_number,
                'lr_date' => $lorryReceipt->lr_date?->toDateString(),
                'customer_name' => $lorryReceipt->billingCustomer?->name,
                'vehicle_registration' => $lorryReceipt->vehicle?->registration_number,
                'vehicle_owner_name' => $lorryReceipt->vehicle?->ownerParty?->name,
                'loading_station' => $this->loadingStationLabel($lorryReceipt),
                'unloading_station' => $this->unloadingStationLabel($lorryReceipt),
            ],
            'goodsRows' => array_values($lorryReceipt->goodsRows->map(fn (LrGoodsRow $row): array => [
                'id' => $row->id,
                'label' => $this->goodsRowLabel($row),
                'package_count' => $row->package_count,
                'actual_weight' => $row->actual_weight,
                'charged_weight' => $row->charged_weight,
                'rate_semantics' => ($row->rate_semantics ?? FreightRateSemantics::WeightKg)->value,
            ])->all()),
            'rateTypeOptions' => FreightRateTypeCatalog::inertiaOptions(),
        ];
    }

    /**
     * Validation rules shared by Lorry Hire create and edit (server recalculates every amount).
     *
     * @return array<string, mixed>
     */
    protected function lorryHireFormRules(): array
    {
        return [
            'hire_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'goods_lines' => ['required', 'array', 'min:1'],
            'goods_lines.*.lr_goods_row_id' => ['required', 'uuid'],
            'goods_lines.*.rate_type' => [
                'required',
                'string',
                Rule::in(FreightRateTypeCatalog::selectableValues()),
            ],
            'goods_lines.*.rate' => ['required', 'numeric', 'min:0'],
            'custom_amounts' => ['nullable', 'array'],
            'custom_amounts.*.id' => ['nullable', 'uuid'],
            'custom_amounts.*.description' => ['required_with:custom_amounts.*.amount', 'string', 'max:255'],
            'custom_amounts.*.amount' => ['required_with:custom_amounts.*.description', 'numeric', 'not_in:0', 'max:99999999.99', 'min:-99999999.99'],
        ];
    }

    protected function loadingStationLabel(LorryReceipt $lorryReceipt): ?string
    {
        return $lorryReceipt->fromStation !== null
            ? $lorryReceipt->fromStation->name
            : $lorryReceipt->from_text;
    }

    protected function unloadingStationLabel(LorryReceipt $lorryReceipt): ?string
    {
        return $lorryReceipt->toStation !== null
            ? $lorryReceipt->toStation->name
            : $lorryReceipt->to_text;
    }

    protected function goodsRowLabel(LrGoodsRow $row): string
    {
        $typeName = $row->goodsType?->name;
        $description = filled($row->goods_description) ? (string) $row->goods_description : null;

        if ($typeName !== null && $description !== null) {
            return $typeName.' — '.$description;
        }

        return $typeName ?? $description ?? 'Goods';
    }
}
