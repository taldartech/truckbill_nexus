import type { ReactNode } from 'react';
import { Gauge } from 'lucide-react';
import { cn } from '@/lib/utils';

export function TripDetailPage({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('trip-detail-page app-index-page', className)}>
            {children}
        </div>
    );
}

export function TripDetailLayout({
    main,
    side,
}: {
    main: ReactNode;
    side: ReactNode;
}) {
    return (
        <div className="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div className="trip-detail-main space-y-3">{main}</div>
            <div className="trip-detail-side space-y-3">{side}</div>
        </div>
    );
}

export function TripDetailCard({
    title,
    badges,
    actions,
    children,
    className,
    id,
}: {
    title: ReactNode;
    badges?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    id?: string;
}) {
    return (
        <div
            id={id}
            className={cn(
                'card overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-xs',
                className,
            )}
        >
            <div className="card-header trip-detail-card-header flex flex-wrap items-center justify-between gap-2 border-b border-[#e2e8f0] px-4 py-3">
                <strong className="trip-detail-card-title flex flex-wrap items-center gap-2 text-[0.95rem] font-bold text-slate-800">
                    {title}
                    {badges}
                </strong>
                {actions ? (
                    <div className="trip-detail-actions flex flex-wrap items-center gap-1.5">
                        {actions}
                    </div>
                ) : null}
            </div>
            <div className="card-body p-0">{children}</div>
        </div>
    );
}

export function TripInfoGrid({ children }: { children: ReactNode }) {
    return <div className="trip-info-grid">{children}</div>;
}

export function TripInfoItem({
    label,
    value,
    accent,
    sub,
    full,
    span2,
}: {
    label: string;
    value: ReactNode;
    accent?: boolean;
    sub?: ReactNode;
    full?: boolean;
    span2?: boolean;
}) {
    return (
        <div
            className={cn(
                'trip-info-grid__item',
                full && 'trip-info-grid__item--full',
                span2 && 'trip-info-grid__item--span-2',
            )}
        >
            <span className="trip-info-grid__label">{label}</span>
            <div
                className={cn(
                    'trip-info-grid__value',
                    accent && 'trip-info-grid__value--accent',
                )}
            >
                {value ?? '—'}
            </div>
            {sub ? <div className="trip-info-grid__sub">{sub}</div> : null}
        </div>
    );
}

export type TripProfitProps = {
    agreed_freight: string;
    additional_charges: string;
    diesel_cost: string;
    other_expenses: string;
    total_expenses: string;
    trip_profit: string;
    trip_mileage_label: string;
};

