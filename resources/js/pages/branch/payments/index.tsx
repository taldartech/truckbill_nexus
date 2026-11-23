import { Head, Link } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';

type PaymentRow = {
    id: string;
    payment_date: string | null;
    invoice_number: string | null;
    invoice_id: string;
    amount_received: string;
    tds_amount: string;
    settlement_amount: string;
    payment_mode: string | null;
};

type Props = {
    payments: PaymentRow[];
};

export default function PaymentsIndex({ payments }: Props) {
    return (
        <>
            <Head title="OwnFleet Payments" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <MasterPageHeader title="OwnFleet Invoice Payments" />
                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2">Date</th>
                                <th className="px-3 py-2">Invoice</th>
                                <th className="px-3 py-2">Received</th>
                                <th className="px-3 py-2">TDS</th>
                                <th className="px-3 py-2">Settlement</th>
                                <th className="px-3 py-2">Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="px-3 py-2">
                                        {row.payment_date}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={`/trip-invoices/${row.invoice_id}`}
                                            className="text-primary underline"
                                        >
                                            {row.invoice_number}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        ₹{row.amount_received}
                                    </td>
                                    <td className="px-3 py-2">
                                        ₹{row.tds_amount}
                                    </td>
                                    <td className="px-3 py-2">
                                        ₹{row.settlement_amount}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.payment_mode ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {payments.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">
                            No payments recorded.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
