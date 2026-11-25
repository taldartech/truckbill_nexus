import { Head, Link, router } from '@inertiajs/react';
import { Eye, LogIn, Plus } from 'lucide-react';
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
import { FormSection } from '@/components/form-section';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type BranchRow = {
    id: string;
    name: string;
    status: string;
    assigned_users_count: number;
    created_at: string | null;
    company: { id: string; name: string; status: string } | null;
    urls: {
        show: string | null;
        company_context: string | null;
        enter_branch_context: string | null;
        enter_branch: string | null;
        create_for_company: string | null;
    };
};

type Props = {
    filters: {
        q: string;
        status: string;
        company: string | null;
        sort: string;
        direction: string;
    };
    companyOptions: { id: string; name: string }[];
    branches: {
        data: BranchRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: {
        index: string;
        companies: string;
        create_company: string;
        create_branch?: string;
    };
};

export default function OwnerBranchesAll({
    filters,
    companyOptions,
    branches,
    links,
}: Props) {
    const [search, setSearch] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [company, setCompany] = useState(filters.company ?? '');

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

    const columns = useMemo<DataTableColumn<BranchRow>[]>(
        () => [
            {
                id: 'branch',
                header: 'Branch',
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
                id: 'company',
                header: 'Company',
                priority: 2,
                cell: (row) => (
                    <span className="inline-flex flex-col gap-0.5">
                        <span>{row.company?.name ?? '—'}</span>
                        {row.company?.status === 'Deactivated' ? (
                            <span className="text-[11px] text-amber-700">
                                Company deactivated
                            </span>
                        ) : null}
                    </span>
                ),
            },
            {
                id: 'assigned_users',
                header: 'Assigned users',
                priority: 2,
                cell: (row) => row.assigned_users_count,
            },
        ],
        [],
    );

    const createHref =
        links.create_branch ??
        (company
            ? `/management/companies/${company}/branches/create`
            : '/management/branches/create');

    return (
        <>
            <Head title="Owner · Branches" />
            <OwnerPageHeader
                title="Branches"
                description="Branches across every company you own. Create Branch selects the target company explicitly — it does not use a previous operational session."
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href={links.companies}>Companies</Link>
                        </Button>
                        <Button
                            asChild
                            className="gap-1.5 bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            <Link
                                href={
                                    company
                                        ? `/management/companies/${company}/branches/create`
                                        : createHref
                                }
                            >
                                <Plus className="size-3.5" aria-hidden />
                                Create branch
                            </Link>
                        </Button>
                    </>
                }
            >
                <form onSubmit={applyFilters}>
                    <FormSection title="Filters" columns={2}>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="q">Branch name</Label>
                            <Input
                                id="q"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="company">Company</Label>
                            <select
                                id="company"
                                value={company}
                                onChange={(event) =>
                                    setCompany(event.target.value)
                                }
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
                        <div className="space-y-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                value={status}
                                onChange={(event) =>
                                    setStatus(event.target.value)
                                }
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Deactivated</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <Button type="submit" variant="secondary">
                                Apply filters
                            </Button>
                        </div>
                    </FormSection>
                </form>

                <ResponsiveDataTable
                    caption="Branches"
                    columns={columns}
                    rows={branches.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No branches found"
                    emptyDescription="Create a branch from a company, or adjust filters."
                    actions={(branch) => (
                        <>
                            {branch.urls.show ? (
                                <AppIconAction
                                    icon={Eye}
                                    label="View or edit branch"
                                    href={branch.urls.show}
                                    variant="info"
                                />
                            ) : null}
                            {branch.urls.enter_branch_context ? (
                                <WorkspaceContextAction
                                    contextUrl={
                                        branch.urls.enter_branch_context
                                    }
                                    redirect={
                                        branch.urls.enter_branch ??
                                        '/operations'
                                    }
                                    icon={LogIn}
                                    label="Enter Branch Panel"
                                />
                            ) : null}
                        </>
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Page {branches.meta.current_page} of{' '}
                    {branches.meta.last_page} · {branches.meta.total} total
                </p>
            </OwnerPageHeader>
        </>
    );
}
