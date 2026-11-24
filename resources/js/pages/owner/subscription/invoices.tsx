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
    status: string;
    proposed_number: string | null;
    approved_number: string | null;
    financial_year: string;
    amount: string;
    currency: string;
    email_status: string | null;
    approved_at: string | null;
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
    links: { overview: string };
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
                header: 'Number',
                priority: 0,
                className: 'font-mono text-xs',
                cell: (row) =>
                    row.approved_number || row.proposed_number || '—',
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => row.status,
            },
            {
                id: 'fy',
                header: 'FY',
                priority: 2,
                cell: (row) => row.financial_year,
            },
            {
                id: 'amount',
                header: 'Amount',
                priority: 2,
                cell: (row) => `${row.currency} ${row.amount}`,
            },
            {
                id: 'email',
                header: 'Email',
                priority: 3,
                cell: (row) => row.email_status || '—',
            },
            {
                id: 'created',
                header: 'Created',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.created_at),
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
                <Button asChild variant="outline" className="w-fit">
                    <Link href={links.overview}>Back to subscription</Link>
                </Button>
                <ResponsiveDataTable
                    caption="Subscription invoices"
                    columns={columns}
                    rows={invoices}
                    rowKey={(row) => row.id}
                    emptyTitle="No subscription invoices yet"
                    emptyDescription="Invoices appear here after billing events."
                    actions={(invoice) => (
                        <div className="flex justify-end gap-1">
                            <AppIconAction
                                icon={Eye}
                                label="Preview invoice"
                                href={invoice.preview_url}
                                variant="info"
                            />
                            <AppIconAction
                                icon={Download}
                                label="Download HTML"
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
