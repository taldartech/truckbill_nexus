import { Package } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type StepTone = 'receipt' | 'route' | 'goods' | 'optional';

const headToneClass: Record<StepTone, string> = {
    receipt: '',
    route: 'lrf-section__head--green',
    goods: 'lrf-section__head--summary',
    optional: 'lrf-section__head--plain',
};

const stepToneClass: Record<StepTone, string> = {
    receipt: '',
    route: 'lrf-step-num--green',
    goods: 'lrf-step-num--summary',
    optional: 'lrf-step-num--gray',
};

export function LrfPage({
    title,
    subtitle,
    backHref,
    backLabel = 'Back',
    actions,
    children,
    className,
}: {
    title: string;
    subtitle?: string;
    backHref?: string;
    backLabel?: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('lrf-page', className)}>
            <div className="lrf-page__toolbar">
                <div className="lrf-page__toolbar-left">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="lrf-page__toolbar-title">{title}</h1>
                        {backHref ? (
                            <a
                                href={backHref}
                                className="lrf-toolbar-back-btn inline-flex items-center rounded-md border border-slate-300 bg-white px-2.5 text-slate-700 hover:bg-slate-50"
                            >
                                ← {backLabel}
                            </a>
                        ) : null}
                    </div>
                    {subtitle ? (
                        <p className="lrf-page__toolbar-sub">{subtitle}</p>
                    ) : null}
                </div>
                {actions ? (
                    <div className="lrf-page__toolbar-actions">{actions}</div>
                ) : null}
            </div>
            {children}
        </div>
    );
}

export function LrfSection({
    step,
    title,
    description,
    tone,
    headRight,
    children,
    className,
    collapsible,
    defaultOpen = true,
}: {
    step: number;
    title: string;
    description: string;
    tone: StepTone;
    headRight?: ReactNode;
    children: ReactNode;
    className?: string;
    collapsible?: boolean;
    defaultOpen?: boolean;
}) {
    if (collapsible) {
        return (
            <details
                className={cn(
                    'lrf-section lrf-section--collapsible',
                    className,
                )}
                open={defaultOpen}
            >
                <summary
                    className={cn(
                        'lrf-section__head lrf-section__head--toggle',
                        headToneClass[tone],
                    )}
                >
                    <div className="lrf-section__head-left">
                        <div
                            className={cn('lrf-step-num', stepToneClass[tone])}
                            aria-hidden
                        >
                            {step}
                        </div>
                        <div>
                            <h2 className="lrf-section__title">{title}</h2>
                            <p className="lrf-section__desc">{description}</p>
                        </div>
                    </div>
                    {headRight ? (
                        <div className="lrf-section__head-right">
                            {headRight}
                        </div>
                    ) : null}
                </summary>
                <div className="lrf-section__body">{children}</div>
            </details>
        );
    }

    return (
        <section className={cn('lrf-section', className)}>
            <div className={cn('lrf-section__head', headToneClass[tone])}>
                <div className="lrf-section__head-left">
                    <div
                        className={cn('lrf-step-num', stepToneClass[tone])}
                        aria-hidden
                    >
                        {step}
                    </div>
                    <div>
                        <h2 className="lrf-section__title">{title}</h2>
                        <p className="lrf-section__desc">{description}</p>
                    </div>
                </div>
                {headRight ? (
                    <div className="lrf-section__head-right">{headRight}</div>
                ) : null}
            </div>
            <div className="lrf-section__body">{children}</div>
        </section>
    );
}

export function LrfReceiptCard({
    title,
    children,
    className,
    vehicle,
}: {
    title: string;
    children: ReactNode;
    className?: string;
    vehicle?: boolean;
}) {
    return (
        <div
            className={cn(
                'lrf-receipt-card',
                vehicle && 'lrf-receipt-card--vehicle',
                className,
            )}
        >
            <div className="lrf-receipt-card__title">{title}</div>
            {children}
        </div>
    );
}

