import { Head, Link } from '@inertiajs/react';
import {
    Building2,
    CreditCard,
    FileSearch,
    GitBranch,
    LogIn,
    Settings,
    Users,
} from 'lucide-react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
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
        create_company: string | null;
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
            <OwnerPageHeader
                title={company.name}
                description={`Owner scope: ${ownerPanelScope}`}
                breadcrumbs={[
                    { label: 'Companies', href: '/owner/companies' },
                    { label: company.name },
                ]}
                actions={
                    <>
                        <WorkspaceStatusBadge status={company.status} />
                        {links.create_company ? (
                            <Button asChild>
                                <Link
                                    href={links.create_company}
                                    aria-label="Create a new company"
                                >
                                    Create company
                                </Link>
                            </Button>
                        ) : null}
                    </>
                }
            >
                <section
                    aria-label="Company overview"
                    className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <MetricCard
                        label="Accessible branches"
                        value={branches.length}
                        icon={GitBranch}
                        tone="green"
                    />
                    <MetricCard
                        label="Subscription"
                        value={subscription.presentation}
                        hint={subscription.message}
                        icon={CreditCard}
                        tone="blue"
                    />
                    <MetricCard
                        label="Company status"
                        value={company.status.replaceAll('_', ' ')}
                        icon={Building2}
                        tone={company.status === 'active' ? 'green' : 'neutral'}
                    />
                </section>

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
                    <NavTile
                        icon={GitBranch}
                        title="Branches"
                        body={
                            isDeactivated
                                ? 'Branch entry is blocked while the company is deactivated.'
                                : 'Create, edit, and deactivate branches.'
                        }
                        href={links.branches}
                        disabled={isDeactivated}
                    />
                    <NavTile
                        icon={Users}
                        title="Users & memberships"
                        body="Invite users, review memberships, assign branch access and Customer roles."
                        href={links.users}
                    />
                    <NavTile
                        icon={Settings}
                        title="Company settings"
                        body="Update legal, tax, GST, visibility, and contact details."
                        href={links.settings}
                    />
                    <NavTile
                        icon={FileSearch}
                        title="Company audit"
                        body="Read-only company activity history with search and pagination."
                        href={links.audit}
                    />
                    <NavTile
                        icon={CreditCard}
                        title="Subscription & billing"
                        body={subscription.message}
                        href={links.subscription}
                    />
                </section>

                {!isDeactivated && links.select_branch ? (
                    <Button asChild variant="info">
                        <Link href={links.select_branch}>
                            <LogIn className="size-4" />
                            Enter Branch Panel
                        </Link>
                    </Button>
                ) : null}

                {branches.length === 0 && !isDeactivated ? (
                    <ShellEmptyState
                        title="No active branches available"
                        description="No active branches are available for this company. Open Branches to create one when capacity allows."
                        action={
                            <Button asChild>
                                <Link href={links.branches}>Open Branches</Link>
                            </Button>
                        }
                    />
                ) : null}
            </OwnerPageHeader>
        </>
    );
}

function NavTile({
    icon: Icon,
    title,
    body,
    href,
    disabled = false,
}: {
    icon: typeof GitBranch;
    title: string;
    body: string;
    href: string;
    disabled?: boolean;
}) {
    return (
        <div className="flex flex-col rounded-xl border border-border bg-card p-4 shadow-xs">
            <div className="mb-3 flex items-center gap-2">
                <Icon className="size-4 text-[#2FB36F]" aria-hidden />
                <h2 className="font-medium">{title}</h2>
            </div>
            <p className="flex-1 text-sm text-muted-foreground">{body}</p>
            <div className="mt-4">
                {disabled ? (
                    <Button variant="outline" size="sm" disabled>
                        Unavailable
                    </Button>
                ) : (
                    <Button asChild variant="info" size="sm">
                        <Link href={href}>Open</Link>
                    </Button>
                )}
            </div>
        </div>
    );
}
