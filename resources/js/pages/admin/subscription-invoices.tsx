import { Form, Head, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
type InvoiceRow = {
    id: string;
    company_name: string | null;
    status: string;
    proposed_number: string | null;
    financial_year: string | null;
    amount: string;
    currency: string;
    approve_url: string;
};

type Props = {
    issuer_note: string;
    invoices: InvoiceRow[];
};

export default function AdminSubscriptionInvoices({
    issuer_note,
    invoices,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: { approved_number?: string };
    }>().props;

    const columns = useMemo<DataTableColumn<InvoiceRow>[]>(
        () => [
            {
                id: 'company',
                header: 'Company',
                priority: 0,
                cell: (row) => row.company_name || '—',
            },
            {
                id: 'proposed',
                header: 'Proposed',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.proposed_number || '—',
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
        ],
        [],
    );

    return (
        <>
            <Head title="Subscription invoice approval" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Super Admin
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Subscription invoice numbers
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {issuer_note}
                    </p>
                </div>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.approved_number ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.approved_number}
                    </p>
                ) : null}
                <ResponsiveDataTable
                    caption="Invoices awaiting approval"
                    columns={columns}
                    rows={invoices}
                    rowKey={(row) => row.id}
                    emptyTitle="No invoices awaiting approval"
                    emptyDescription="Approved invoices are removed from this queue."
                    actions={(invoice) => (
                        <Form
                            action={invoice.approve_url}
                            method="post"
                            className="flex flex-wrap items-center justify-end gap-2"
                        >
                            <input
                                name="approved_number"
                                defaultValue={invoice.proposed_number || ''}
                                aria-label={`Approved number for ${invoice.company_name || invoice.id}`}
                                className="max-w-[7rem] rounded-md border border-border bg-background px-2 py-1 font-mono text-xs"
                            />
                            <Button type="submit" size="sm">
                                Approve
                            </Button>
                        </Form>
                    )}
                />
            </div>
        </>
    );
}