export function LrfPartyMetaGrid({
    items,
}: {
    items: Array<{ label: string; value: string }>;
}) {
    return (
        <div className="lrf-party-meta">
            {items.map((item) => {
                const empty = !item.value || item.value === '—';
                return (
                    <div key={item.label} className="lrf-party-meta__item">
                        <span className="lrf-party-meta__label">
                            {item.label}
                        </span>
                        <span
                            className={cn(
                                'lrf-party-meta__value',
                                empty && 'is-empty',
                            )}
                        >
                            {item.value || '—'}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

export function LrfRouteConnector({
    routeHint,
    ready,
}: {
    routeHint: string;
    ready?: boolean;
}) {
    return (
        <div
            className={cn('lrf-goods-lane', ready ? 'is-ready' : 'is-partial')}
            aria-hidden={false}
        >
            <div className="lrf-goods-lane__track">
                <span className="lrf-goods-lane__dot lrf-goods-lane__dot--start" />
                <span className="lrf-goods-lane__line" />
                <span className="lrf-goods-lane__cargo">
                    <Package className="size-3.5" aria-hidden />
                </span>
                <span className="lrf-goods-lane__line" />
                <span className="lrf-goods-lane__dot lrf-goods-lane__dot--end" />
            </div>
            <p className="lrf-goods-lane__label" id="lrRouteFlowLabel">
                Route
            </p>
            <p className="lrf-goods-lane__sub" id="lrRouteFlowSub">
                {routeHint}
            </p>
        </div>
    );
}

export function LrfPartyCard({
    badge,
    badgeTone,
    title,
    children,
    footer,
}: {
    badge: string;
    badgeTone: 'from' | 'to';
    title?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'lrf-party-card',
                badgeTone === 'from'
                    ? 'lrf-party-card--from'
                    : 'lrf-party-card--to',
            )}
        >
            <div className="lrf-party-card__badge">{badge}</div>
            {title ? (
                <div className="lrf-party-card__title">{title}</div>
            ) : null}
            {children}
            {footer}
        </div>
    );
}

export function LrfPayPills({
    value,
    onChange,
    disabled,
}: {
    value: 'owner' | 'agency' | '';
    onChange: (next: 'owner' | 'agency') => void;
    disabled?: boolean;
}) {
    return (
        <div className="lrf-pay-pills" role="group" aria-label="Pay to">
            {(
                [
                    { id: 'owner' as const, label: 'Truck Owner' },
                    { id: 'agency' as const, label: 'Agency' },
                ] as const
            ).map((pill) => (
                <button
                    key={pill.id}
                    type="button"
                    disabled={disabled}
                    onClick={() => onChange(pill.id)}
                    className={cn(
                        'lrf-pay-pill w-1/2',
                        value === pill.id && 'is-active',
                    )}
                >
                    {pill.label}
                </button>
            ))}
        </div>
    );
}

export function LrfInnerPanel({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="lrf-inner-panel">
            <div className="lrf-inner-panel__head">
                <h3 className="lrf-inner-panel__title">{title}</h3>
                {action}
            </div>
            {children}
        </div>
    );
}

export function LrfDriverTdsSummary({
    driverName,
    tdsLabel,
}: {
    driverName: string | null | undefined;
    tdsLabel: string;
}) {
    return (
        <div
            className="lrf-truck-summary lrf-truck-summary--field"
            role="status"
        >
            {driverName ? (
                <>
                    <span className="lrf-truck-summary__item">
                        <span className="lrf-truck-summary__k">Driver</span>
                        <span className="lrf-truck-summary__v">
                            {driverName}
                        </span>
                    </span>
                    <span className="lrf-truck-summary__sep" aria-hidden>
                        ·
                    </span>
                </>
            ) : null}
            <span className="lrf-truck-summary__item">
                <span className="lrf-truck-summary__k">TDS</span>
                <span className="lrf-truck-summary__v">{tdsLabel}</span>
            </span>
        </div>
    );
}

export function LrfSummaryMetrics({
    items,
}: {
    items: Array<{ name: string; value: string; hero?: boolean }>;
}) {
    return (
        <div className="lrf-summary-metrics">
            {items.map((item) => (
                <div
                    key={item.name}
                    className={cn(
                        'lrf-metric',
                        item.hero && 'lrf-metric--hero',
                    )}
                >
                    <div>
                        <span className="lrf-metric__name">{item.name}</span>
                        <span className="lrf-metric__val">{item.value}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}

export function LrfActions({ children }: { children: ReactNode }) {
    return <div className="lrf-actions">{children}</div>;
}

export function LrfLinkedHero({
    biltyNumber,
    routeFrom,
    routeTo,
    payeeChip,
    kpis,
    parties,
    vehicleRoute,
}: {
    biltyNumber: string;
    routeFrom: string;
    routeTo: string;
    payeeChip?: string | null;
    kpis: Array<{ label: string; value: ReactNode; accent?: boolean }>;
    parties: Array<{ label: string; value: ReactNode }>;
    vehicleRoute: Array<{ label: string; value: ReactNode }>;
}) {
    return (
        <div className="lrf-section lrf-linked">
            <div className="lrf-linked__head">
                <div className="lrf-linked__head-main">
                    <div className="lrf-linked__eyebrow">Linked Bilty</div>
                    <div className="lrf-linked__title-row">
                        <span className="lrf-linked__lr">{biltyNumber}</span>
                        {payeeChip ? (
                            <span className="lrf-chip lrf-chip--info">
                                {payeeChip}
                            </span>
                        ) : null}
                    </div>
                </div>
                <div className="lrf-linked__route-pill" title="Route">
                    <span>{routeFrom}</span>
                    <span aria-hidden>→</span>
                    <span>{routeTo}</span>
                </div>
            </div>
            <div className="lrf-section__body">
                <div className="lrf-linked__kpis">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.label}
                            className={cn(
                                'lrf-linked__kpi',
                                kpi.accent && 'lrf-linked__kpi--accent',
                            )}
                        >
                            <span className="lrf-linked__kpi-label">
                                {kpi.label}
                            </span>
                            <span className="lrf-linked__kpi-value">
                                {kpi.value}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="lrf-linked__panels">
                    <div className="lrf-linked__panel">
                        <div className="lrf-linked__panel-title">Parties</div>
                        <div className="lrf-linked__facts">
                            {parties.map((fact) => (
                                <div
                                    key={fact.label}
                                    className="lrf-linked__fact"
                                >
                                    <span className="lrf-linked__fact-label">
                                        {fact.label}
                                    </span>
                                    <span className="lrf-linked__fact-value">
                                        {fact.value}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="lrf-linked__panel">
                        <div className="lrf-linked__panel-title">
                            Vehicle &amp; Route
                        </div>
                        <div className="lrf-linked__facts">
                            {vehicleRoute.map((fact) => (
                                <div
                                    key={fact.label}
                                    className="lrf-linked__fact"
                                >
                                    <span className="lrf-linked__fact-label">
                                        {fact.label}
                                    </span>
                                    <span className="lrf-linked__fact-value">
                                        {fact.value}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

/** @deprecated Prefer structural lrf classes; kept for gradual migration. */
export const lrfSectionShell = 'lrf-section';
