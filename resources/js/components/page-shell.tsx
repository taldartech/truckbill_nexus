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
    form: 'max-w-5xl',
} as const;

/**
 * Consistent page chrome for Management / Branch / Admin surfaces.
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
                'mx-auto flex w-full flex-1 flex-col gap-6 p-4 md:p-6',
                widthClass[width],
                className,
            )}
        >
            {(title || actions) && (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0 space-y-1">
                        {title ? (
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                        ) : null}
                        {description ? (
                            <p className="text-sm text-muted-foreground">
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
};

export function FormSection({
    title,
    description,
    children,
    className,
}: FormSectionProps) {
    return (
        <section
            className={cn(
                'space-y-4 rounded-xl border border-border bg-card p-4 md:p-6',
                className,
            )}
        >
            <div className="space-y-1">
                <h2 className="text-base font-semibold tracking-tight">
                    {title}
                </h2>
                {description ? (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-4 md:grid-cols-2">{children}</div>
        </section>
    );
}
