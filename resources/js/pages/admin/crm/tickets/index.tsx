import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { formatDisplayDateSmart } from '@/lib/dates';

type TicketRow = {
    id: string;
    subject: string;
    category: string;
    priority: string;
    status: string;
    assignee: string | null;
    due_at: string | null;
    is_overdue: boolean;
    show_url: string;
};

type Props = {
    filters: { status: string };
    statuses: string[];
    urls: { index: string; create: string };
    tickets: {
        data: TicketRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function CrmTicketsIndex({
    filters,
    statuses,
    urls,
    tickets,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<TicketRow>[]>(
        () => [
            {
                id: 'subject',
                header: 'Subject',
                priority: 0,
                cell: (ticket) => (
                    <>
                        <div>{ticket.subject}</div>
                        <div className="text-xs text-muted-foreground">
                            {ticket.category}
                            {ticket.assignee ? ` · ${ticket.assignee}` : ''}
                        </div>
                    </>
                ),
            },
            {
                id: 'priority',
                header: 'Priority',
                priority: 2,
                cell: (ticket) => ticket.priority,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (ticket) => ticket.status,
            },
            {
                id: 'due',
                header: 'Due',
                priority: 2,
                cell: (ticket) =>
                    ticket.is_overdue
                        ? 'Overdue'
                        : formatDisplayDateSmart(ticket.due_at),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Support tickets" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Internal · CRM
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Support tickets
                        </h1>
                    </div>
                    <Button asChild>
                        <Link href={urls.create}>Create ticket</Link>
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
                    caption="Support tickets"
                    columns={columns}
                    rows={tickets.data}
                    rowKey={(ticket) => ticket.id}
                    emptyTitle="No tickets found"
                    emptyDescription="Create a ticket or adjust filters."
                    actions={(ticket) => (
                        <Link
                            href={ticket.show_url}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            Open
                        </Link>
                    )}
                />
            </div>
        </>
    );
}
