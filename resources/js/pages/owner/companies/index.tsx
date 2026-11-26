import { Head, router } from '@inertiajs/react';
import {
    CreditCard,
    GitBranch,
    History,
    Pencil,
    Plus,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
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

type CompanyRow = {
    id: string;
    name: string;
    pan: string | null;
    gstin: string | null;
    gst_registration_type: string | null;
    registered_address: string | null;
    contact_person: string | null;
    mobile: string | null;
    data_visibility: string | null;
    branch_count: number;
    status: string;
    subscription_status: string;
    created_at: string | null;
    can_manage: boolean;
    urls: {
        context: string;
        settings: string;
        branches: string;
        subscription: string;
        audit: string;
        users: string;
        create_branch: string | null;
    };
};

type Props = {
    filters: {
        q: string;
        status: string;
        sort: string;
        direction: string;
    };
    companies: {
        data: CompanyRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: {
        index: string;
        create: string;
        branches_all: string;
        subscriptions: string;
    };
};

function visibilityLabel(value: string | null): string {
    if (value === 'branch_wise_restricted') {
        return 'Restricted';
    }

    if (value === 'shared_data') {
        return 'Shared';
    }

    return '—';
}

export default function OwnerCompaniesIndex({
    filters,
    companies,
    links,
}: Props) {
    const [search, setSearch] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            {
                q: search || undefined,
                status,
                sort: filters.sort,
                direction: filters.direction,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<CompanyRow>[]>(
        () => [
            {
                id: 'name',
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
                id: 'gstin',
                header: 'GSTIN',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.gstin ?? '—',
            },
            {
                id: 'pan',
                header: 'PAN',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.pan ?? '—',
            },
            {
                id: 'branches',
                header: 'Branches',
                priority: 2,
                cell: (row) => row.branch_count,
            },
            {
                id: 'visibility',
                header: 'Visibility',
                priority: 3,
                cell: (row) => visibilityLabel(row.data_visibility),
            },
            {
                id: 'subscription',
                header: 'Subscription',
                priority: 3,
                className: 'capitalize',
                cell: (row) => row.subscription_status.replaceAll('_', ' '),
            },
            {
                id: 'created',
                header: 'Created',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.created_at),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Management · Companies" />
            <OwnerPageHeader
                title="Companies"
                description="Manage every company you own from one list — open settings, users, branches, or billing directly."
                actions={
                    <AppIconAction
                        icon={Plus}
                        label="Create company"
                        href={links.create}
                        variant="default"
                        size="default"
                        className="gap-2 px-3"
                    >
                        <span className="text-sm font-medium">Create</span>
                    </AppIconAction>
                }
            >
                <form
                    onSubmit={applyFilters}
                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                >
                    <div className="min-w-0 flex-1 space-y-2">
                        <Label htmlFor="q">Search</Label>
                        <Input
                            id="q"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, PAN, or GSTIN"
                        />
                    </div>
                    <div className="space-y-2 sm:w-48">
                        <Label htmlFor="status">Status</Label>
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
                    caption="Owned companies"
                    columns={columns}
                    rows={companies.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No companies yet"
                    emptyDescription="Create your first company to open management for branches and subscriptions."
                    emptyAction={
                        <AppIconAction
                            icon={Plus}
                            label="Create company"
                            href={links.create}
                            variant="default"
                            size="default"
                            className="gap-2 px-3"
                        >
                            <span className="text-sm font-medium">Create</span>
                        </AppIconAction>
                    }
                    actions={(company) => (
                        <>
                            <WorkspaceContextAction
                                contextUrl={company.urls.context}
                                redirect={company.urls.settings}
                                icon={Pencil}
                                label="Edit company"
                                variant="warning"
                                disabled={!company.can_manage}
                            />
                            <AppIconAction
                                icon={GitBranch}
                                label="Manage branches"
                                href={company.urls.branches}
                                variant="info"
                            />
                            {company.urls.create_branch ? (
                                <AppIconAction
                                    icon={Plus}
                                    label="Create branch"
                                    href={company.urls.create_branch}
                                    variant="default"
                                />
                            ) : null}
                            <AppIconAction
                                icon={Users}
                                label="Manage users"
                                href={company.urls.users}
                                variant="info"
                            />
                            <span className="hidden sm:contents">
                                <AppIconAction
                                    icon={CreditCard}
                                    label="View subscriptions"
                                    href={company.urls.subscription}
                                    variant="default"
                                />
                                <AppIconAction
                                    icon={History}
                                    label="View audit"
                                    href={company.urls.audit}
                                    variant="secondary"
                                />
                            </span>
                        </>
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
