import { Head, Link } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
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
    branch,
    preferences,
    links,
}: BranchSettingsProps) {
    const showStatus = branch.status !== 'Active';

    return (
        <>
            <Head title="Branch settings" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <MasterPageHeader title="Branch settings" />
                    {showStatus ? (
                        <WorkspaceStatusBadge status={branch.status} />
                    ) : null}
                </div>

                <ShellEmptyState
                    title="Branch preferences"
                    description={preferences.note}
                    action={
                        <Button asChild variant="outline">
                            <Link href={links.dashboard}>
                                Back to dashboard
                            </Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}
