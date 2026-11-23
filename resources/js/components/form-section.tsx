import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type FormSectionProps = {
    title: string;
    description?: string;
    children: ReactNode;
    /** Optional compact footer (e.g. actions) inside the section. */
    footer?: ReactNode;
    columns?: 1 | 2 | 3;
} & Omit<HTMLAttributes<HTMLElement>, 'title'>;

/**
 * Compact form grouping with brand accent header for operational forms.
 */
export function FormSection({
    title,
    description,
    children,
    className,
    footer,
    columns = 1,
    ...props
}: FormSectionProps) {
    const gridClass =
        columns === 1
            ? 'space-y-3'
            : columns === 3
              ? 'grid gap-3 md:grid-cols-2 xl:grid-cols-3'
              : 'grid gap-3 md:grid-cols-2';

    return (
        <section
            className={cn(
                'overflow-hidden rounded-xl border border-border bg-card shadow-xs',
                className,
            )}
            {...props}
        >
            <header className="border-b border-border bg-gradient-to-r from-[#2FB36F]/10 via-[#2D8CFF]/5 to-transparent px-4 py-3">
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    {title}
                </h2>
                {description ? (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </header>
            <div className={cn('p-4', gridClass)}>{children}</div>
            {footer ? (
                <div className="border-t border-border bg-muted/30 px-4 py-3">
                    {footer}
                </div>
            ) : null}
        </section>
    );
}
