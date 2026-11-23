import { Head } from '@inertiajs/react';

type Props = {
    invoice: {
        invoice_number: string;
        invoice_date: string | null;
        billing_customer: { name: string; gstin: string | null } | null;
        lines: Array<{ lr_number: string | null; base_freight: string }>;
        base_freight_total: string;
        gst_amount: string;
        invoice_total: string;
        particulars_mode: string;
    };
    company: { name: string; gstin: string | null };
    sampleBanner: string;
};

export default function TripInvoicePrint({
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
                <header className="flex justify-between">
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
                        <p>Date: {invoice.invoice_date}</p>
                    </div>
                </header>
                <p>
                    <strong>Bill To:</strong> {invoice.billing_customer?.name}
                    {invoice.billing_customer?.gstin
                        ? ` (${invoice.billing_customer.gstin})`
                        : ''}
                </p>
                <table className="w-full border-collapse border text-left">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2">Particulars</th>
                            <th className="py-2 text-right">Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.particulars_mode === 'combined_freight' ? (
                            <tr className="border-b">
                                <td className="py-2">
                                    Freight (see annexure:{' '}
                                    {invoice.lines
                                        .map((l) => l.lr_number)
                                        .join(', ')}
                                    )
                                </td>
                                <td className="py-2 text-right">
                                    {invoice.base_freight_total}
                                </td>
                            </tr>
                        ) : (
                            invoice.lines.map((line, index) => (
                                <tr key={index} className="border-b">
                                    <td className="py-2">
                                        LR {line.lr_number}
                                    </td>
                                    <td className="py-2 text-right">
                                        {line.base_freight}
                                    </td>
                                </tr>
                            ))
                        )}
                        <tr className="border-b">
                            <td className="py-2">GST</td>
                            <td className="py-2 text-right">
                                {invoice.gst_amount}
                            </td>
                        </tr>
                        <tr>
                            <td className="py-2 font-semibold">Total</td>
                            <td className="py-2 text-right font-semibold">
                                {invoice.invoice_total}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </>
    );
}
