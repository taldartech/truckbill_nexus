import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Download, Eye } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
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
    company_id: string;
    company_name: string | null;
    purchase_details: string;
    purchase_type: string | null;
    payment_reference: string | null;
    payment_date: string | null;
    invoice_number: string | null;
    billing_profile_id: string | null;
    billing_profile_name: string | null;
    status: string;
    is_approved: boolean;
    financial_year: string | null;
    taxable_amount: string;
    total_tax_amount: string;
    amount: string;
    currency: string;
    invoice_date: string | null;
    statutory_deadline: string | null;
    is_overdue: boolean;
    view_url: string;
    download_url: string | null;
    approve_url: string | null;
};

type Props = {
    filters: {
        customer_company: string;
        billing_profile_id: string;
        q: string;
        status: string;
        date_from: string;
        date_to: string;
    };
    companyOptions: { id: string; name: string }[];
    billingProfileOptions: { id: string; name: string }[];
    canManage: boolean;
    invoices: InvoiceRow[];
    links: { index: string; billing_profiles: string };
};

export default function AdminSubscriptionInvoices({
    filters,
    companyOptions,
    billingProfileOptions,
    canManage,
    invoices,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [search, setSearch] = useState(filters.q);
    const [companyId, setCompanyId] = useState(filters.customer_company);
    const [billingProfileId, setBillingProfileId] = useState(
        filters.billing_profile_id,
    );
    const [status, setStatus] = useState(filters.status);
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);

    const applyFilters = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            links.index,
            {
                q: search || undefined,
                customer_company: companyId || undefined,
                billing_profile_id: billingProfileId || undefined,
                status,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<InvoiceRow>[]>(
        () => [
            {
                id: 'company',
                header: 'Company',
                priority: 0,
                cell: (row) => (
                    <div className="font-medium">{row.company_name || '—'}</div>
                ),
            },
            {
                id: 'purchase',
                header: 'Subscription / Purchase',
                priority: 1,
                cell: (row) => (
                    <div className="text-xs">
                        <div>{row.purchase_details}</div>
                        <div className="text-muted-foreground capitalize">
                            {row.document_type.replaceAll('_', ' ')}
                        </div>
                    </div>
                ),
            },
            {
                id: 'payment_ref',
                header: 'Payment Ref',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.payment_reference || '—',
            },
            {
                id: 'payment_date',
                header: 'Payment Date',
                priority: 2,
                className: 'text-xs text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.payment_date),
            },
            {
                id: 'amount',
                header: 'Amount',
                priority: 0,
                cell: (row) => (
                    <div className="text-xs">
                        <div className="font-medium">
                            {row.currency} {row.amount}
                        </div>
                        <div className="text-muted-foreground">
                            Taxable {row.taxable_amount} + GST{' '}
                            {row.total_tax_amount}
                        </div>
                    </div>
                ),
            },
            {
                id: 'number',
                header: 'Invoice Number',
                priority: 0,
                className: 'font-mono text-xs',
                cell: (row) => row.invoice_number || '—',
            },
            {
                id: 'billing_profile',
                header: 'Billing Profile',
                priority: 2,
                className: 'text-xs',
                cell: (row) => row.billing_profile_name || '—',
            },
            {
                id: 'status',
                header: 'Invoice Status',
                priority: 1,
                cell: (row) => (
                    <div className="flex flex-col gap-1">
                        <WorkspaceStatusBadge
                            status={
                                row.is_approved
                                    ? row.status
                                    : 'pending_approval'
                            }
                        />
                        {!row.is_approved && row.statutory_deadline ? (
                            <span
                                className={
                                    row.is_overdue
                                        ? 'text-[11px] font-medium text-destructive'
                                        : 'text-[11px] text-muted-foreground'
                                }
                            >
                                Due by{' '}
                                {formatDisplayDateSmart(row.statutory_deadline)}
                            </span>
                        ) : null}
                    </div>
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Subscription Invoice Register" />
            <PageShell
                title="Subscription Invoices"
                description="Approve pending subscription invoices with a Billing Profile and manual Invoice Number, or download issued documents."
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={links.billing_profiles}>
                            Billing Profiles
                        </Link>
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['approval', 'credit_note']}
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 rounded-xl border border-border bg-card p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end"
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
                        <Label htmlFor="inv_status">Status</Label>
                        <select
                            id="inv_status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="all">All</option>
                            <option value="pending_approval">
                                Pending Approval
                            </option>
                            <option value="approved">Approved</option>
                            <option value="credit_note">Credit Notes</option>
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="inv_company">Customer Company</Label>
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
                        <Label htmlFor="inv_billing_profile">
                            Billing Profile
                        </Label>
                        <select
                            id="inv_billing_profile"
                            value={billingProfileId}
                            onChange={(e) =>
                                setBillingProfileId(e.target.value)
                            }
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All Billing Profiles</option>
                            {billingProfileOptions.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="space-y-1">
                            <Label htmlFor="inv_from">From</Label>
                            <Input
                                id="inv_from"
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="inv_to">To</Label>
                            <Input
                                id="inv_to"
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                        </div>
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
                        emptyDescription="Invoices appear here after verified subscription payments."
                        actions={(invoice) => (
                            <div className="flex flex-wrap items-center justify-end gap-1.5">
                                {canManage && invoice.approve_url ? (
                                    <Button asChild size="sm">
                                        <Link href={invoice.approve_url}>
                                            <Check className="size-3.5" />
                                            Approve Invoice
                                        </Link>
                                    </Button>
                                ) : null}
                                {invoice.is_approved ? (
                                    <>
                                        <AppIconAction
                                            icon={Eye}
                                            label="View invoice"
                                            href={invoice.view_url}
                                            variant="info"
                                        />
                                        {invoice.download_url ? (
                                            <AppIconAction
                                                icon={Download}
                                                label="Download invoice"
                                                href={invoice.download_url}
                                                variant="outline"
                                            />
                                        ) : null}
                                    </>
                                ) : !canManage ? (
                                    <AppIconAction
                                        icon={Eye}
                                        label="View details"
                                        href={invoice.view_url}
                                        variant="info"
                                    />
                                ) : null}
                            </div>
                        )}
                    />
                </div>
            </PageShell>
        </>
    );
}
