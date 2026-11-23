import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
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
    created_at: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    gate_note: string;
    payments: PaymentRow[];
    links: { overview: string };
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
                cell: (row) => row.purpose,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => `${row.status} / ${row.verification_status}`,
            },
            {
                id: 'amount',
                header: 'Amount',
                priority: 2,
                cell: (row) => `${row.currency} ${row.amount}`,
            },
            {
                id: 'gateway_order',
                header: 'Gateway order',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.gateway_order_id || '—',
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
            <Head title={`Payments · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Payment history"
            >
                <p className="text-sm text-muted-foreground">{gate_note}</p>
                <Button asChild variant="outline" className="w-fit">
                    <Link href={links.overview}>Back to subscription</Link>
                </Button>
                <ResponsiveDataTable
                    caption="Payment history"
                    columns={columns}
                    rows={payments}
                    rowKey={(row) => row.id}
                    emptyTitle="No payment intents recorded"
                    emptyDescription="Payment attempts for this company will appear here."
                />
            </CompanyOwnerPageShell>
        </>
    );
}
