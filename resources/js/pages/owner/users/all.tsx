import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type MembershipRow = {
    id: string;
    status: string;
    is_owner: boolean;
    user: { id: number | null; name: string | null; email: string | null };
    company: { id: string | null; name: string | null };
    branches: string[];
    roles: string[];
    urls: { show: string | null };
};

type Props = {
    filters: { q: string; status: string; company: string };
    companyOptions: { id: string; name: string }[];
    memberships: {
        data: MembershipRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { index: string; companies: string };
};

export default function OwnerUsersAll({
    filters,
    companyOptions,
    memberships,
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

    return (
        <>
            <Head title="Owner · Users" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
                <OwnerPageHeader
                    title="Users"
                    description="Memberships across every company you own. Open a row to manage invitations, roles, and branches."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={links.companies}>Companies</Link>
                        </Button>
                    }
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 rounded-xl border border-border bg-card p-4 md:grid-cols-4"
                >
                    <div className="space-y-2 md:col-span-2">
                        <Label htmlFor="q">Search</Label>
                        <Input
                            id="q"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name or email"
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
                            <option value="inactive">Inactive</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div className="md:col-span-4">
                        <Button type="submit" variant="secondary">
                            Apply filters
                        </Button>
                    </div>
                </form>

                {memberships.data.length === 0 ? (
                    <ShellEmptyState
                        title="No memberships found"
                        description="Invite users from a company Users screen, or adjust filters."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border bg-card shadow-sm">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/40 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Email
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Company
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Branches
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Roles
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {memberships.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-3 font-medium">
                                            {row.user.name ?? '—'}
                                            {row.is_owner ? (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    Owner
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-3 text-muted-foreground">
                                            {row.user.email ?? '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            {row.company.name ?? '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            {row.branches.length > 0
                                                ? row.branches.join(', ')
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            {row.roles.length > 0
                                                ? row.roles.join(', ')
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.status}
                                            />
                                        </td>
                                        <td className="px-3 py-3">
                                            {row.urls.show ? (
                                                <Link
                                                    href={row.urls.show}
                                                    className="text-xs font-medium text-primary underline"
                                                >
                                                    Manage
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    Page {memberships.meta.current_page} of{' '}
                    {memberships.meta.last_page} · {memberships.meta.total}{' '}
                    total
                </p>
            </div>
        </>
    );
}
