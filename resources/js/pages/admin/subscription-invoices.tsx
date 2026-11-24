import { Form, Head, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useMemo } from 'react';
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
import { Input } from '@/components/ui/input';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

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
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => <WorkspaceStatusBadge status={row.status} />,
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
            <PageShell
                title="Subscription invoice numbers"
                description={issuer_note}
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} keys={['approved_number']} />

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
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
                                <Input
                                    name="approved_number"
                                    defaultValue={invoice.proposed_number || ''}
                                    aria-label={`Approved number for ${invoice.company_name || invoice.id}`}
                                    className="h-8 max-w-[9rem] font-mono text-xs"
                                />
                                <AppIconAction
                                    icon={Check}
                                    label="Approve invoice number"
                                    type="submit"
                                    variant="default"
                                    size="sm"
                                />
                            </Form>
                        )}
                    />
                </div>
            </PageShell>
        </>
    );
}
