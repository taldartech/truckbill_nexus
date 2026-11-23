import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';

type LeadRow = {
    id: string;
    contact_name: string;
    email: string | null;
    company_name: string | null;
    status: string;
    source: string;
    interested_product: string;
    assignee: string | null;
    show_url: string;
};

type Props = {
    filters: { q: string; status: string };
    statuses: string[];
    urls: { index: string; create: string };
    leads: {
        data: LeadRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function CrmLeadsIndex({
    filters,
    statuses,
    urls,
    leads,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<LeadRow>[]>(
        () => [
            {
                id: 'contact',
                header: 'Contact',
                priority: 0,
                cell: (lead) => (
                    <>
                        <div>{lead.contact_name}</div>
                        <div className="text-xs text-muted-foreground">
                            {lead.email ?? '—'}
                            {lead.company_name ? ` · ${lead.company_name}` : ''}
                        </div>
                    </>
                ),
            },
            {
                id: 'product',
                header: 'Product',
                priority: 2,
                cell: (lead) => lead.interested_product,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (lead) => lead.status,
            },
            {
                id: 'assignee',
                header: 'Assignee',
                priority: 2,
                cell: (lead) => lead.assignee ?? 'Unassigned',
            },
        ],
        [],
    );

    return (
        <>
            <Head title="CRM Leads" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Internal · CRM
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Leads
                        </h1>
                    </div>
                    <Button asChild>
                        <Link href={urls.create}>Create lead</Link>
                    </Button>
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
                    action={urls.index}
                    method="get"
                    className="flex flex-col gap-3 rounded-xl border border-border p-4 md:flex-row md:items-end"
                >
                    <label className="flex flex-1 flex-col gap-1 text-sm">
                        Search
                        <input
                            name="q"
                            defaultValue={filters.q}
                            className="rounded-md border border-border bg-background px-3 py-2"
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
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit">Filter</Button>
                </Form>

                <ResponsiveDataTable
                    caption="CRM leads"
                    columns={columns}
                    rows={leads.data}
                    rowKey={(lead) => lead.id}
                    emptyTitle="No leads found"
                    emptyDescription="Create a lead or adjust filters."
                    actions={(lead) => (
                        <Link
                            href={lead.show_url}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            Open
                        </Link>
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Page {leads.meta.current_page} of {leads.meta.last_page} (
                    {leads.meta.total} total)
                </p>
            </div>
        </>
    );
}
