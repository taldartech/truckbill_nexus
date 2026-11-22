import { Head, Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type OwnerDeferredProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    section: {
        key: string;
        title: string;
        message: string;
        status: string;
    };
    links: { dashboard: string };
};

export default function OwnerDeferred({
    company,
    ownerPanelScope,
    section,
    links,
}: OwnerDeferredProps) {
    return (
        <>
            <Head title={`${section.title} · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Owner Panel · {section.title}
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <WorkspaceStatusBadge status={company.status} />
                            <span className="text-muted-foreground">
                                Scope: {ownerPanelScope}
                            </span>
                        </div>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>

                <ShellEmptyState
                    title={`${section.title} not available in this release`}
                    description={section.message}
                    action={
                        <Button asChild variant="outline">
                            <Link href={links.dashboard}>
                                Back to Owner Dashboard
                            </Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}
