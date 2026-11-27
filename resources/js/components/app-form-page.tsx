import type { ReactNode } from 'react';
import { AppCardHeader } from '@/components/app-card-header';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { cn } from '@/lib/utils';

/**
 * Full-width operator form shell for Owner / Branch / Accounting create-edit pages.
 * Do not use for dialogs, auth screens, or compact quick-create sheets.
 */
export function AppFormPage({
    title,
    description,
    actions,
    children,
    className,
}: {
    title?: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    const body = (
        <div
            className={cn('app-form-page w-full min-w-0 space-y-6', className)}
        >
            {children}
        </div>
    );

    if (
        title === undefined &&
        description === undefined &&
        actions === undefined
    ) {
        return body;
    }

    return (
        <OwnerPageHeader
            title={title ?? ''}
            description={description}
            actions={actions}
        >
            {body}
        </OwnerPageHeader>
    );
}

/**
 * Bordered form card that uses available content width (no max-w-* clamps).
 */
export function AppFormCard({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'app-form-card w-full min-w-0 space-y-6 rounded-xl border border-border bg-card p-4 sm:p-6',
                className,
            )}
        >
            {children}
        </div>
    );
}

/**
 * Responsive field grid — 1 / 2 / 3 / 4 columns by breakpoint.
 */
export function AppFormGrid({
    children,
    cols = 2,
    columns,
    className,
}: {
    children: ReactNode;
    cols?: 2 | 3 | 4;
    /** @deprecated Prefer `cols`. */
    columns?: 2 | 3 | 4;
    className?: string;
}) {
    const resolved = columns ?? cols;

    return (
        <div
            className={cn(
                'grid gap-4',
                resolved === 2 && 'sm:grid-cols-2',
                resolved === 3 && 'sm:grid-cols-2 lg:grid-cols-3',
                resolved === 4 && 'sm:grid-cols-2 lg:grid-cols-4',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function AppFormSection({
    title,
    description,
    actions,
    children,
    className,
}: {
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('space-y-4', className)}>
            {title || description || actions ? (
                <AppCardHeader
                    title={title}
                    titleAs="h3"
                    titleClassName="text-sm font-semibold tracking-tight"
                    meta={description}
                    actions={actions}
                    actionsClassName="gap-2"
                />
            ) : null}
            {children}
        </section>
    );
}
