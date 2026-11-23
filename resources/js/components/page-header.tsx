import type { ReactNode } from 'react';

type Props = {
    title: string;
    description?: string | null;
    actions?: ReactNode;
};

/**
 * Compact page chrome: title + optional actions.
 * Do not pass company/branch/status here — use AppTopbar context.
 */
export function PageHeader({
    title,
    description = null,
    actions = null,
}: Props) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
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
    );
}
