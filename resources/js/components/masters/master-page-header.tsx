import { Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type Props = {
    eyebrow: string;
    title: string;
    company: { id: string; name: string; status: string };
    branch?: { id: string; name: string; status: string } | null;
    crumbs?: { label: string; href?: string }[];
};

export function MasterPageHeader({
    eyebrow,
    title,
    company,
    branch,
    crumbs = [],
}: Props) {
    return (
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div className="space-y-2">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {eyebrow}
                </p>
                {crumbs.length > 0 ? (
                    <nav
                        aria-label="Breadcrumb"
                        className="flex flex-wrap gap-2 text-sm text-muted-foreground"
                    >
                        {crumbs.map((crumb, index) => (
                            <span
                                key={`${crumb.label}-${index}`}
                                className="inline-flex items-center gap-2"
                            >
                                {index > 0 ? <span aria-hidden>/</span> : null}
                                {crumb.href ? (
                                    <Link
                                        href={crumb.href}
                                        className="hover:text-foreground"
                                    >
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className="text-foreground">
                                        {crumb.label}
                                    </span>
                                )}
                            </span>
                        ))}
                    </nav>
                ) : null}
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <WorkspaceStatusBadge status={company.status} />
                    <span>{company.name}</span>
                    {branch ? (
                        <>
                            <span aria-hidden>·</span>
                            <WorkspaceStatusBadge status={branch.status} />
                            <span>{branch.name}</span>
                        </>
                    ) : null}
                </div>
            </div>
            <div className="w-full max-w-sm">
                <CompanySwitcher />
            </div>
        </div>
    );
}
