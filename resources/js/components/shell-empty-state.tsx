import type { ReactNode } from 'react';

type ShellEmptyStateProps = {
    title: string;
    description: string;
    action?: ReactNode;
};

/**
 * Technical shell empty state — no product workflow content.
 */
export default function ShellEmptyState({
    title,
    description,
    action,
}: ShellEmptyStateProps) {
    return (
        <div
            role="status"
            className="flex min-h-64 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border bg-muted/40 px-6 py-12 text-center"
        >
            <div
                aria-hidden
                className="bg-brand-gradient h-1.5 w-16 rounded-full"
            />
            <h2 className="text-lg font-semibold text-foreground">{title}</h2>
            <p className="max-w-md text-sm text-muted-foreground">
                {description}
            </p>
            {action}
        </div>
    );
}
