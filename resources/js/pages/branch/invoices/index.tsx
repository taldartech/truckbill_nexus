import { Head, Link, router } from '@inertiajs/react';
import { Eye, Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type InvoiceRow = {
    id: string;
    source: string;
    source_label: string;
    invoice_number: string;
    invoice_date: string | null;
    billing_customer_name: string | null;
    lr_numbers: string[];
    invoice_status: string;
    payment_status: string;
    invoice_total: string;
    balance_due: string;
    urls: { show: string };
};

type Props = {
    filters: {
        q: string;
        customer_id: string | null;
        source: string | null;
        payment_status: string | null;
        invoice_status: string | null;
    };
    permissions: {
        can_create_own_fleet: boolean;
        can_create_broker: boolean;
        can_view_freight: boolean;
    };
    sourceOptions: Array<{ value: string; label: string }>;
    customerOptions: Array<{ id: string; name: string }>;
    invoices: {
        data: InvoiceRow[];
        meta: { total: number };
    };
    urls: { index: string; create: string };
    sampleBanner: string;
};

export default function CustomerInvoicesIndex({
    filters,
    permissions,
    sourceOptions,
    customerOptions,
    invoices,
    urls,
    sampleBanner,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const [customerId, setCustomerId] = useState(filters.customer_id ?? '');
    const [source, setSource] = useState(filters.source ?? '');
    const [paymentStatus, setPaymentStatus] = useState(
        filters.payment_status ?? '',
    );
    const [invoiceStatus, setInvoiceStatus] = useState(
        filters.invoice_status ?? '',
    );

    const canCreate =
        permissions.can_create_own_fleet || permissions.can_create_broker;

    const columns = useMemo<DataTableColumn<InvoiceRow>[]>(
        () => [
            {
                id: 'number',
                header: 'Invoice',
                priority: 0,
                className: 'whitespace-nowrap font-medium',
                cell: (row) => row.invoice_number,
            },
            {
                id: 'date',
                header: 'Date',
                priority: 0,
                className: 'whitespace-nowrap',
                cell: (row) => row.invoice_date ?? '—',
            },
            {
                id: 'customer',
                header: 'Customer',
                priority: 1,
                className: 'max-w-[12rem] truncate',
                cell: (row) => row.billing_customer_name ?? '—',
            },
            {
                id: 'source',
                header: 'Source',
                priority: 1,
                className: 'whitespace-nowrap',
                cell: (row) => row.source_label,
            },
            {
                id: 'lrs',
                header: 'LR refs',
                priority: 2,
                className: 'max-w-[14rem] truncate',
                cell: (row) => row.lr_numbers.join(', ') || '—',
            },
            {
                id: 'total',
                header: 'Total',
                priority: 2,
                className: 'whitespace-nowrap tabular-nums',
                cell: (row) =>
                    permissions.can_view_freight
                        ? `₹${row.invoice_total}`
                        : '—',
            },
            {
                id: 'balance',
                header: 'Balance',
                priority: 2,
                className: 'whitespace-nowrap tabular-nums',
                cell: (row) =>
                    permissions.can_view_freight ? `₹${row.balance_due}` : '—',
            },
            {
                id: 'status',
                header: 'Status',
                priority: 3,
                className: 'whitespace-nowrap capitalize',
                cell: (row) => `${row.invoice_status} / ${row.payment_status}`,
            },
        ],
        [permissions.can_view_freight],
    );

    return (
        <>
            <Head title="Customer Invoices" />
            <PageShell
                title="Invoices"
                description="Customer billing across Own Fleet and Broker lorry receipts."
                actions={
                    canCreate ? (
                        <Button asChild variant="primary" className="gap-2">
                            <Link href={urls.create}>
                                <Plus className="size-4" />
                                Create invoice
                            </Link>
                        </Button>
                    ) : null
                }
            >
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>

                <FormSection title="Filters">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                urls.index,
                                {
                                    q: q || undefined,
                                    customer_id: customerId || undefined,
                                    source: source || undefined,
                                    payment_status: paymentStatus || undefined,
                                    invoice_status: invoiceStatus || undefined,
                                },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="grid gap-3 md:grid-cols-2 lg:grid-cols-3"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="q">Search invoice no.</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="customer_id">Customer</Label>
                            <select
                                id="customer_id"
                                value={customerId}
                                onChange={(e) => setCustomerId(e.target.value)}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">All customers</option>
                                {customerOptions.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="source">Source</Label>
                            <select
                                id="source"
                                value={source}
                                onChange={(e) => setSource(e.target.value)}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">All sources</option>
                                {sourceOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="payment_status">
                                Payment status
                            </Label>
                            <Input
                                id="payment_status"
                                value={paymentStatus}
                                onChange={(e) =>
                                    setPaymentStatus(e.target.value)
                                }
                                placeholder="pending, partial, paid…"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="invoice_status">
                                Invoice status
                            </Label>
                            <Input
                                id="invoice_status"
                                value={invoiceStatus}
                                onChange={(e) =>
                                    setInvoiceStatus(e.target.value)
                                }
                            />
                        </div>
                        <div className="flex items-end">
                            <Button
                                type="submit"
                                variant="secondary"
                                className="gap-2"
                            >
                                <Filter className="size-4" />
                                Apply
                            </Button>
                        </div>
                    </form>
                </FormSection>

                <ResponsiveDataTable
                    caption="Customer invoices"
                    columns={columns}
                    rows={invoices.data}
                    rowKey={(row) => `${row.source}:${row.id}`}
                    emptyTitle="No customer invoices"
                    emptyDescription="Create an invoice from eligible lorry receipts."
                    actions={(row) => (
                        <AppIconAction
                            icon={Eye}
                            label="View invoice"
                            href={row.urls.show}
                            variant="info"
                        />
                    )}
                />
            </PageShell>
        </>
    );
}
