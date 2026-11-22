import { Head, Link } from '@inertiajs/react';
import {
    Building2,
    CreditCard,
    FileSearch,
    GitBranch,
    Settings,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import type { WorkspaceBranch } from '@/types/workspace';

type OwnerDashboardProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    isDeactivated: boolean;
    branches: WorkspaceBranch[];
    subscription: {
        presentation: string;
        message: string;
        company_status: string;
    };
    links: {
        subscription: string;
        branches: string;
        users: string;
        settings: string;
        audit: string;
        select_branch: string | null;
    };
};

export default function OwnerDashboard({
    company,
    ownerPanelScope,
    isDeactivated,
    branches,
    subscription,
    links,
}: OwnerDashboardProps) {
    return (
        <>
            <Head title={`Owner Panel · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <WorkspaceStatusBadge status={company.status} />
                            <span>Scope: {ownerPanelScope}</span>
                        </div>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>

                {isDeactivated ? (
                    <div
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        Company is Deactivated. Branch Panel is unavailable.
                        Administrative and billing information remains visible.
                    </div>
                ) : null}

                <section
                    aria-label="Administration shortcuts"
                    className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <PanelCard
                        icon={GitBranch}
                        title="Branches"
                        body={
                            isDeactivated
                                ? 'Branch entry is blocked while the company is deactivated.'
                                : `${branches.length} accessible branch${branches.length === 1 ? '' : 'es'}.`
                        }
                        action={
                            <Button asChild variant="secondary" size="sm">
                                <Link href={links.branches}>View branches</Link>
                            </Button>
                        }
                    />
                    <PanelCard
                        icon={Users}
                        title="Users & memberships"
                        body="Invite users, review memberships, assign branch access and Customer roles."
                        action={
                            <Button asChild variant="secondary" size="sm">
                                <Link href={links.users}>Open users</Link>
                            </Button>
                        }
                    />
                    <PanelCard
                        icon={Settings}
                        title="Company settings"
                        body="View company status and edit the company display name. Additional master fields remain deferred."
                        action={
                            <Button asChild variant="secondary" size="sm">
                                <Link href={links.settings}>Open settings</Link>
                            </Button>
                        }
                    />
                    <PanelCard
                        icon={FileSearch}
                        title="Company audit"
                        body="Read-only company activity history with search and pagination."
                        action={
                            <Button asChild variant="secondary" size="sm">
                                <Link href={links.audit}>Open audit</Link>
                            </Button>
                        }
                    />
                    <PanelCard
                        icon={CreditCard}
                        title="Subscription & billing"
                        body={subscription.message}
                        action={
                            <Button asChild variant="secondary" size="sm">
                                <Link href={links.subscription}>
                                    Open subscription
                                </Link>
                            </Button>
                        }
                    />
                    <PanelCard
                        icon={Building2}
                        title="Company snapshot"
                        body={`Identity and status for ${company.name}.`}
                    />
                </section>

                {!isDeactivated && links.select_branch ? (
                    <div>
                        <Button asChild>
                            <Link href={links.select_branch}>
                                Enter Branch Panel
                            </Link>
                        </Button>
                    </div>
                ) : null}

                {branches.length === 0 && !isDeactivated ? (
                    <ShellEmptyState
                        title="No active branches available"
                        description="No active branches are available for this company. Branch creation is not part of this P2-WS-8 UI slice."
                    />
                ) : null}
            </div>
        </>
    );
}

function PanelCard({
    icon: Icon,
    title,
    body,
    action,
}: {
    icon: typeof Building2;
    title: string;
    body: string;
    action?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Icon className="size-4 text-muted-foreground" aria-hidden />
                <h2 className="font-medium">{title}</h2>
            </div>
            <p className="text-sm text-muted-foreground">{body}</p>
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}
