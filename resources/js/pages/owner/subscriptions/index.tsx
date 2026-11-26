import { Head, Link, router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { WorkspaceContextAction } from '@/components/workspace-context-action';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDateSmart } from '@/lib/dates';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type SubscriptionRow = {
    id: string;
    name: string;
    status: string;
    subscription_status: string;
    active_modules: string[];
    trial_ends_at: string | null;
    paid_through_date: string | null;
    common_renewal_date: string | null;
    notes: string[];
    urls: {
        context: string;
        subscription: string;
        purchase?: string;
        company_settings: string;
    };
};

type Props = {
    filters: { q: string; status: string; company: string };
    companyOptions: { id: string; name: string }[];
    companies: {
        data: SubscriptionRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { index: string; companies: string };
};

export default function OwnerSubscriptionsIndex({
    filters,
    companyOptions,
    companies,
    links,
}: Props) {
    const [search, setSearch] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [company, setCompany] = useState(filters.company);

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            {
                q: search || undefined,
                status,
                company: company || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<SubscriptionRow>[]>(
        () => [
            {
                id: 'company',
                header: 'Company',
                priority: 0,
                cell: (row) => row.name,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => <WorkspaceStatusBadge status={row.status} />,
            },
            {
                id: 'modules',
                header: 'Active modules',
                priority: 2,
                className: 'text-xs',
                cell: (row) =>
                    row.active_modules.length > 0
                        ? row.active_modules
                              .map((module) => module.replaceAll('_', ' '))
                              .join(', ')
                        : '—',
            },
            {
                id: 'subscription',
                header: 'Subscription',
                priority: 2,
                className: 'capitalize',
                cell: (row) => row.subscription_status.replaceAll('_', ' '),
            },
            {
                id: 'renewal',
                header: 'Trial / renewal',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) =>
                    row.trial_ends_at
                        ? `Trial ends ${formatDisplayDateSmart(row.trial_ends_at)}`
                        : row.common_renewal_date
                          ? `Renews ${formatDisplayDateSmart(row.common_renewal_date)}`
                          : row.paid_through_date
                            ? `Paid through ${formatDisplayDateSmart(row.paid_through_date)}`
                            : '—',
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Owner · Subscriptions" />
            <OwnerPageHeader
                title="Subscriptions"
                description="Choose a company to manage modules, plans, renewals, and payments. Pricing is always calculated on the server."
                actions={
                    <Button asChild variant="outline">
                        <Link href={links.companies}>Companies</Link>
                    </Button>
                }
            >
                <form
                    onSubmit={applyFilters}
                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                >
                    <div className="min-w-0 flex-1 space-y-2">
                        <Label htmlFor="q">Search companies</Label>
                        <Input
                            id="q"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <div className="space-y-2 sm:w-48">
                        <Label htmlFor="company">Company</Label>
                        <select
                            id="company"
                            value={company}
                            onChange={(event) => setCompany(event.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="">All owned companies</option>
                            {companyOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-2 sm:w-48">
                        <Label htmlFor="status">Company status</Label>
                        <select
                            id="status"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Deactivated</option>
                        </select>
                    </div>
                    <Button type="submit" variant="secondary">
                        Apply
                    </Button>
                </form>

                <ResponsiveDataTable
                    caption="Company subscriptions"
                    columns={columns}
                    rows={companies.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No owned companies"
                    emptyDescription="Create a company before managing subscriptions."
                    actions={(row) => (
                        <div className="flex items-center justify-end gap-1.5">
                            {row.urls.purchase ? (
                                <Button asChild size="sm" variant="default">
                                    <Link href={row.urls.purchase}>
                                        {row.active_modules.length > 0
                                            ? 'Upgrade / Renew'
                                            : 'Buy Plan'}
                                    </Link>
                                </Button>
                            ) : null}
                            <WorkspaceContextAction
                                contextUrl={row.urls.context}
                                redirect={row.urls.subscription}
                                icon={CreditCard}
                                label="Overview"
                            />
                        </div>
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Page {companies.meta.current_page} of{' '}
                    {companies.meta.last_page} · {companies.meta.total} total
                </p>
            </OwnerPageHeader>
        </>
    );
}