function formatInr(value: string): string {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return value;
    }
    return n.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function TripFinancialSummary({
    profit,
    distanceKm,
}: {
    profit: TripProfitProps;
    distanceKm?: string | null;
}) {
    const revenue =
        Number(profit.agreed_freight) + Number(profit.additional_charges);
    const expenseAmount = Number(profit.total_expenses);
    const profitAmount = Number(profit.trip_profit);
    const isProfit = profitAmount >= 0;
    const scale = Math.max(revenue, expenseAmount, 1);
    const freightBarPct = Math.min(100, (revenue / scale) * 100);
    const expenseBarPct = Math.min(100, (expenseAmount / scale) * 100);
    const marginLabel =
        revenue > 0 ? `${((profitAmount / revenue) * 100).toFixed(1)}%` : '—';
    const distance = distanceKm ? Number(distanceKm) : 0;

    const perKmRows =
        distance > 0
            ? [
                  {
                      label: 'Revenue / KM',
                      value: `₹${formatInr(String(revenue / distance))}`,
                  },
                  {
                      label: 'Expense / KM',
                      value: `₹${formatInr(String(expenseAmount / distance))}`,
                  },
                  {
                      label: 'Profit / KM',
                      value: `₹${formatInr(String(profitAmount / distance))}`,
                  },
              ]
            : [];

    return (
        <div
            className={cn(
                'card trip-financial-summary overflow-hidden rounded-xl border bg-white shadow-xs',
                isProfit
                    ? 'trip-financial-summary--profit'
                    : 'trip-financial-summary--loss',
            )}
        >
            <div className="trip-financial-summary__header">
                <div>
                    <span className="trip-financial-summary__title">
                        Financial Summary
                    </span>
                    <span className="trip-financial-summary__subtitle">
                        Revenue vs expenses
                    </span>
                </div>
                <span
                    className={cn(
                        'trip-financial-summary__badge',
                        isProfit ? 'is-profit' : 'is-loss',
                    )}
                >
                    {isProfit ? 'Profit' : 'Loss'} ₹
                    {formatInr(String(Math.abs(profitAmount)))}
                </span>
            </div>
            <div className="trip-financial-summary__body">
                <div className="trip-financial-summary__stats">
                    <div className="trip-financial-summary__stat">
                        <span className="trip-financial-summary__stat-label">
                            Freight
                        </span>
                        <span className="trip-financial-summary__stat-value">
                            ₹{formatInr(profit.agreed_freight)}
                        </span>
                    </div>
                    {Number(profit.additional_charges) > 0 ? (
                        <div className="trip-financial-summary__stat">
                            <span className="trip-financial-summary__stat-label">
                                Additional
                            </span>
                            <span className="trip-financial-summary__stat-value">
                                ₹{formatInr(profit.additional_charges)}
                            </span>
                        </div>
                    ) : null}
                    <div className="trip-financial-summary__stat">
                        <span className="trip-financial-summary__stat-label">
                            Expenses
                        </span>
                        <span className="trip-financial-summary__stat-value trip-financial-summary__stat-value--expense">
                            ₹{formatInr(profit.total_expenses)}
                        </span>
                    </div>
                    <div className="trip-financial-summary__stat trip-financial-summary__stat--result">
                        <span className="trip-financial-summary__stat-label">
                            {isProfit ? 'Net Profit' : 'Net Loss'}
                        </span>
                        <span
                            className={cn(
                                'trip-financial-summary__stat-value',
                                isProfit ? 'is-profit' : 'is-loss',
                            )}
                        >
                            ₹{formatInr(profit.trip_profit)}
                        </span>
                    </div>
                </div>

                <div className="trip-financial-summary__bar" aria-hidden>
                    <span
                        className="trip-financial-summary__bar-freight"
                        style={{ width: `${freightBarPct}%` }}
                    />
                    <span
                        className="trip-financial-summary__bar-expense"
                        style={{ width: `${expenseBarPct}%` }}
                    />
                </div>
                <div className="trip-financial-summary__legend">
                    <span>Freight</span>
                    <span>Expenses</span>
                    <span className="trip-financial-summary__margin">
                        Margin {marginLabel}
                    </span>
                </div>

                <div className="trip-financial-summary__rows">
                    <div className="trip-financial-summary__row">
                        <span className="text-slate-500">Diesel</span>
                        <span className="font-semibold text-slate-800 tabular-nums">
                            ₹{formatInr(profit.diesel_cost)}
                        </span>
                    </div>
                    <div className="trip-financial-summary__row">
                        <span className="text-slate-500">Other expenses</span>
                        <span className="font-semibold text-slate-800 tabular-nums">
                            ₹{formatInr(profit.other_expenses)}
                        </span>
                    </div>
                    {perKmRows.map((row) => (
                        <div
                            key={row.label}
                            className="trip-financial-summary__row"
                        >
                            <span className="text-slate-500">{row.label}</span>
                            <span className="font-semibold text-slate-800 tabular-nums">
                                {row.value}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

export function TripDieselCard({
    profit,
    dieselLitres,
    distanceKm,
}: {
    profit: TripProfitProps;
    dieselLitres: string;
    distanceKm?: string | null;
}) {
    const litres = Number(dieselLitres);
    const distance = distanceKm ? Number(distanceKm) : 0;

    return (
        <div className="card trip-performance-card overflow-hidden rounded-xl border bg-white shadow-xs">
            <div className="trip-performance-card__header">
                <h3 className="trip-performance-card__title mb-0 flex items-center gap-1.5">
                    <Gauge
                        className="size-4 shrink-0 text-slate-600"
                        aria-hidden
                    />
                    Diesel & mileage
                </h3>
            </div>
            <div className="trip-performance-card__body">
                <div className="trip-performance-metrics">
                    <div className="trip-performance-metrics__item">
                        <span className="trip-performance-metrics__label">
                            Trip mileage
                        </span>
                        <span className="trip-performance-metrics__value">
                            {profit.trip_mileage_label}
                        </span>
                    </div>
                    <div className="trip-performance-metrics__item">
                        <span className="trip-performance-metrics__label">
                            Diesel cost
                        </span>
                        <span className="trip-performance-metrics__value">
                            ₹{formatInr(profit.diesel_cost)}
                        </span>
                    </div>
                    <div className="trip-performance-metrics__item">
                        <span className="trip-performance-metrics__label">
                            Diesel litres
                        </span>
                        <span className="trip-performance-metrics__value">
                            {litres > 0 ? `${litres.toFixed(3)} L` : '—'}
                        </span>
                    </div>
                    <div className="trip-performance-metrics__item">
                        <span className="trip-performance-metrics__label">
                            Trip distance
                        </span>
                        <span className="trip-performance-metrics__value">
                            {distance > 0 ? `${distance} km` : '—'}
                        </span>
                    </div>
                </div>
                <p className="mt-3 text-xs leading-relaxed text-slate-500">
                    Full expected-vs-actual diesel performance metrics are not
                    available on this screen yet; values reflect recorded
                    expenses and completion mileage.
                </p>
            </div>
        </div>
    );
}

export function TripKmBar({
    pickupCount,
    dropCount,
    tripDistance,
    totalFreight,
    lrFreight,
    additionalCharges,
    totalExpenses,
    mileageLabel,
    allocationReady,
}: {
    pickupCount: number;
    dropCount: number;
    tripDistance?: string | null;
    totalFreight: string;
    lrFreight?: string | null;
    additionalCharges?: string | null;
    totalExpenses: string;
    mileageLabel: string;
    allocationReady: boolean;
}) {
    return (
        <TripDetailCard title="Trip summary bar">
            <div className="trip-detail-km-bar">
                <div className="trip-detail-km-bar__grid">
                    <div className="trip-detail-km-bar__item">
                        <span className="trip-detail-km-bar__label">
                            Pickups / Drops
                        </span>
                        <strong>
                            {pickupCount} / {dropCount}
                        </strong>
                    </div>
                    <div className="trip-detail-km-bar__item">
                        <span className="trip-detail-km-bar__label">
                            Total KM
                        </span>
                        <strong>
                            {tripDistance ? `${tripDistance} km` : '—'}
                        </strong>
                    </div>
                    <div className="trip-detail-km-bar__item">
                        <span className="trip-detail-km-bar__label">
                            Allocated freight
                        </span>
                        <strong>₹{formatInr(totalFreight)}</strong>
                    </div>
                    {lrFreight ? (
                        <div className="trip-detail-km-bar__item">
                            <span className="trip-detail-km-bar__label">
                                LR freight
                            </span>
                            <strong>₹{formatInr(lrFreight)}</strong>
                        </div>
                    ) : null}
                    {additionalCharges && Number(additionalCharges) > 0 ? (
                        <div className="trip-detail-km-bar__item">
                            <span className="trip-detail-km-bar__label">
                                LR additional
                            </span>
                            <strong>₹{formatInr(additionalCharges)}</strong>
                        </div>
                    ) : null}
                    <div className="trip-detail-km-bar__item">
                        <span className="trip-detail-km-bar__label">
                            Total expenses
                        </span>
                        <strong>₹{formatInr(totalExpenses)}</strong>
                    </div>
                    <div className="trip-detail-km-bar__item">
                        <span className="trip-detail-km-bar__label">
                            Trip mileage
                        </span>
                        <strong>{mileageLabel}</strong>
                    </div>
                    <div className="trip-detail-km-bar__item">
                        <span className="trip-detail-km-bar__label">
                            Completion
                        </span>
                        <strong
                            className={cn(
                                allocationReady
                                    ? 'text-[#15803d]'
                                    : 'text-slate-600',
                            )}
                        >
                            {allocationReady ? 'Ready' : 'Blocked'}
                        </strong>
                    </div>
                </div>
            </div>
        </TripDetailCard>
    );
}

export function TripExpensesTable({
    headers,
    empty,
    children,
}: {
    headers: string[];
    empty: string;
    children: ReactNode;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="trip-compact-table min-w-full">
                <thead>
                    <tr>
                        {headers.map((header) => (
                            <th key={header}>{header}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
            {!children || (Array.isArray(children) && children.length === 0) ? (
                <p className="trip-compact-empty">{empty}</p>
            ) : null}
        </div>
    );
}
