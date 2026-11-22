import { Head, Link } from '@inertiajs/react';
import { Building2, CreditCard, GitBranch } from 'lucide-react';
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
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
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
                        This company is deactivated. Branch Panel and operational
                        actions are unavailable. You may view company identity and
                        billing information only.
                    </div>
                ) : null}

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <PanelCard
                        icon={Building2}
                        title="Company administration"
                        body="Company profile and administrative context for this owned company."
                    />
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
                        title="No branches available"
                        description="Create or activate a branch before entering the Branch Panel."
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
