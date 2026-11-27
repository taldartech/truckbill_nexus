/**
 * Authoritative freight Rate Type options (Rules §27). Must stay aligned with
 * `App\Domains\Consignment\Support\FreightRateTypeCatalog`.
 */
export const FREIGHT_RATE_TYPE_OPTIONS = [
    { value: 'per_charged_weight', label: 'Per Charged Weight' },
    { value: 'per_actual_weight', label: 'Per Actual Weight' },
    { value: 'fixed_amount', label: 'Fixed' },
    { value: 'per_package_unit', label: 'Per Unit' },
] as const;

export const DEFAULT_FREIGHT_RATE_TYPE = 'per_charged_weight';

const SELECTABLE_VALUES: Set<string> = new Set(
    FREIGHT_RATE_TYPE_OPTIONS.map((option) => option.value),
);

const LEGACY_LABELS: Record<string, string> = {
    per_kg: 'Per KG',
    per_mt: 'Per MT',
};

export function freightRateTypeLabel(value: string): string {
    const known = FREIGHT_RATE_TYPE_OPTIONS.find(
        (option) => option.value === value,
    );
    if (known) {
        return known.label;
    }

    return LEGACY_LABELS[value] ?? value;
}

export function isSelectableFreightRateType(value: string): boolean {
    return SELECTABLE_VALUES.has(value);
}
