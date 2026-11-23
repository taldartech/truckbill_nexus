import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type SubscriptionRow = {
    id: string;
    name: string;
    status: string;
    subscription_status: string;
    trial_ends_at: string | null;
    paid_through_date: string | null;
    notes: string[];
    urls: { subscription: string };
};

type Props = {
    filters: { q: string; status: string };
    companies: {
        data: SubscriptionRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { index: string; companies: string };
};

export default function OwnerSubscriptionsIndex({
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
            { q: search || undefined, status },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Owner · Subscriptions" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Subscriptions
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Select a company to open its subscription workspace.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={links.companies}>Companies</Link>
                    </Button>
                </div>

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

                {companies.data.length === 0 ? (
                    <ShellEmptyState
                        title="No owned companies"
                        description="Create a company before managing subscriptions."
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
                                        Company status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Subscription
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Trial / paid through
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {companies.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-3 font-medium">
                                            {row.name}
                                        </td>
                                        <td className="px-3 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.status}
                                            />
                                        </td>
                                        <td className="px-3 py-3 capitalize">
                                            {row.subscription_status.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-xs text-muted-foreground">
                                            {row.trial_ends_at
                                                ? `Trial ends ${row.trial_ends_at}`
                                                : row.paid_through_date
                                                  ? `Paid through ${row.paid_through_date}`
                                                  : '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            <Link
                                                href={row.urls.subscription}
                                                className="text-xs font-medium text-primary underline"
                                            >
                                                View subscription
                                            </Link>
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
