/**
 * Human-readable labels for server pricing breakdown keys.
 * Do not render raw object keys as user-facing copy.
 */
const PRICING_BREAKDOWN_LABELS: Record<string, string> = {
    base_annual: 'Base annual total',
    after_bundle: 'After bundle discount',
    bundle_percent: 'Bundle discount %',
    period_before_tenure: 'Period amount before tenure',
    after_tenure: 'After tenure discount',
    tenure_percent: 'Tenure discount %',
    after_renewal: 'After renewal discount',
    renewal_percent: 'Renewal discount %',
    coupon_monetary_applied: 'Coupon monetary applied',
    coupon_extra_months: 'Coupon extra months',
    final_period_amount: 'Final period amount',
    monetary_cap: 'Monetary discount cap',
};

const DISPLAY_ORDER = [
    'base_annual',
    'bundle_percent',
    'after_bundle',
    'period_before_tenure',
    'tenure_percent',
    'after_tenure',
    'renewal_percent',
    'after_renewal',
    'coupon_monetary_applied',
    'coupon_extra_months',
    'monetary_cap',
    'final_period_amount',
] as const;

export type PricingBreakdownRow = {
    key: string;
    label: string;
    value: string;
};

export function pricingBreakdownRows(
    breakdown: Record<string, unknown> | null | undefined,
): PricingBreakdownRow[] {
    if (!breakdown) {
        return [];
    }

    const seen = new Set<string>();
    const rows: PricingBreakdownRow[] = [];

    for (const key of DISPLAY_ORDER) {
        if (!(key in breakdown)) {
            continue;
        }
        const value = breakdown[key];
        if (value !== null && typeof value === 'object') {
            continue;
        }
        seen.add(key);
        rows.push({
            key,
            label: PRICING_BREAKDOWN_LABELS[key] ?? key,
            value: String(value),
        });
    }

    for (const [key, value] of Object.entries(breakdown)) {
        if (seen.has(key) || key === 'display_list_prices') {
            continue;
        }
        if (value !== null && typeof value === 'object') {
            continue;
        }
        rows.push({
            key,
            label: PRICING_BREAKDOWN_LABELS[key] ?? key.replaceAll('_', ' '),
            value: String(value),
        });
    }

    return rows;
}
