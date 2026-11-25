import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function FbDetailPage({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('fb-detail-page app-index-page', className)}>
            {children}
        </div>
    );
}

export function FbDetailLayout({
    main,
    side,
}: {
    main: ReactNode;
    side: ReactNode;
}) {
    return (
        <div className="row g-3 grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div className="fb-detail-main space-y-3">{main}</div>
            <div className="fb-detail-side space-y-3">{side}</div>
        </div>
    );
}

export function FbDetailCard({
    title,
    badges,
    actions,
    children,
    className,
}: {
    title: ReactNode;
    badges?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'card overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-xs',
                className,
            )}
        >
            <div className="card-header fb-detail-card-header flex flex-wrap items-center justify-between gap-2 border-b border-[#e2e8f0] px-4 py-3">
                <strong className="fb-detail-card-title flex flex-wrap items-center gap-2 text-[0.95rem] font-bold text-slate-800">
                    {title}
                    {badges}
                </strong>
                {actions ? (
                    <div className="fb-detail-actions flex flex-wrap items-center gap-1.5">
                        {actions}
                    </div>
                ) : null}
            </div>
            <div className="card-body">{children}</div>
        </div>
    );
}

export function FbInfoGrid({ children }: { children: ReactNode }) {
    return <div className="fb-info-grid px-4 py-3">{children}</div>;
}

export function FbInfoItem({
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
                'fb-info-grid__item',
                full && 'fb-info-grid__item--full',
                span2 && 'fb-info-grid__item--span-2',
            )}
        >
            <span className="fb-info-grid__label">{label}</span>
            <div
                className={cn(
                    'fb-info-grid__value',
                    accent && 'fb-info-grid__value--accent',
                )}
            >
                {value ?? '—'}
            </div>
            {sub ? <div className="fb-info-grid__sub">{sub}</div> : null}
        </div>
    );
}

export function FbRouteCard({
    from,
    to,
    customer,
    payee,
    lrAmount,
    rate,
}: {
    from: string;
    to: string;
    customer: string;
    payee: string;
    lrAmount?: string | null;
    rate?: string | null;
}) {
    return (
        <div className="card overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-xs">
            <div className="fb-route-header flex flex-wrap items-center justify-between gap-2 border-b border-[#e2e8f0] px-4 py-3">
                <strong className="text-[0.95rem] font-bold text-slate-800">
                    Linked Bilty &amp; Route
                </strong>
                <div className="fb-route-header__totals flex flex-wrap gap-3 text-xs text-slate-600">
                    {lrAmount ? <span>LR amount {lrAmount}</span> : null}
                    {rate ? <span>Rate {rate}</span> : null}
                </div>
            </div>
            <div className="fb-route-preview px-4 py-3">
                <div className="fb-route-facts grid gap-3 sm:grid-cols-2">
                    <FbInfoItem label="From" value={from} />
                    <FbInfoItem label="To" value={to} />
                    <FbInfoItem label="Customer" value={customer} />
                    <FbInfoItem label="Payee" value={payee} />
                </div>
            </div>
        </div>
    );
}

export function FbFinancialSummary({
    balanceDue,
    cleared,
    trAmount,
    deductions,
    balance,
    rows,
}: {
    balanceDue: boolean;
    cleared: boolean;
    trAmount: string;
    deductions: string;
    balance: string;
    rows: Array<{ label: string; value: string }>;
}) {
    const hirePct = 70;
    const deductionPct = 40;

    return (
        <div className="card fb-financial-summary overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-xs">
            <div className="fb-financial-summary__header px-4 py-3">
                <div className="fb-financial-summary__title-row">
                    <div>
                        <div className="fb-financial-summary__title">
                            Financial Summary
                        </div>
                        <div className="fb-financial-summary__subtitle">
                            Hire vs deductions
                        </div>
                    </div>
                    <span
                        className={cn(
                            'fb-financial-summary__badge',
                            cleared ? 'is-clear' : 'is-due',
                        )}
                    >
                        {cleared ? 'Cleared' : 'Balance due'}
                        <strong className="ml-1">{balance}</strong>
                    </span>
                </div>
            </div>
            <div className="fb-financial-summary__body px-4 py-3">
                <div className="fb-financial-summary__stats">
                    <div className="fb-financial-summary__stat">
                        <span className="fb-financial-summary__stat-label">
                            TR Amount
                        </span>
                        <span className="fb-financial-summary__stat-value">
                            {trAmount}
                        </span>
                    </div>
                    <div className="fb-financial-summary__stat">
                        <span className="fb-financial-summary__stat-label">
                            Deductions
                        </span>
                        <span className="fb-financial-summary__stat-value fb-financial-summary__stat-value--muted">
                            {deductions}
                        </span>
                    </div>
                    <div className="fb-financial-summary__stat fb-financial-summary__stat--result">
                        <span className="fb-financial-summary__stat-label">
                            Balance
                        </span>
                        <span
                            className={cn(
                                'fb-financial-summary__stat-value',
                                balanceDue ? 'is-due' : 'is-clear',
                            )}
                        >
                            {balance}
                        </span>
                    </div>
                </div>
                <div className="fb-financial-summary__bar" aria-hidden>
                    <span
                        className="fb-financial-summary__bar-hire"
                        style={{ width: `${hirePct}%` }}
                    />
                    <span
                        className="fb-financial-summary__bar-deduction"
                        style={{ width: `${deductionPct}%` }}
                    />
                </div>
                <div className="fb-financial-summary__rows mt-3 space-y-1.5">
                    {rows.map((row) => (
                        <div
                            key={row.label}
                            className="flex items-center justify-between gap-2 text-xs"
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

export function FbCompactTable({
    title,
    count,
    headers,
    empty,
    children,
}: {
    title: string;
    count?: number;
    headers: string[];
    empty: string;
    children: ReactNode;
}) {
    return (
        <div className="card fb-compact-card overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-xs">
            <div className="card-header flex items-center justify-between border-b border-[#e2e8f0] px-4 py-3">
                <strong className="text-[0.95rem] font-bold text-slate-800">
                    {title}
                </strong>
                {typeof count === 'number' ? (
                    <span className="fb-compact-card__count text-xs text-slate-500">
                        {count}
                    </span>
                ) : null}
            </div>
            <div className="overflow-x-auto">
                <table className="fb-compact-table min-w-full text-sm">
                    <thead>
                        <tr>
                            {headers.map((header) => (
                                <th
                                    key={header}
                                    className="px-3 py-2 text-left text-xs font-bold text-slate-500"
                                >
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>{children}</tbody>
                </table>
                {!children ||
                (Array.isArray(children) && children.length === 0) ? (
                    <p className="fb-compact-empty px-4 py-6 text-center text-sm text-slate-500">
                        {empty}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
