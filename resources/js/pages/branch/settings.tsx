import { Head } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
import ShellEmptyState from '@/components/shell-empty-state';
import { FormSection } from '@/components/form-section';
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
    return (
        <>
            <Head title="Branch settings" />
            <PageShell
                title="Branch settings"
                description="Branch-level preferences and status."
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to dashboard"
                        href={links.dashboard}
                        variant="outline"
                    />
                }
            >
                <FormSection title="Workspace status" columns={2}>
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Branch
                        </p>
                        <p className="text-sm font-medium">{branch.name}</p>
                    </div>
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Status
                        </p>
                        <WorkspaceStatusBadge status={branch.status} />
                    </div>
                </FormSection>

                <ShellEmptyState
                    title="Branch preferences"
                    description={preferences.note}
                />
            </PageShell>
        </>
    );
}
