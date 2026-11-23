import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Crumb = { label: string; href?: string };

type Props = {
    eyebrow?: string;
    title: string;
    description?: string;
    breadcrumbs?: Crumb[];
    actions?: ReactNode;
};

export function OwnerPageHeader({
    eyebrow = 'Owner Panel',
    title,
    description,
    breadcrumbs,
    actions,
}: Props) {
    return (
        <div className="flex flex-col gap-4 border-b border-border/80 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 space-y-2">
                {breadcrumbs && breadcrumbs.length > 0 ? (
                    <nav
                        aria-label="Breadcrumb"
                        className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
                    >
                        {breadcrumbs.map((crumb, index) => (
                            <span
                                key={`${crumb.label}-${index}`}
                                className="inline-flex items-center gap-1"
                            >
                                {index > 0 ? (
                                    <span aria-hidden="true">/</span>
                                ) : null}
                                {crumb.href ? (
                                    <Link
                                        href={crumb.href}
                                        className="font-medium text-[#2D8CFF] underline-offset-4 hover:underline"
                                    >
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className="font-medium text-foreground">
                                        {crumb.label}
                                    </span>
                                )}
                            </span>
                        ))}
                    </nav>
                ) : (
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {eyebrow}
                    </p>
                )}
                <h1 className="text-2xl font-semibold tracking-tight text-foreground md:text-[1.75rem]">
                    {title}
                </h1>
                {description ? (
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            {actions ? (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            ) : null}
        </div>
    );
}
