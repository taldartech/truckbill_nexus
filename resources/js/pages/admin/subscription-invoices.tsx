import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Download, Eye } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateSmart } from '@/lib/dates';

type InvoiceRow = {
    id: string;
    document_type: string;
    company_name: string | null;
    invoice_number: string | null;
    proposed_number: string | null;
    status: string;
    payment_status: string;
    purchase_type: string | null;
    financial_year: string | null;
    taxable_amount: string;
    total_tax_amount: string;
    amount: string;
    currency: string;
    issued_at: string | null;
    view_url: string;
    download_url: string;
    approve_url: string | null;
};

type Props = {
    filters: { company_id: string; q: string; status: string };
    companyOptions: { id: string; name: string }[];
    canManage: boolean;
    invoices: InvoiceRow[];
    links: { index: string; billing_profile: string };
};

export default function AdminSubscriptionInvoices({
    filters,
    companyOptions,
    canManage,
    invoices,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [search, setSearch] = useState(filters.q);
    const [companyId, setCompanyId] = useState(filters.company_id);
    const [status, setStatus] = useState(filters.status);

    const applyFilters = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            links.index,
            {
                q: search || undefined,
                company_id: companyId || undefined,
                status,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<InvoiceRow>[]>(
        () => [
            {
                id: 'number',
                header: 'Invoice Number',
                priority: 0,
                className: 'font-mono text-xs',
                cell: (row) => row.invoice_number || row.proposed_number || '—',
            },
            {
                id: 'company',
                header: 'Company',
                priority: 0,
                cell: (row) => row.company_name || '—',
            },
            {
                id: 'type',
                header: 'Type',
                priority: 2,
                className: 'capitalize text-xs',
                cell: (row) => row.document_type.replaceAll('_', ' '),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => <WorkspaceStatusBadge status={row.status} />,
            },
            {
                id: 'payment',
                header: 'Payment',
                priority: 2,
                className: 'capitalize text-xs',
                cell: (row) => row.payment_status,
            },
            {
                id: 'taxable',
                header: 'Taxable',
                priority: 2,
                cell: (row) => `${row.currency} ${row.taxable_amount}`,
            },
            {
                id: 'tax',
                header: 'GST',
                priority: 3,
                cell: (row) => `${row.currency} ${row.total_tax_amount}`,
            },
            {
                id: 'amount',
                header: 'Grand Total',
                priority: 1,
                className: 'font-medium',
                cell: (row) => `${row.currency} ${row.amount}`,
            },
            {
                id: 'date',
                header: 'Issued Date',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.issued_at),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Subscription Invoice Register" />
            <PageShell
                title="Subscription Invoices"
                description="Platform subscription tax invoices and credit notes issued to Customer Companies."
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={links.billing_profile}>
                            Platform Billing Profile
                        </Link>
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['approved_number', 'credit_note']}
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 rounded-xl border border-border bg-card p-3 sm:grid-cols-4 sm:items-end"
                >
                    <div className="space-y-1">
                        <Label htmlFor="inv_search">Search</Label>
                        <Input
                            id="inv_search"
                            placeholder="Invoice #, company, payment ref"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="inv_company">Company</Label>
                        <select
                            id="inv_company"
                            value={companyId}
                            onChange={(e) => setCompanyId(e.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All Companies</option>
                            {companyOptions.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="inv_status">Status</Label>
                        <select
                            id="inv_status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="all">All</option>
                            <option value="issued">Issued</option>
                            <option value="approved">Approved</option>
                            <option value="awaiting_number_approval">
                                Awaiting Approval
                            </option>
                            <option value="credit_note">Credit Notes</option>
                        </select>
                    </div>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Subscription invoices"
                        columns={columns}
                        rows={invoices}
                        rowKey={(row) => row.id}
                        emptyTitle="No subscription invoices found"
                        emptyDescription="Invoices appear here after completed purchases or adjustments."
                        actions={(invoice) => (
                            <div className="flex flex-wrap items-center justify-end gap-1">
                                <AppIconAction
                                    icon={Eye}
                                    label="View invoice"
                                    href={invoice.view_url}
                                    variant="info"
                                />
                                <AppIconAction
                                    icon={Download}
                                    label="Download invoice"
                                    href={invoice.download_url}
                                    variant="outline"
                                />
                                {canManage && invoice.approve_url ? (
                                    <Form
                                        action={invoice.approve_url}
                                        method="post"
                                        className="inline-flex items-center gap-1"
                                    >
                                        <Input
                                            name="approved_number"
                                            defaultValue={
                                                invoice.proposed_number || ''
                                            }
                                            className="h-8 max-w-[8rem] font-mono text-xs"
                                        />
                                        <AppIconAction
                                            icon={Check}
                                            label="Approve number"
                                            type="submit"
                                            variant="default"
                                            size="sm"
                                        />
                                    </Form>
                                ) : null}
                            </div>
                        )}
                    />
                </div>
            </PageShell>
        </>
    );
}
