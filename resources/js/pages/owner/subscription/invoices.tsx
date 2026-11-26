import { Form, Head, Link } from '@inertiajs/react';
import { Download, Eye, Mail } from 'lucide-react';
import { useMemo } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
import { formatDisplayDateSmart } from '@/lib/dates';

type InvoiceRow = {
    id: string;
    document_type?: string;
    status: string;
    invoice_number?: string | null;
    proposed_number: string | null;
    approved_number: string | null;
    financial_year: string;
    taxable_amount?: string;
    total_tax_amount?: string;
    amount: string;
    currency: string;
    issued_at?: string | null;
    email_status: string | null;
    created_at: string | null;
    preview_url: string;
    download_url: string;
    email_url: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    issuer_note: string;
    invoices: InvoiceRow[];
    links: { overview: string; payments?: string };
};

export default function SubscriptionInvoices({
    company,
    ownerPanelScope,
    issuer_note,
    invoices,
    links,
}: Props) {
    const columns = useMemo<DataTableColumn<InvoiceRow>[]>(
        () => [
            {
                id: 'number',
                header: 'Invoice Number',
                priority: 0,
                className: 'font-mono text-xs',
                cell: (row) =>
                    row.invoice_number ||
                    row.approved_number ||
                    row.proposed_number ||
                    '—',
            },
            {
                id: 'type',
                header: 'Type',
                priority: 2,
                className: 'capitalize text-xs',
                cell: (row) =>
                    (row.document_type || 'tax_invoice').replaceAll('_', ' '),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => row.status,
            },
            {
                id: 'taxable',
                header: 'Taxable',
                priority: 2,
                cell: (row) =>
                    `${row.currency} ${row.taxable_amount || row.amount}`,
            },
            {
                id: 'tax',
                header: 'GST',
                priority: 2,
                cell: (row) =>
                    `${row.currency} ${row.total_tax_amount || '0.00'}`,
            },
            {
                id: 'amount',
                header: 'Grand Total',
                priority: 1,
                cell: (row) => `${row.currency} ${row.amount}`,
            },
            {
                id: 'issued',
                header: 'Issued Date',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) =>
                    formatDisplayDateSmart(row.issued_at || row.created_at),
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Invoices · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Subscription invoices"
            >
                <p className="text-sm text-muted-foreground">{issuer_note}</p>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={links.overview}>Back to subscription</Link>
                    </Button>
                    {links.payments ? (
                        <Button asChild variant="outline">
                            <Link href={links.payments}>Payment History</Link>
                        </Button>
                    ) : null}
                </div>
                <ResponsiveDataTable
                    caption="Subscription invoices"
                    columns={columns}
                    rows={invoices}
                    rowKey={(row) => row.id}
                    emptyTitle="No subscription invoices yet"
                    emptyDescription="Invoices generate automatically after verified payment."
                    actions={(invoice) => (
                        <div className="flex justify-end gap-1">
                            <AppIconAction
                                icon={Eye}
                                label="View invoice"
                                href={invoice.preview_url}
                                variant="info"
                            />
                            <AppIconAction
                                icon={Download}
                                label="Download / Print"
                                href={invoice.download_url}
                                variant="outline"
                            />
                            {invoice.email_url ? (
                                <Form
                                    action={invoice.email_url}
                                    method="post"
                                    className="inline-flex"
                                >
                                    {({ processing }) => (
                                        <AppIconAction
                                            icon={Mail}
                                            label="Email invoice"
                                            type="submit"
                                            variant="secondary"
                                            loading={processing}
                                            disabled={processing}
                                        />
                                    )}
                                </Form>
                            ) : null}
                        </div>
                    )}
                />
            </CompanyOwnerPageShell>
        </>
    );
}
