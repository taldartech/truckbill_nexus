import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { enter } from '@/routes/workspace/branches';

type BranchRow = {
    id: string;
    name: string;
    status: string;
    show_url: string;
};

type OwnerBranchesIndexProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    canEnterBranch: boolean;
    capacity: {
        included_branches: number;
        purchased_branches: number;
        branch_limit: number;
        used_branches: number;
        available_branch_slots: number;
    };
    filters: { q: string; status: string };
    branches: {
        data: BranchRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    deactivatedMessage: string | null;
    links: {
        dashboard: string;
        index: string;
        create: string;
    };
};

type FlashPageProps = {
    flash?: { success?: string | null };
};

export default function OwnerBranchesIndex({
    company,
    ownerPanelScope,
    canManage,
    canEnterBranch,
    capacity,
    filters,
    branches,
    deactivatedMessage,
    links,
}: OwnerBranchesIndexProps) {
    const { flash } = usePage<FlashPageProps>().props;
    const [search, setSearch] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            { q: search || undefined, status },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`Branches · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Branches
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

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <div className="flex flex-col gap-3 rounded-xl border border-border p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="text-sm text-muted-foreground">
                        Capacity:{' '}
                        <span className="font-medium text-foreground">
                            {capacity.used_branches}/{capacity.branch_limit}
                        </span>{' '}
                        branches used ({capacity.available_branch_slots} slot
                        {capacity.available_branch_slots === 1 ? '' : 's'}{' '}
                        available)
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={links.dashboard}>Dashboard</Link>
                        </Button>
                        {canManage && capacity.available_branch_slots >= 1 ? (
                            <Button asChild>
                                <Link href={links.create}>Create branch</Link>
                            </Button>
                        ) : null}
                        {canManage && capacity.available_branch_slots < 1 ? (
                            <Button type="button" disabled>
                                Capacity full
                            </Button>
                        ) : null}
                    </div>
                </div>

                {deactivatedMessage ? (
                    <ShellEmptyState
                        title="Branch administration unavailable"
                        description={deactivatedMessage}
                    />
                ) : (
                    <>
                        <form
                            onSubmit={applyFilters}
                            className="flex flex-col gap-3 rounded-xl border border-border p-4 sm:flex-row sm:items-end"
                        >
                            <div className="flex-1 space-y-2">
                                <Label htmlFor="branch-search">Search</Label>
                                <Input
                                    id="branch-search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Branch name"
                                />
                            </div>
                            <div className="space-y-2 sm:w-40">
                                <Label htmlFor="branch-status">Status</Label>
                                <select
                                    id="branch-status"
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(event.target.value)
                                    }
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                            <Button type="submit" variant="secondary">
                                Filter
                            </Button>
                        </form>

                        {branches.data.length === 0 ? (
                            <ShellEmptyState
                                title="No branches found"
                                description="Create a branch or adjust filters."
                            />
                        ) : (
                            <ul className="space-y-3" role="list">
                                {branches.data.map((branch) => (
                                    <li
                                        key={branch.id}
                                        className="flex flex-col gap-3 rounded-xl border border-border p-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <Link
                                                href={branch.show_url}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {branch.name}
                                            </Link>
                                            <div className="mt-1">
                                                <WorkspaceStatusBadge
                                                    status={branch.status}
                                                />
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Button variant="outline" asChild>
                                                <Link href={branch.show_url}>
                                                    Manage
                                                </Link>
                                            </Button>
                                            {canEnterBranch &&
                                            branch.status === 'Active' ? (
                                                <Form
                                                    {...enter.form({
                                                        company: company.id,
                                                        branch: branch.id,
                                                    })}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Enter Branch Panel
                                                        </Button>
                                                    )}
                                                </Form>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {branches.meta.last_page > 1 ? (
                            <p className="text-sm text-muted-foreground">
                                Page {branches.meta.current_page} of{' '}
                                {branches.meta.last_page} ·{' '}
                                {branches.meta.total} total
                            </p>
                        ) : null}
                    </>
                )}
            </div>
        </>
    );
}
