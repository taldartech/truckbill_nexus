import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

    return (
        <>
            <Head title="Owner · Branches" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <OwnerPageHeader
                    title="Branches"
                    description="Branches across every company you own."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={links.companies}>Companies</Link>
                            </Button>
                            {companyOptions[0] ? (
                                <Button asChild>
                                    <Link
                                        href={`/owner/companies/${company || companyOptions[0].id}/branches/create`}
                                    >
                                        Create branch
                                    </Link>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 md:grid-cols-4"
                >
                    <div className="space-y-2 md:col-span-2">
                        <Label htmlFor="q">Branch name</Label>
                        <Input
                            id="q"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <div className="space-y-2">
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
                    <div className="space-y-2">
                        <Label htmlFor="status">Status</Label>
                        <select
                            id="status"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Deactivated</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div className="md:col-span-4">
                        <Button type="submit" variant="secondary">
                            Apply filters
                        </Button>
                    </div>
                </form>

                {branches.data.length === 0 ? (
                    <ShellEmptyState
                        title="No branches found"
                        description="Create a branch from a company, or adjust filters."
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
                                        Branch
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Assigned users
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {branches.data.map((branch) => (
                                    <tr
                                        key={branch.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-3">
                                            {branch.company?.name ?? '—'}
                                        </td>
                                        <td className="px-3 py-3 font-medium">
                                            {branch.name}
                                        </td>
                                        <td className="px-3 py-3">
                                            <WorkspaceStatusBadge
                                                status={branch.status}
                                            />
                                        </td>
                                        <td className="px-3 py-3">
                                            {branch.assigned_users_count}
                                        </td>
                                        <td className="px-3 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                {branch.urls.show ? (
                                                    <Link
                                                        href={branch.urls.show}
                                                        className="text-xs font-medium text-primary underline"
                                                    >
                                                        View / Edit
                                                    </Link>
                                                ) : null}
                                                {branch.urls.enter_branch ? (
                                                    <Link
                                                        href={
                                                            branch.urls
                                                                .enter_branch
                                                        }
                                                        className="text-xs font-medium text-primary underline"
                                                    >
                                                        Enter Branch Panel
                                                    </Link>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    Page {branches.meta.current_page} of{' '}
                    {branches.meta.last_page} · {branches.meta.total} total
                </p>
            </div>
        </>
    );
}
