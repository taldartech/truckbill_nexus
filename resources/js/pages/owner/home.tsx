import { Head, Link } from '@inertiajs/react';
import {
    Building2,
    CreditCard,
    GitBranch,
    TriangleAlert,
    Users,
} from 'lucide-react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { SummaryCard } from '@/components/owner/summary-card';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type CompanyCard = {
    id: string;
    name: string;
    status: string;
    subscription_status: string;
    branch_count: number;
    urls: {
        dashboard: string;
        branches: string;
        subscription: string;
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
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
                <OwnerPageHeader
                    title={`${greeting}, ${owner_name}`}
                    description="Health of your owned companies — open any company for settings, users, or billing."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={links.companies}>
                                    All companies
                                </Link>
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
                />

                <section
                    aria-label="Summary"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <SummaryCard
                        label="Companies"
                        value={totals.companies}
                        hint={`${totals.active_companies} active`}
                        icon={Building2}
                        tone="info"
                    />
                    <SummaryCard
                        label="Branches"
                        value={totals.branches}
                        icon={GitBranch}
                        tone="default"
                    />
                    <SummaryCard
                        label="Active subscriptions"
                        value={totals.active_subscriptions}
                        icon={CreditCard}
                        tone="success"
                    />
                    <SummaryCard
                        label="Needs attention"
                        value={totals.trial_or_attention}
                        icon={TriangleAlert}
                        tone={
                            totals.trial_or_attention > 0
                                ? 'warning'
                                : 'default'
                        }
                    />
                </section>

                <div className="grid gap-6 xl:grid-cols-5">
                    <section
                        aria-label="Company quick access"
                        className="space-y-4 xl:col-span-3"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Your companies
                            </h2>
                            <Link
                                href={links.companies}
                                className="text-sm font-medium text-[#2D8CFF] underline-offset-4 hover:underline"
                            >
                                Manage all
                            </Link>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {companies.map((company) => (
                                <div
                                    key={company.id}
                                    className="rounded-xl border border-border bg-card p-4 shadow-sm"
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
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="secondary"
                                        >
                                            <Link href={company.urls.dashboard}>
                                                Open
                                            </Link>
                                        </Button>
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={company.urls.branches}>
                                                Branches
                                            </Link>
                                        </Button>
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link
                                                href={company.urls.subscription}
                                            >
                                                Subscription
                                            </Link>
                                        </Button>
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
                                Attention
                            </h2>
                            <Link
                                href={links.subscriptions}
                                className="text-sm font-medium text-[#2D8CFF] underline-offset-4 hover:underline"
                            >
                                Subscriptions
                            </Link>
                        </div>
                        <div className="rounded-xl border border-border bg-card shadow-sm">
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
                                                <Link
                                                    href={item.href}
                                                    className="inline-block text-xs font-medium text-[#2D8CFF] underline-offset-4 hover:underline"
                                                >
                                                    Review
                                                </Link>
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
            </div>
        </>
    );
}
