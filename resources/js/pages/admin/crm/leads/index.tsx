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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type LeadRow = {
    id: string;
    contact_name: string;
    email: string | null;
    company_name: string | null;
    city: string | null;
    state: string | null;
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

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

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
                cell: (lead) => {
                    const location = [lead.city, lead.state]
                        .filter((part) => part && part.trim() !== '')
                        .join(', ');

                    return (
                        <>
                            <div className="font-medium">
                                {lead.contact_name}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {lead.email ?? '—'}
                                {lead.company_name
                                    ? ` · ${lead.company_name}`
                                    : ''}
                            </div>
                            {location ? (
                                <div className="text-xs text-muted-foreground">
                                    {location}
                                </div>
                            ) : null}
                        </>
                    );
                },
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
                cell: (lead) => <WorkspaceStatusBadge status={lead.status} />,
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
            <PageShell
                title="Leads"
                description="Internal CRM · qualify, assign, and convert inbound prospects."
                actions={
                    <Button asChild variant="default">
                        <Link href={urls.create}>
                            <Plus className="size-4" aria-hidden />
                            Create lead
                        </Link>
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />

                <FormSection title="Search & filters" columns={2}>
                    <Form action={urls.index} method="get" className="contents">
                        <div className="space-y-2 md:col-span-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Name, email, company"
                            />
                        </div>
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
                        <div className="flex items-end md:col-span-2">
                            <Button type="submit" variant="info">
                                Apply filters
                            </Button>
                        </div>
                    </Form>
                </FormSection>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="CRM leads"
                        columns={columns}
                        rows={leads.data}
                        rowKey={(lead) => lead.id}
                        emptyTitle="No leads found"
                        emptyDescription="Create a lead or adjust filters."
                        actions={(lead) => (
                            <AppIconAction
                                icon={Eye}
                                label="View lead"
                                href={lead.show_url}
                                variant="info"
                            />
                        )}
                    />
                    <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                        Page {leads.meta.current_page} of {leads.meta.last_page}{' '}
                        ({leads.meta.total} total)
                    </p>
                </div>
            </PageShell>
        </>
    );
}
