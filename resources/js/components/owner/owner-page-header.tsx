import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type Crumb = { label: string; href?: string };

type OwnerPageShellProps = {
    eyebrow?: string;
    title: string;
    description?: string;
    breadcrumbs?: Crumb[];
    actions?: ReactNode;
    children: ReactNode;
    width?: 'full' | 'wide' | 'form';
    className?: string;
};

function OwnerBreadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
    return (
        <nav
            aria-label="Breadcrumb"
            className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
        >
            {crumbs.map((crumb, index) => (
                <span
                    key={`${crumb.label}-${index}`}
                    className="inline-flex items-center gap-1"
                >
                    {index > 0 ? <span aria-hidden="true">/</span> : null}
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
    );
}

/**
 * Owner Panel page chrome — wraps premium PageShell with optional breadcrumbs.
 */
export function OwnerPageHeader({
    eyebrow: _eyebrow = 'Owner Panel',
    title,
    description,
    breadcrumbs,
    actions,
    children,
    width = 'full',
    className,
}: OwnerPageShellProps) {
    const shellDescription = description ?? _eyebrow;

    return (
        <PageShell
            title={title}
            description={shellDescription}
            actions={actions}
            width={width}
            className={className}
        >
            {breadcrumbs && breadcrumbs.length > 0 ? (
                <OwnerBreadcrumbs crumbs={breadcrumbs} />
            ) : null}
            {children}
        </PageShell>
    );
}

type CompanyScope = { name: string; status: string };

/**
 * Company-scoped Owner page chrome (settings, users, billing, branches, audit).
 */
export function CompanyOwnerPageShell({
    company,
    ownerPanelScope: _ownerPanelScope,
    section,
    actions,
    children,
    width = 'full',
    breadcrumbs,
}: {
    company: CompanyScope;
    ownerPanelScope: string;
    section: string;
    actions?: ReactNode;
    children: ReactNode;
    width?: 'full' | 'wide' | 'form';
    breadcrumbs?: Crumb[];
}) {
    return (
        <OwnerPageHeader
            title={company.name}
            description={section}
            breadcrumbs={breadcrumbs}
            width={width}
            actions={
                <>
                    <WorkspaceStatusBadge status={company.status} />
                    {actions}
                </>
            }
        >
            {children}
        </OwnerPageHeader>
    );
}
