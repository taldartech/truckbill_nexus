import { Head, Link } from '@inertiajs/react';
import { Download, Eye } from 'lucide-react';
import { useMemo } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateSmart } from '@/lib/dates';

type PaymentRow = {
    id: string;
    purpose: string;
    amount: string;
    currency: string;
    status: string;
    verification_status: string;
    gateway: string;
    gateway_order_id: string | null;
    gateway_payment_id: string | null;
    invoice_status?: string | null;
    invoice_label?: string | null;
    invoice_number?: string | null;
    invoice_url?: string | null;
    invoice_download_url?: string | null;
    created_at: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    gate_note: string;
    payments: PaymentRow[];
    links: { overview: string; purchase?: string; invoices?: string };
};

export default function SubscriptionPayments({
    company,
    ownerPanelScope,
    gate_note,
    payments,
    links,
}: Props) {
    const columns = useMemo<DataTableColumn<PaymentRow>[]>(
        () => [
            {
                id: 'purpose',
                header: 'Purpose',
                priority: 0,
                className: 'capitalize',
                cell: (row) => row.purpose.replaceAll('_', ' '),
            },
            {
                id: 'status',
                header: 'Payment Status',
                priority: 1,
                cell: (row) => `${row.status} / ${row.verification_status}`,
            },
            {
                id: 'amount',
                header: 'Amount',
                priority: 1,
                cell: (row) => `${row.currency} ${row.amount}`,
            },
            {
                id: 'invoice',
                header: 'Invoice',
                priority: 2,
                cell: (row) => {
                    if (row.invoice_number) {
                        return (
                            <span className="font-mono text-xs">
                                {row.invoice_number}
                            </span>
                        );
                    }
                    if (row.invoice_status === 'pending_approval') {
                        return (
                            <WorkspaceStatusBadge status="Invoice Pending Approval" />
                        );
                    }

                    return '—';
                },
            },
            {
                id: 'gateway_order',
                header: 'Order / Payment Ref',
                priority: 3,
                className: 'font-mono text-xs',
                cell: (row) =>
                    row.gateway_payment_id || row.gateway_order_id || '—',
            },
            {
                id: 'created',
                header: 'Date',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.created_at),
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Payments · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Payment history"
            >
                <p className="text-sm text-muted-foreground">{gate_note}</p>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={links.overview}>Back to subscription</Link>
                    </Button>
                    {links.purchase ? (
                        <Button asChild>
                            <Link href={links.purchase}>Buy / Upgrade</Link>
                        </Button>
                    ) : null}
                    {links.invoices ? (
                        <Button asChild variant="outline">
                            <Link href={links.invoices}>View Invoices</Link>
                        </Button>
                    ) : null}
                </div>
                <ResponsiveDataTable
                    caption="Payment history"
                    columns={columns}
                    rows={payments}
                    rowKey={(row) => row.id}
                    emptyTitle="No payment intents recorded"
                    emptyDescription="Verified payments and tax invoices for this company will appear here."
                    actions={(row) => (
                        <div className="flex justify-end gap-1">
                            {row.invoice_url ? (
                                <AppIconAction
                                    icon={Eye}
                                    label="View invoice"
                                    href={row.invoice_url}
                                    variant="info"
                                />
                            ) : null}
                            {row.invoice_download_url ? (
                                <AppIconAction
                                    icon={Download}
                                    label="Download invoice"
                                    href={row.invoice_download_url}
                                    variant="outline"
                                />
                            ) : null}
                        </div>
                    )}
                />
            </CompanyOwnerPageShell>
        </>
    );
}
