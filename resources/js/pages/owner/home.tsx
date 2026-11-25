import { Head, Link } from '@inertiajs/react';
import {
    Building2,
    CreditCard,
    GitBranch,
    Plus,
    Settings,
    TriangleAlert,
    Users,
} from 'lucide-react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { WorkspaceContextAction } from '@/components/workspace-context-action';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type CompanyCard = {
    id: string;
    name: string;
    status: string;
    subscription_status: string;
    branch_count: number;
    can_manage: boolean;
    urls: {
        context: string;
        settings: string;
        branches: string;
        subscription: string;
        create_branch: string | null;
    };
};

type AttentionItem = {
    tone: string;
    title: string;
    detail: string;
    href: string | null;
};

type Props = {
    owner_name: string;
    totals: {
        companies: number;
        active_companies: number;
        branches: number;
        active_subscriptions: number;
        trial_or_attention: number;
    };
    companies: CompanyCard[];
    attention: AttentionItem[];
    links: {
        companies: string;
        branches: string;
        users: string;
        subscriptions: string;
        audit: string;
        create_company: string;
    };
};

export default function OwnerHome({
    owner_name,
    totals,
    companies,
    attention,
    links,
}: Props) {
    const hour = new Date().getHours();
    const greeting =
        hour < 12
            ? 'Good morning'
            : hour < 18
              ? 'Good afternoon'
              : 'Good evening';

    return (
        <>
            <Head title="Owner · Dashboard" />
            <OwnerPageHeader
                title={`${greeting}, ${owner_name}`}
                description="Owner control panel — company register, branch footprint, subscriptions, and audit at a glance. Select a company card to manage settings or enter operations."
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href={links.companies}>All companies</Link>
                        </Button>
                        <Button asChild>
                            <Link
                                href={links.create_company}
                                aria-label="Create a new company"
                            >
                                Create company
                            </Link>
                        </Button>
                    </>
                }
            >
                <section
                    aria-label="Summary"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <MetricCard
                        label="Companies"
                        value={totals.companies}
                        hint={`${totals.active_companies} active`}
                        icon={Building2}
                        tone="blue"
                    />
                    <MetricCard
                        label="Branches"
                        value={totals.branches}
                        icon={GitBranch}
                        tone="green"
                    />
                    <MetricCard
                        label="Active subscriptions"
                        value={totals.active_subscriptions}
                        icon={CreditCard}
                        tone="green"
                    />
                    <MetricCard
                        label="Needs attention"
                        value={totals.trial_or_attention}
                        icon={TriangleAlert}
                        tone={
                            totals.trial_or_attention > 0 ? 'amber' : 'neutral'
                        }
                    />
                </section>

                <div className="grid gap-4 xl:grid-cols-5">
                    <section
                        aria-label="Company quick access"
                        className="space-y-4 xl:col-span-3"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Company register
                            </h2>
                            <Button asChild variant="link" size="sm">
                                <Link href={links.companies}>Manage all</Link>
                            </Button>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {companies.map((company) => (
                                <div
                                    key={company.id}
                                    className="rounded-xl border border-border bg-card p-4 shadow-xs"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <p className="truncate font-semibold">
                                            {company.name}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <WorkspaceStatusBadge
                                                status={company.status}
                                            />
                                            <span className="capitalize">
                                                {company.subscription_status.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </span>
                                            <span>
                                                {company.branch_count} branch
                                                {company.branch_count === 1
                                                    ? ''
                                                    : 'es'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <WorkspaceContextAction
                                            contextUrl={company.urls.context}
                                            redirect={company.urls.settings}
                                            icon={Settings}
                                            label="Manage company"
                                            variant="info"
                                            disabled={!company.can_manage}
                                        />
                                        {company.urls.create_branch ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                className="gap-1.5 bg-[#2FB36F] text-white hover:bg-[#279960]"
                                            >
                                                <Link
                                                    href={
                                                        company.urls
                                                            .create_branch
                                                    }
                                                >
                                                    <Plus
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                    Create branch
                                                </Link>
                                            </Button>
                                        ) : null}
                                        <WorkspaceContextAction
                                            contextUrl={company.urls.context}
                                            redirect={company.urls.branches}
                                            icon={GitBranch}
                                            label="Branches"
                                            variant="outline"
                                        />
                                        <WorkspaceContextAction
                                            contextUrl={company.urls.context}
                                            redirect={company.urls.subscription}
                                            icon={CreditCard}
                                            label="Subscription"
                                            variant="outline"
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section
                        aria-label="Attention"
                        className="space-y-4 xl:col-span-2"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Subscription alerts
                            </h2>
                            <Button asChild variant="link" size="sm">
                                <Link href={links.subscriptions}>
                                    Subscriptions
                                </Link>
                            </Button>
                        </div>
                        <div className="rounded-xl border border-border bg-card shadow-xs">
                            {attention.length === 0 ? (
                                <p className="p-4 text-sm text-muted-foreground">
                                    No subscription or lifecycle items need
                                    attention right now.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {attention.map((item, index) => (
                                        <li
                                            key={`${item.title}-${index}`}
                                            className="space-y-1 p-4"
                                        >
                                            <p className="text-sm font-medium">
                                                {item.title}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {item.detail}
                                            </p>
                                            {item.href ? (
                                                <Button
                                                    asChild
                                                    variant="link"
                                                    size="sm"
                                                    className="h-auto p-0 text-xs"
                                                >
                                                    <Link href={item.href}>
                                                        Review
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.users}>
                                    <Users className="size-4" />
                                    Users
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.branches}>Branches</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.audit}>Audit</Link>
                            </Button>
                        </div>
                    </section>
                </div>
            </OwnerPageHeader>
        </>
    );
}
