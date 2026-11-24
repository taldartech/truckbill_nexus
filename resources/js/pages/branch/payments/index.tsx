import { Head } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useMemo } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { formatDisplayDate } from '@/lib/dates';

type PaymentRow = {
    id: string;
    payment_date: string | null;
    invoice_number: string | null;
    invoice_id: string;
    source: string;
    source_label: string;
    amount_received: string;
    tds_amount: string;
    settlement_amount: string;
    payment_mode: string | null;
    invoice_show_url: string | null;
};

type Props = {
    payments: PaymentRow[];
};

function formatMoney(value: string): string {
    return `₹${value}`;
}

export default function PaymentsIndex({ payments }: Props) {
    const settlementTotal = useMemo(
        () =>
            payments.reduce(
                (sum, row) => sum + Number.parseFloat(row.settlement_amount),
                0,
            ),
        [payments],
    );

    const columns = useMemo<DataTableColumn<PaymentRow>[]>(
        () => [
            {
                id: 'date',
                header: 'Date',
                priority: 0,
                cell: (row) => formatDisplayDate(row.payment_date),
            },
            {
                id: 'invoice',
                header: 'Invoice',
                priority: 1,
                cell: (row) => row.invoice_number ?? '—',
            },
            {
                id: 'source',
                header: 'Source',
                priority: 2,
                cell: (row) => row.source_label,
            },
            {
                id: 'received',
                header: 'Received',
                priority: 2,
                cell: (row) => formatMoney(row.amount_received),
            },
            {
                id: 'tds',
                header: 'TDS',
                priority: 2,
                cell: (row) => formatMoney(row.tds_amount),
            },
            {
                id: 'settlement',
                header: 'Settlement',
                priority: 1,
                cell: (row) => formatMoney(row.settlement_amount),
            },
            {
                id: 'mode',
                header: 'Mode',
                priority: 3,
                cell: (row) => row.payment_mode ?? '—',
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Customer Invoice Receipts" />
            <PageShell
                title="Customer Invoice Receipts"
                description="Receipts against customer invoices (Own Fleet and Broker). Transporter voucher payments remain under Broker → Voucher Payments."
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <MetricCard
                        label="Payments"
                        value={payments.length}
                        tone="blue"
                    />
                    <MetricCard
                        label="Settlement total"
                        value={
                            Number.isFinite(settlementTotal)
                                ? formatMoney(settlementTotal.toFixed(2))
                                : '—'
                        }
                        tone="green"
                    />
                </div>

                <ResponsiveDataTable
                    caption="Invoice payments"
                    columns={columns}
                    rows={payments}
                    rowKey={(row) => row.id}
                    emptyTitle="No payments recorded"
                    emptyDescription="Record a payment from a customer invoice."
                    actions={(row) => (
                        <AppIconAction
                            icon={Eye}
                            label="View invoice"
                            href={
                                row.invoice_show_url ??
                                `/trip-invoices/${row.invoice_id}`
                            }
                            variant="info"
                        />
                    )}
                />
            </PageShell>
        </>
    );
}
