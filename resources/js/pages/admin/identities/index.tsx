import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';

type IdentityRow = {
    id: number;
    name: string;
    email: string;
    identity_type: string | null;
    is_super_admin: boolean;
    is_active: boolean;
    deactivated_at: string | null;
    show_url: string;
};

type Props = {
    filters: { q: string; status: string; type: string };
    index_url: string;
    identities: {
        data: IdentityRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function AdminIdentitiesIndex({
    filters,
    index_url,
    identities,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<IdentityRow>[]>(
        () => [
            {
                id: 'identity',
                header: 'Identity',
                priority: 0,
                cell: (row) => (
                    <>
                        <div>{row.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.email}
                        </div>
                    </>
                ),
            },
            {
                id: 'type',
                header: 'Type',
                priority: 2,
                cell: (row) =>
                    `${row.identity_type ?? '—'}${row.is_super_admin ? ' · Super Admin' : ''}`,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (row.is_active ? 'Active' : 'Deactivated'),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Identity lifecycle" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Lifecycle
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Identities
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Deactivate or reactivate Customer and Internal
                        identities. Hard delete and restore are prohibited.
                    </p>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <Form
                    action={index_url}
                    method="get"
                    className="grid gap-3 rounded-xl border border-border p-4 md:grid-cols-4 md:items-end"
                >
                    <label className="flex flex-col gap-1 text-sm md:col-span-2">
                        Search
                        <input
                            name="q"
                            defaultValue={filters.q}
                            className="rounded-md border border-border bg-background px-3 py-2"
                            placeholder="Name or email"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Status
                        <select
                            name="status"
                            defaultValue={filters.status}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        >
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="deactivated">Deactivated</option>
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Type
                        <select
                            name="type"
                            defaultValue={filters.type}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        >
                            <option value="all">All</option>
                            <option value="customer">Customer</option>
                            <option value="internal">Internal</option>
                        </select>
                    </label>
                    <Button type="submit" className="md:col-span-4 md:w-fit">
                        Filter
                    </Button>
                </Form>

                <ResponsiveDataTable
                    caption="Identities"
                    columns={columns}
                    rows={identities.data}
                    rowKey={(row) => String(row.id)}
                    emptyTitle="No identities match these filters"
                    emptyDescription="Try adjusting search or filter options."
                    actions={(identity) => (
                        <Link
                            href={identity.show_url}
                            className="text-sm font-medium underline-offset-4 hover:underline"
                        >
                            Open
                        </Link>
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Showing page {identities.meta.current_page} of{' '}
                    {identities.meta.last_page} ({identities.meta.total} total)
                </p>
            </div>
        </>
    );
}
