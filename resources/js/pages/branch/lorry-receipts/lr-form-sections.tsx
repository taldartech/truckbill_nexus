import { ArrowRight, Package } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export const lrfSectionShell =
    'overflow-hidden rounded-xl border border-border bg-card shadow-xs';

type StepTone = 'receipt' | 'route' | 'goods';

const stepToneClass: Record<StepTone, string> = {
    receipt:
        'border-b border-border bg-gradient-to-br from-sky-100/80 via-background to-muted/30',
    route: 'border-b border-[#badbcc]/60 bg-gradient-to-br from-[#2FB36F]/15 via-background to-muted/30',
    goods: 'border-b border-amber-200/60 bg-gradient-to-br from-amber-100/60 via-background to-muted/30',
};

const stepBadgeClass: Record<StepTone, string> = {
    receipt: 'bg-[#2D8CFF] shadow-[0_1px_2px_rgba(45,140,255,0.25)]',
    route: 'bg-[#2FB36F] shadow-[0_1px_2px_rgba(47,179,111,0.25)]',
    goods: 'bg-amber-500 shadow-[0_1px_2px_rgba(245,158,11,0.25)]',
};

export function LrfSection({
    step,
    title,
    description,
    tone,
    headRight,
    children,
    className,
}: {
    step: number;
    title: string;
    description: string;
    tone: StepTone;
    headRight?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn(lrfSectionShell, className)}>
            <header
                className={cn(
                    'flex flex-wrap items-center justify-between gap-3 px-4 py-3',
                    stepToneClass[tone],
                )}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <div
                        className={cn(
                            'flex size-[30px] shrink-0 items-center justify-center rounded-full text-[0.78rem] font-extrabold text-white',
                            stepBadgeClass[tone],
                        )}
                        aria-hidden
                    >
                        {step}
                    </div>
                    <div className="min-w-0">
                        <h2 className="text-[0.9rem] leading-tight font-bold text-slate-800">
                            {title}
                        </h2>
                        <p className="mt-0.5 text-[0.72rem] text-muted-foreground">
                            {description}
                        </p>
                    </div>
                </div>
                {headRight ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {headRight}
                    </div>
                ) : null}
            </header>
            <div className="px-4 py-3.5 md:px-4 md:py-4">{children}</div>
        </section>
    );
}

export function LrfReceiptCard({
    title,
    children,
    className,
}: {
    title: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'space-y-3 rounded-lg border border-border/80 bg-muted/20 p-3',
                className,
            )}
        >
            <p className="text-xs font-semibold tracking-wide text-slate-700 uppercase">
                {title}
            </p>
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
        <div className="grid gap-1.5 text-xs">
            {items.map((item) => (
                <div
                    key={item.label}
                    className="flex items-baseline justify-between gap-3 border-b border-border/40 pb-1 last:border-0"
                >
                    <span className="shrink-0 text-muted-foreground">
                        {item.label}
                    </span>
                    <span className="min-w-0 truncate text-right font-medium text-foreground">
                        {item.value || '—'}
                    </span>
                </div>
            ))}
        </div>
    );
}

export function LrfRouteConnector({ routeHint }: { routeHint: string }) {
    return (
        <div className="hidden flex-col items-center justify-center gap-2 px-2 text-center md:flex">
            <div className="relative flex w-full max-w-[7rem] flex-col items-center py-2">
                <span
                    className="size-2 rounded-full bg-[#2FB36F]"
                    aria-hidden
                />
                <span className="my-1 h-10 w-px bg-border" aria-hidden />
                <span className="flex size-7 items-center justify-center rounded-full border border-[#2FB36F]/40 bg-[#2FB36F]/10 text-[#2FB36F]">
                    <Package className="size-3.5" aria-hidden />
                </span>
                <span className="my-1 h-10 w-px bg-border" aria-hidden />
                <span
                    className="size-2 rounded-full bg-[#2D8CFF]"
                    aria-hidden
                />
                <ArrowRight
                    className="absolute top-1/2 -right-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden
                />
            </div>
            <p className="max-w-[8rem] text-[0.7rem] leading-snug text-muted-foreground">
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
    title: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-1 flex-col gap-3 rounded-lg border border-border/80 bg-card p-3 shadow-xs">
            <span
                className={cn(
                    'inline-flex w-fit rounded px-1.5 py-0.5 text-[0.65rem] font-bold tracking-wider uppercase',
                    badgeTone === 'from'
                        ? 'bg-[#2D8CFF]/15 text-[#2D8CFF]'
                        : 'bg-[#2FB36F]/15 text-[#2FB36F]',
                )}
            >
                {badge}
            </span>
            <div className="text-sm font-semibold text-foreground">{title}</div>
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
        <div
            className="flex gap-1 rounded-md border border-border bg-muted/30 p-0.5"
            role="group"
            aria-label="Pay to"
        >
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
                        'min-h-9 flex-1 rounded px-2 text-xs font-semibold whitespace-nowrap transition-colors',
                        value === pill.id
                            ? 'bg-[#2FB36F] text-white shadow-xs'
                            : 'text-muted-foreground hover:bg-background',
                        disabled && 'cursor-not-allowed opacity-60',
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
        <div className="rounded-lg border border-border/70 bg-background/50 p-3">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold tracking-tight text-foreground uppercase">
                    {title}
                </h3>
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
            className="flex min-h-9 flex-wrap items-center gap-x-2 gap-y-1 rounded-md border border-input bg-muted/20 px-3 py-2 text-sm"
            role="status"
        >
            {driverName ? (
                <>
                    <span className="text-muted-foreground">Driver</span>
                    <span className="font-medium">{driverName}</span>
                    <span className="text-muted-foreground" aria-hidden>
                        ·
                    </span>
                </>
            ) : null}
            <span className="text-muted-foreground">TDS</span>
            <span className="font-medium tabular-nums">{tdsLabel}</span>
        </div>
    );
}
