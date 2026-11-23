import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PageShellProps = {
    title?: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    /** Prefer full content width for operational pages. */
    width?: 'full' | 'wide' | 'form';
};

const widthClass = {
    full: 'max-w-none',
    wide: 'max-w-7xl',
    form: 'max-w-6xl',
} as const;

/**
 * Consistent page chrome for Management / Branch / Admin surfaces.
 * Full-width by default to avoid skeleton-like empty space.
 */
export function PageShell({
    title,
    description,
    actions,
    children,
    className,
    width = 'full',
}: PageShellProps) {
    return (
        <div
            className={cn(
                'mx-auto flex w-full flex-1 flex-col gap-5 bg-[var(--brand-surface-muted)] p-4 md:gap-6 md:p-6',
                widthClass[width],
                className,
            )}
        >
            {(title || actions) && (
                <div className="rounded-xl border border-border bg-card p-4 shadow-xs md:p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0 space-y-1">
                            <div
                                className="mb-2 h-1 w-16 rounded-full"
                                style={{
                                    backgroundImage: 'var(--brand-gradient)',
                                }}
                                aria-hidden
                            />
                            {title ? (
                                <h1 className="text-xl font-semibold tracking-tight text-foreground md:text-2xl">
                                    {title}
                                </h1>
                            ) : null}
                            {description ? (
                                <p className="max-w-3xl text-sm text-muted-foreground">
                                    {description}
                                </p>
                            ) : null}
                        </div>
                        {actions ? (
                            <div className="flex flex-wrap items-center gap-2">
                                {actions}
                            </div>
                        ) : null}
                    </div>
                </div>
            )}
            {children}
        </div>
    );
}

type FormSectionProps = {
    title: string;
    description?: string;
    children: ReactNode;
    className?: string;
    /** Override grid columns for dense operational forms. */
    columns?: 1 | 2 | 3;
};

export function FormSection({
    title,
    description,
    children,
    className,
    columns = 2,
}: FormSectionProps) {
    const gridClass =
        columns === 1
            ? 'grid gap-4'
            : columns === 3
              ? 'grid gap-4 md:grid-cols-2 xl:grid-cols-3'
              : 'grid gap-4 md:grid-cols-2';

    return (
        <section
            className={cn(
                'space-y-4 overflow-hidden rounded-xl border border-border bg-card shadow-xs',
                className,
            )}
        >
            <div className="border-b border-border bg-gradient-to-r from-[#2FB36F]/10 via-[#2D8CFF]/5 to-transparent px-4 py-3 md:px-5">
                <h2 className="text-sm font-semibold tracking-tight text-foreground md:text-base">
                    {title}
                </h2>
                {description ? (
                    <p className="mt-0.5 text-xs text-muted-foreground md:text-sm">
                        {description}
                    </p>
                ) : null}
            </div>
            <div className={cn('px-4 pb-4 md:px-5 md:pb-5', gridClass)}>
                {children}
            </div>
        </section>
    );
}
