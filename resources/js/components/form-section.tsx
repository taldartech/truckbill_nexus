import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type FormSectionProps = {
    title: string;
    description?: string;
    children: ReactNode;
    /** Optional compact footer (e.g. actions) inside the section. */
    footer?: ReactNode;
} & Omit<HTMLAttributes<HTMLElement>, 'title'>;

/**
 * Compact form grouping with subtle border/shadow — not oversized empty cards.
 */
export function FormSection({
    title,
    description,
    children,
    className,
    footer,
    ...props
}: FormSectionProps) {
    return (
        <section
            className={cn(
                'space-y-3 rounded-lg border border-border bg-card p-4 shadow-xs',
                className,
            )}
            {...props}
        >
            <header className="space-y-0.5">
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    {title}
                </h2>
                {description ? (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </header>
            <div className="space-y-3">{children}</div>
            {footer ? (
                <div className="border-t border-border pt-3">{footer}</div>
            ) : null}
        </section>
    );
}
