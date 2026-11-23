import { Head, Link } from '@inertiajs/react';
import { ArrowRightLeft, LayoutGrid, Settings } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import type { WorkspaceBranch } from '@/types/workspace';

type BranchDashboardProps = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    isOwner: boolean;
    modules: Array<{ key: string; label: string; href: string }>;
    siblingBranches: WorkspaceBranch[];
    links: {
        dashboard: string;
        settings: string;
        owner_panel: string | null;
        select_branch: string | null;
    };
};

export default function BranchDashboard({
    branch,
    modules,
    links,
}: BranchDashboardProps) {
    return (
        <>
            <Head title="Operations" />
            <PageShell
                title="Dashboard"
                description={`${branch.name} — branch operations and modules.`}
                actions={
                    <>
                        <AppIconAction
                            icon={Settings}
                            label="Branch settings"
                            href={links.settings}
                            variant="outline"
                            size="default"
                            className="gap-2 px-3"
                        >
                            <span className="text-sm font-medium">
                                Settings
                            </span>
                        </AppIconAction>
                        {links.select_branch ? (
                            <Button asChild size="sm" variant="secondary">
                                <Link href={links.select_branch}>
                                    <ArrowRightLeft className="size-4" />
                                    Switch branch
                                </Link>
                            </Button>
                        ) : null}
                    </>
                }
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <MetricCard
                        label="Modules"
                        value={modules.length}
                        hint="Shortcuts from your enabled workspace modules"
                        icon={LayoutGrid}
                        tone="green"
                    />
                </div>

                {modules.length > 0 ? (
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {modules.map((module) => (
                            <li key={module.key}>
                                <Link
                                    href={module.href}
                                    className="flex h-full flex-col gap-2 rounded-xl border border-border bg-card px-4 py-4 text-sm font-medium shadow-xs transition-colors hover:border-primary/40 hover:bg-muted/30"
                                >
                                    <span className="text-foreground">
                                        {module.label}
                                    </span>
                                    <span className="text-xs font-normal text-muted-foreground">
                                        Open module →
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <ShellEmptyState
                        title="Ready for operations"
                        description="Use Masters and Enquiries from the sidebar to begin."
                    />
                )}
            </PageShell>
        </>
    );
}
