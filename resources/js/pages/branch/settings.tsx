import { Head, Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type BranchSettingsProps = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    isOwner: boolean;
    preferences: { note: string };
    links: {
        dashboard: string;
        owner_panel: string | null;
    };
};

export default function BranchSettings({
    company,
    branch,
    isOwner,
    preferences,
    links,
}: BranchSettingsProps) {
    return (
        <>
            <Head title={`Settings · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Branch Panel · Settings
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {branch.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span>{company.name}</span>
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

                <ShellEmptyState
                    title="Branch preferences"
                    description={preferences.note}
                    action={
                        <Button asChild variant="outline">
                            <Link href={links.dashboard}>Back to Branch Dashboard</Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}
