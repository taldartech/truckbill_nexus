import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';

type CompanyRow = {
    id: string;
    name: string;
    pan: string | null;
    is_active: boolean;
    deactivated_at: string | null;
    owner: { id: number; name: string; email: string } | null;
    show_url: string;
};

type Props = {
    filters: { q: string; status: string };
    index_url: string;
    companies: {
        data: CompanyRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function AdminCompaniesIndex({
    filters,
    index_url,
    companies,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<CompanyRow>[]>(
        () => [
            {
                id: 'company',
                header: 'Company',
                priority: 0,
                cell: (row) => (
                    <>
                        <div>{row.name}</div>
                        {row.pan ? (
                            <div className="font-mono text-xs text-muted-foreground">
                                {row.pan}
                            </div>
                        ) : null}
                    </>
                ),
            },
            {
                id: 'owner',
                header: 'Owner',
                priority: 2,
                cell: (row) =>
                    row.owner ? (
                        <>
                            <div>{row.owner.name}</div>
                            <div className="text-xs text-muted-foreground">
                                {row.owner.email}
                            </div>
                        </>
                    ) : (
                        '—'
                    ),
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
            <Head title="Company lifecycle" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Lifecycle
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Companies
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Deactivate and reactivate companies with mandatory
                        remarks. Ownership transfer is available on company
                        detail.
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
                    className="flex flex-col gap-3 rounded-xl border border-border p-4 md:flex-row md:items-end"
                >
                    <label className="flex flex-1 flex-col gap-1 text-sm">
                        Search
                        <input
                            name="q"
                            defaultValue={filters.q}
                            className="rounded-md border border-border bg-background px-3 py-2"
                            placeholder="Name, PAN, or owner"
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
                    <Button type="submit">Filter</Button>
                </Form>

                <ResponsiveDataTable
                    caption="Companies"
                    columns={columns}
                    rows={companies.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No companies match these filters"
                    emptyDescription="Try adjusting search or status filters."
                    actions={(company) => (
                        <Link
                            href={company.show_url}
                            className="text-sm font-medium underline-offset-4 hover:underline"
                        >
                            Open
                        </Link>
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Showing page {companies.meta.current_page} of{' '}
                    {companies.meta.last_page} ({companies.meta.total} total)
                </p>
            </div>
        </>
    );
}
