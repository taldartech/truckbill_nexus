import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { useMemo } from 'react';
import { PortalFlashBanner } from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
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

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

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
                        <div className="font-medium">{ticket.subject}</div>
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
                cell: (ticket) => (
                    <WorkspaceStatusBadge status={ticket.status} />
                ),
            },
            {
                id: 'due',
                header: 'Due',
                priority: 2,
                cell: (ticket) =>
                    ticket.is_overdue ? (
                        <span className="font-medium text-amber-700">
                            Overdue
                        </span>
                    ) : (
                        formatDisplayDateSmart(ticket.due_at)
                    ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Support tickets" />
            <PageShell
                title="Support tickets"
                description="Internal CRM · triage, assign, and resolve customer issues."
                actions={
                    <Button asChild variant="default">
                        <Link href={urls.create}>
                            <Plus className="size-4" aria-hidden />
                            Create ticket
                        </Link>
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />

                <FormSection title="Filters" columns={2}>
                    <Form action={urls.index} method="get" className="contents">
                        <div className="space-y-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status}
                                className={selectClass}
                            >
                                <option value="all">All</option>
                                {statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {status}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" variant="info">
                                Apply filters
                            </Button>
                        </div>
                    </Form>
                </FormSection>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Support tickets"
                        columns={columns}
                        rows={tickets.data}
                        rowKey={(ticket) => ticket.id}
                        emptyTitle="No tickets found"
                        emptyDescription="Create a ticket or adjust filters."
                        actions={(ticket) => (
                            <AppIconAction
                                icon={Eye}
                                label="View ticket"
                                href={ticket.show_url}
                                variant="info"
                            />
                        )}
                    />
                    <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                        Page {tickets.meta.current_page} of{' '}
                        {tickets.meta.last_page} ({tickets.meta.total} total)
                    </p>
                </div>
            </PageShell>
        </>
    );
}
