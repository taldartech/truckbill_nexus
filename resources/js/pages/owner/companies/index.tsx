import { Head, router } from '@inertiajs/react';
import {
    CreditCard,
    GitBranch,
    History,
    Pencil,
    Plus,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
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
            <Head title="Management · Companies" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
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
                />

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
                        description="Create your first company to open management for branches and subscriptions."
                        action={
                            <AppIconAction
                                icon={Plus}
                                label="Create company"
                                href={links.create}
                                variant="default"
                                size="default"
                                className="gap-2 px-3"
                            >
                                <span className="text-sm font-medium">
                                    Create
                                </span>
                            </AppIconAction>
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
                                        GSTIN
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        PAN
                                    </th>
                                    <th className="hidden px-3 py-2 font-medium lg:table-cell">
                                        Created
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
                                            {company.gstin ?? '—'}
                                        </td>
                                        <td className="px-3 py-3 font-mono text-xs">
                                            {company.pan ?? '—'}
                                        </td>
                                        <td className="hidden px-3 py-3 text-xs text-muted-foreground lg:table-cell">
                                            {formatDisplayDateSmart(
                                                company.created_at,
                                            )}
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
                                            <div className="flex flex-wrap gap-1">
                                                <WorkspaceContextAction
                                                    contextUrl={
                                                        company.urls.context
                                                    }
                                                    redirect={
                                                        company.urls.settings
                                                    }
                                                    icon={Pencil}
                                                    label="Edit company"
                                                    disabled={
                                                        !company.can_manage
                                                    }
                                                />
                                                <WorkspaceContextAction
                                                    contextUrl={
                                                        company.urls.context
                                                    }
                                                    redirect={
                                                        company.urls.branches
                                                    }
                                                    icon={GitBranch}
                                                    label="Manage branches"
                                                />
                                                <WorkspaceContextAction
                                                    contextUrl={
                                                        company.urls.context
                                                    }
                                                    redirect={
                                                        company.urls.users
                                                    }
                                                    icon={Users}
                                                    label="Manage users"
                                                />
                                                <WorkspaceContextAction
                                                    contextUrl={
                                                        company.urls.context
                                                    }
                                                    redirect={
                                                        company.urls
                                                            .subscription
                                                    }
                                                    icon={CreditCard}
                                                    label="Manage subscription"
                                                />
                                                <WorkspaceContextAction
                                                    contextUrl={
                                                        company.urls.context
                                                    }
                                                    redirect={
                                                        company.urls.audit
                                                    }
                                                    icon={History}
                                                    label="View audit"
                                                />
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
