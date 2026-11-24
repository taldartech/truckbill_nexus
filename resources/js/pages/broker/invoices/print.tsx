import { Head } from '@inertiajs/react';

type Props = {
    invoice: {
        invoice_number: string;
        invoice_date: string | null;
        billing_customer_name: string | null;
        taxable_total: string | null;
        gst_amount: string | null;
        invoice_total: string | null;
        amount_received: string | null;
        customer_tds_amount: string | null;
        balance_due: string | null;
        payment_status: string;
        lines: Array<{
            lr_number: string;
            memo_number: string | null;
            pod_number: string | null;
            freight_amount: string | null;
            charges_amount: string | null;
            shortage_damage_amount: string | null;
            line_total: string | null;
        }>;
        payments: Array<{
            payment_date: string | null;
            amount_received: string | null;
            tds_amount: string | null;
            deduction_amount: string | null;
        }>;
    };
    company: { name: string; gstin: string | null };
    sampleBanner: string;
};

export default function BrokerInvoicePrint({
    invoice,
    company,
    sampleBanner,
}: Props) {
    return (
        <>
            <Head title={`Print ${invoice.invoice_number}`} />
            <div className="mx-auto max-w-3xl space-y-4 p-8 text-sm text-black print:p-0">
                <p className="border border-amber-600 bg-amber-50 px-3 py-2 font-medium">
                    {sampleBanner}
                </p>
                <header className="flex justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">
                            {company.name}
                        </h1>
                        <p>GSTIN: {company.gstin ?? '—'}</p>
                    </div>
                    <div className="text-right">
                        <p className="text-lg font-semibold">
                            Invoice {invoice.invoice_number}
                        </p>
                        <p>Date: {invoice.invoice_date ?? '—'}</p>
                        <p>Payment: {invoice.payment_status}</p>
                    </div>
                </header>
                <p>
                    <strong>Bill To:</strong>{' '}
                    {invoice.billing_customer_name ?? '—'}
                </p>
                <table className="w-full border-collapse border text-left">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2">Particulars</th>
                            <th className="py-2 text-right">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.lines.map((line) => (
                            <tr key={line.lr_number} className="border-b">
                                <td className="py-2">
                                    LR {line.lr_number}
                                    {line.memo_number
                                        ? ` · Memo ${line.memo_number}`
                                        : ''}
                                    {line.pod_number
                                        ? ` · POD ${line.pod_number}`
                                        : ''}
                                </td>
                                <td className="py-2 text-right">
                                    {line.line_total ?? '—'}
                                </td>
                            </tr>
                        ))}
                        <tr className="border-b">
                            <td className="py-2">Taxable</td>
                            <td className="py-2 text-right">
                                {invoice.taxable_total ?? '—'}
                            </td>
                        </tr>
                        <tr className="border-b">
                            <td className="py-2">GST</td>
                            <td className="py-2 text-right">
                                {invoice.gst_amount ?? '—'}
                            </td>
                        </tr>
                        <tr className="border-b">
                            <td className="py-2 font-semibold">Total</td>
                            <td className="py-2 text-right font-semibold">
                                {invoice.invoice_total ?? '—'}
                            </td>
                        </tr>
                        <tr className="border-b">
                            <td className="py-2">Received / TDS</td>
                            <td className="py-2 text-right">
                                {invoice.amount_received ?? '—'} /{' '}
                                {invoice.customer_tds_amount ?? '—'}
                            </td>
                        </tr>
                        <tr>
                            <td className="py-2 font-semibold">Balance due</td>
                            <td className="py-2 text-right font-semibold">
                                {invoice.balance_due ?? '—'}
                            </td>
                        </tr>
                    </tbody>
                </table>
                {invoice.payments.length > 0 ? (
                    <section>
                        <h2 className="mb-2 font-semibold">Settlements</h2>
                        <ul className="space-y-1">
                            {invoice.payments.map((payment, index) => (
                                <li key={`${payment.payment_date}-${index}`}>
                                    {payment.payment_date ?? '—'} · received{' '}
                                    {payment.amount_received ?? '—'}
                                    {payment.tds_amount
                                        ? ` · TDS ${payment.tds_amount}`
                                        : ''}
                                    {payment.deduction_amount
                                        ? ` · deduction ${payment.deduction_amount}`
                                        : ''}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </>
    );
}
