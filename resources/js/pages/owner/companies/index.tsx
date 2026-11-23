import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type CompanyRow = {
    id: string;
    name: string;
    pan: string | null;
    gst_registration_type: string | null;
    data_visibility: string | null;
    branch_count: number;
    status: string;
    subscription_status: string;
    created_at: string | null;
    can_manage: boolean;
    urls: {
        dashboard: string;
        settings: string;
        branches: string;
        subscription: string;
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

    return (
        <>
            <Head title="Owner · Companies" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Companies
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Manage every company you own from one list.
                        </p>
                    </div>
                    <Button asChild>
                        <Link
                            href={links.create}
                            aria-label="Create a new company"
                        >
                            Create company
                        </Link>
                    </Button>
                </div>

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

                {companies.data.length === 0 ? (
                    <ShellEmptyState
                        title="No companies yet"
                        description="Create your first company to open Owner management for branches and subscriptions."
                        action={
                            <Button asChild>
                                <Link href={links.create}>Create company</Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/40 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Company
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        PAN
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        GST
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Visibility
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Branches
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Subscription
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {companies.data.map((company) => (
                                    <tr
                                        key={company.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-3 font-medium">
                                            {company.name}
                                        </td>
                                        <td className="px-3 py-3 font-mono text-xs">
                                            {company.pan ?? '—'}
                                        </td>
                                        <td className="px-3 py-3 capitalize">
                                            {company.gst_registration_type ??
                                                '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            {company.data_visibility ===
                                            'branch_wise_restricted'
                                                ? 'Restricted'
                                                : company.data_visibility ===
                                                    'shared_data'
                                                  ? 'Shared'
                                                  : '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            {company.branch_count}
                                        </td>
                                        <td className="px-3 py-3">
                                            <WorkspaceStatusBadge
                                                status={company.status}
                                            />
                                        </td>
                                        <td className="px-3 py-3 capitalize">
                                            {company.subscription_status.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </td>
                                        <td className="px-3 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                <Link
                                                    href={
                                                        company.urls.dashboard
                                                    }
                                                    className="text-xs font-medium text-primary underline"
                                                >
                                                    View
                                                </Link>
                                                <Link
                                                    href={company.urls.settings}
                                                    className="text-xs font-medium text-primary underline"
                                                >
                                                    Settings
                                                </Link>
                                                <Link
                                                    href={company.urls.branches}
                                                    className="text-xs font-medium text-primary underline"
                                                >
                                                    Branches
                                                </Link>
                                                <Link
                                                    href={
                                                        company.urls
                                                            .subscription
                                                    }
                                                    className="text-xs font-medium text-primary underline"
                                                >
                                                    Subscription
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    Page {companies.meta.current_page} of{' '}
                    {companies.meta.last_page} · {companies.meta.total} total
                </p>
            </div>
        </>
    );
}
