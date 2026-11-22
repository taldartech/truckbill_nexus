import { Head, Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import type { WorkspaceBranch } from '@/types/workspace';

type BranchDashboardProps = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    isOwner: boolean;
    modules: Array<{ key: string; label: string; href: string }>;
    siblingBranches: WorkspaceBranch[];
    links: {
        settings: string;
        owner_panel: string | null;
        select_branch: string | null;
    };
};

export default function BranchDashboard({
    company,
    branch,
    isOwner,
    modules,
    links,
}: BranchDashboardProps) {
    return (
        <>
            <Head title={`${branch.name} · Branch Panel`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Branch Panel
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {branch.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span>{company.name}</span>
                            <WorkspaceStatusBadge status={company.status} />
                            <WorkspaceStatusBadge status={branch.status} />
                        </div>
                    </div>
                    <div className="flex w-full max-w-sm flex-col gap-2">
                        <CompanySwitcher />
                        {isOwner && links.owner_panel ? (
                            <Button asChild variant="secondary">
                                <Link href={links.owner_panel}>
                                    Return to Owner Panel
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </div>

                <nav
                    aria-label="Branch navigation"
                    className="flex flex-wrap gap-2"
                >
                    <Button asChild size="sm" variant="secondary">
                        <Link
                            href={`/branch/companies/${company.id}/branches/${branch.id}`}
                        >
                            Dashboard
                        </Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link href={links.settings}>Settings</Link>
                    </Button>
                    {links.select_branch ? (
                        <Button asChild size="sm" variant="outline">
                            <Link href={links.select_branch}>
                                Switch branch
                            </Link>
                        </Button>
                    ) : null}
                </nav>

                {modules.length === 0 ? (
                    <ShellEmptyState
                        title="No operational modules available"
                        description="Broker, OwnFleet, and Partload product screens are not part of Phase 2. Unavailable modules are hidden rather than shown as disabled."
                    />
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {modules.map((module) => (
                            <li key={module.key}>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full justify-start"
                                >
                                    <Link href={module.href}>
                                        {module.label}
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
