import { formatInr } from '@/lib/money';
import {
    estimateHireProfitability,
    type HireProfitability,
} from '@/lib/hire-profitability';
import { freightRateTypeLabel } from '@/lib/freight-rate-type-options';
import { cn } from '@/lib/utils';
import {
    LrfSection,
    LrfSummaryMetrics,
} from '@/pages/branch/lorry-receipts/lr-form-sections';

type CustomerGoodsLine = {
    label: string;
    rate_type: string;
    rate: string | null;
    actual_weight: string | null;
    charged_weight: string | null;
    package_count: string | null;
    customer_freight_amount: string | null;
};

type Props = {
    canViewCustomerFreight: boolean;
    customerGoodsLines: CustomerGoodsLine[];
    customerFreightTotal: string | null;
    transporterFreight: number | null;
    transporterNetPayable: number | null;
    adjustmentImpact: number;
};

function money(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : formatInr(value);
}

function profitTone(profit: HireProfitability | null): string {
    if (profit === null) {
        return 'text-muted-foreground';
    }
    if (profit.estimatedProfit > 0.01) {
        return 'text-emerald-700';
    }
    if (profit.estimatedProfit < -0.01) {
        return 'text-destructive';
    }

    return 'text-muted-foreground';
}

export function FreightProfitabilitySection({
    canViewCustomerFreight,
    customerGoodsLines,
    customerFreightTotal,
    transporterFreight,
    transporterNetPayable,
    adjustmentImpact,
}: Props) {
    if (!canViewCustomerFreight) {
        return null;
    }

    const customerTotal =
        customerFreightTotal !== null ? Number(customerFreightTotal) : null;
    const profit = estimateHireProfitability(
        customerTotal,
        transporterNetPayable,
    );

    return (
        <div data-testid="lorry-hire-section-freight-profitability">
            <LrfSection
                step={3}
                title="Freight & Profitability"
                description="Customer freight from the linked Bilty versus transporter net payable (estimated)"
                tone="goods"
            >
                <h3 className="mb-2 text-sm font-semibold text-slate-800">
                    Customer freight (read only)
                </h3>
                <div className="overflow-x-auto rounded-md border border-slate-200">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs text-slate-500">
                            <tr>
                                <th className="px-2 py-2">Goods</th>
                                <th className="px-2 py-2">Rate type</th>
                                <th className="px-2 py-2 text-end">Rate</th>
                                <th className="px-2 py-2 text-end">
                                    Charged wt.
                                </th>
                                <th className="px-2 py-2 text-end">Freight</th>
                            </tr>
                        </thead>
                        <tbody>
                            {customerGoodsLines.map((row) => (
                                <tr
                                    key={row.label}
                                    className="border-t border-slate-100"
                                >
                                    <td className="px-2 py-2">{row.label}</td>
                                    <td className="px-2 py-2">
                                        {freightRateTypeLabel(row.rate_type)}
                                    </td>
                                    <td className="px-2 py-2 text-end tabular-nums">
                                        {row.rate ?? '—'}
                                    </td>
                                    <td className="px-2 py-2 text-end tabular-nums">
                                        {row.charged_weight ??
                                            row.actual_weight ??
                                            '—'}
                                    </td>
                                    <td className="px-2 py-2 text-end tabular-nums">
                                        {row.customer_freight_amount ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-slate-200 bg-slate-50 font-semibold">
                                <td className="px-2 py-2 text-end" colSpan={4}>
                                    Customer freight total
                                </td>
                                <td className="px-2 py-2 text-end tabular-nums">
                                    {money(customerTotal)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="mt-4">
                    <LrfSummaryMetrics
                        items={[
                            {
                                name: 'Transporter freight',
                                value: money(transporterFreight),
                            },
                            ...(adjustmentImpact !== 0
                                ? [
                                      {
                                          name: 'Delivery adjustments',
                                          value: money(adjustmentImpact),
                                      },
                                  ]
                                : []),
                            {
                                name: 'Transporter net payable',
                                value: money(transporterNetPayable),
                            },
                            {
                                name: 'Estimated profit / loss',
                                value: money(profit?.estimatedProfit ?? null),
                                hero: true,
                            },
                            ...(profit !== null && profit.marginPercent !== null
                                ? [
                                      {
                                          name: 'Margin',
                                          value: `${profit.marginPercent.toFixed(2)}%`,
                                      },
                                  ]
                                : []),
                        ]}
                    />
                    {profit ? (
                        <p
                            className={cn(
                                'mt-2 text-sm font-semibold tabular-nums',
                                profitTone(profit),
                            )}
                        >
                            {profit.estimatedProfit > 0.01
                                ? 'Estimated positive freight margin'
                                : profit.estimatedProfit < -0.01
                                  ? 'Estimated freight loss'
                                  : 'Break-even freight estimate'}
                        </p>
                    ) : null}
                    <p className="mt-2 text-xs text-muted-foreground">
                        Estimated freight profitability only — not finalized
                        accounting profit. TDS withheld from the transporter is
                        not counted as profit.
                    </p>
                </div>
            </LrfSection>
        </div>
    );
}
