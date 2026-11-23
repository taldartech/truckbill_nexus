import { Head, Link } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
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
    modules,
    links,
}: BranchDashboardProps) {
    return (
        <>
            <Head title="Operations" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <MasterPageHeader title="Dashboard" />
                    <div className="flex flex-wrap gap-2">
                        <AppIconAction
                            icon={Settings}
                            label="Branch settings"
                            href={links.settings}
                            size="default"
                            className="gap-2 px-3"
                        >
                            <span className="text-sm font-medium">
                                Settings
                            </span>
                        </AppIconAction>
                        {links.select_branch ? (
                            <Button asChild size="sm" variant="outline">
                                <Link href={links.select_branch}>
                                    Switch branch
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </div>

                {modules.length > 0 ? (
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {modules.map((module) => (
                            <li key={module.key}>
                                <Link
                                    href={module.href}
                                    className="block rounded-lg border border-border px-4 py-3 text-sm font-medium transition-colors hover:bg-muted/50"
                                >
                                    {module.label}
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
            </div>
        </>
    );
}
