import { Form, Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
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
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Owner Panel · Subscription invoices
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {company.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Scope: {ownerPanelScope}
                    </p>
                </div>
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
                        <div className="flex flex-wrap justify-end gap-2">
                            <Link
                                href={invoice.preview_url}
                                className="text-sm underline-offset-2 hover:underline"
                            >
                                Preview
                            </Link>
                            <a
                                href={invoice.download_url}
                                className="text-sm underline-offset-2 hover:underline"
                                target="_blank"
                                rel="noreferrer"
                            >
                                HTML
                            </a>
                            {invoice.email_url ? (
                                <Form
                                    action={invoice.email_url}
                                    method="post"
                                    className="inline"
                                >
                                    <button
                                        type="submit"
                                        className="text-sm underline-offset-2 hover:underline"
                                    >
                                        Email
                                    </button>
                                </Form>
                            ) : null}
                        </div>
                    )}
                />
            </div>
        </>
    );
}
