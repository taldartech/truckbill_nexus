import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type DetailTab = {
    id: string;
    label: string;
    count?: number;
    tone?: 'default' | 'warning' | 'danger';
};

type DetailTabsProps = {
    tabs: DetailTab[];
    activeId: string;
    onChange: (id: string) => void;
    children: ReactNode;
    className?: string;
    'aria-label'?: string;
};

/**
 * Business detail-page tab strip for operational records (Trip, LR, Lead, Invoice).
 */
export function DetailTabs({
    tabs,
    activeId,
    onChange,
    children,
    className,
    'aria-label': ariaLabel = 'Detail sections',
}: DetailTabsProps) {
    return (
        <div className={cn('space-y-4', className)}>
            <div
                role="tablist"
                aria-label={ariaLabel}
                className="flex flex-wrap gap-1 rounded-xl border border-border bg-card p-1.5 shadow-xs"
            >
                {tabs.map((tab) => {
                    const active = tab.id === activeId;
                    return (
                        <button
                            key={tab.id}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            id={`detail-tab-${tab.id}`}
                            aria-controls={`detail-panel-${tab.id}`}
                            onClick={() => onChange(tab.id)}
                            className={cn(
                                'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition-colors',
                                active
                                    ? 'bg-[color-mix(in_srgb,var(--brand-green)_14%,white)] text-foreground shadow-xs ring-1 ring-[color-mix(in_srgb,var(--brand-green)_35%,transparent)]'
                                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                tab.tone === 'warning' && !active
                                    ? 'text-amber-700'
                                    : null,
                                tab.tone === 'danger' && !active
                                    ? 'text-destructive'
                                    : null,
                            )}
                        >
                            {tab.label}
                            {typeof tab.count === 'number' ? (
                                <span
                                    className={cn(
                                        'rounded-md px-1.5 py-0.5 text-xs tabular-nums',
                                        active
                                            ? 'bg-background/80 text-foreground'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {tab.count}
                                </span>
                            ) : null}
                        </button>
                    );
                })}
            </div>
            {children}
        </div>
    );
}

type DetailTabPanelProps = {
    id: string;
    activeId: string;
    children: ReactNode;
    className?: string;
};

export function DetailTabPanel({
    id,
    activeId,
    children,
    className,
}: DetailTabPanelProps) {
    const active = id === activeId;

    return (
        <div
            role="tabpanel"
            id={`detail-panel-${id}`}
            aria-labelledby={`detail-tab-${id}`}
            hidden={!active}
            className={cn(active ? 'space-y-4' : undefined, className)}
        >
            {active ? children : null}
        </div>
    );
}
