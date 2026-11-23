import { Form, Head, Link } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { useState } from 'react';

type Props = {
    invoice: {
        id: string;
        invoice_number: string;
        invoice_date: string | null;
        payment_status: string;
        balance_due: string;
        invoice_total: string;
        base_freight_total: string;
        additional_charges_total: string;
        gst_amount: string;
        invoice_tds_rate_percent: string;
        tds_basis_freight: string;
        billing_customer: { name: string } | null;
        lines: Array<{ lr_number: string | null; base_freight: string }>;
        charges: Array<{
            charge_type: string;
            description: string;
            amount: string;
        }>;
        payments: Array<{
            payment_date: string | null;
            amount_received: string;
            tds_amount: string;
            settlement_amount: string;
        }>;
    };
    permissions: {
        can_cancel: boolean;
        can_delete: boolean;
        can_print: boolean;
        can_record_payment: boolean;
    };
    paymentModes: Array<{ id: string; name: string }>;
    sampleBanner: string;
};

export default function TripInvoiceShow({
    invoice,
    permissions,
    paymentModes,
    sampleBanner,
}: Props) {
    const [showPayment, setShowPayment] = useState(false);

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <MasterPageHeader title={`Invoice ${invoice.invoice_number}`} />
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg border p-4 text-sm">
                        <p>
                            <strong>Date:</strong> {invoice.invoice_date}
                        </p>
                        <p>
                            <strong>Customer:</strong>{' '}
                            {invoice.billing_customer?.name}
                        </p>
                        <p>
                            <strong>Payment:</strong>{' '}
                            <span className="capitalize">
                                {invoice.payment_status}
                            </span>
                        </p>
                        <p>
                            <strong>Invoice TDS rate:</strong>{' '}
                            {invoice.invoice_tds_rate_percent}%
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p>Base freight: ₹{invoice.base_freight_total}</p>
                        <p>Additional: ₹{invoice.additional_charges_total}</p>
                        <p>GST: ₹{invoice.gst_amount}</p>
                        <p className="font-semibold">
                            Total: ₹{invoice.invoice_total}
                        </p>
                        <p>Balance due: ₹{invoice.balance_due}</p>
                    </div>
                </div>
                <section>
                    <h2 className="mb-2 text-sm font-semibold">LR lines</h2>
                    <ul className="space-y-1 text-sm">
                        {invoice.lines.map((line, index) => (
                            <li key={index}>
                                {line.lr_number} — ₹{line.base_freight}
                            </li>
                        ))}
                    </ul>
                </section>
                {invoice.charges.length > 0 && (
                    <section>
                        <h2 className="mb-2 text-sm font-semibold">
                            Additional charges
                        </h2>
                        <ul className="space-y-1 text-sm">
                            {invoice.charges.map((c, index) => (
                                <li key={index}>
                                    {c.description} — ₹{c.amount}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <section>
                    <h2 className="mb-2 text-sm font-semibold">
                        Payment history
                    </h2>
                    {invoice.payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No payments yet.
                        </p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {invoice.payments.map((p, index) => (
                                <li key={index}>
                                    {p.payment_date}: received ₹
                                    {p.amount_received}, TDS ₹{p.tds_amount}{' '}
                                    (settlement ₹{p.settlement_amount})
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <div className="flex flex-wrap gap-2">
                    {permissions.can_print && (
                        <Button asChild variant="outline">
                            <Link
                                href={`/trip-invoices/${invoice.id}/print`}
                                target="_blank"
                            >
                                Print sample
                            </Link>
                        </Button>
                    )}
                    {permissions.can_record_payment &&
                        invoice.balance_due !== '0.00' && (
                            <Button onClick={() => setShowPayment((v) => !v)}>
                                Record payment
                            </Button>
                        )}
                </div>
                {showPayment && (
                    <Form
                        action={`/trip-invoices/${invoice.id}/payments`}
                        method="post"
                        className="max-w-xl space-y-3 rounded-lg border p-4"
                    >
                        {({ processing }) => (
                            <>
                                <label className="flex flex-col gap-1 text-sm">
                                    Payment date
                                    <input
                                        type="date"
                                        name="payment_date"
                                        className="rounded-md border px-2 py-1"
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-sm">
                                    Mode
                                    <select
                                        name="payment_mode_id"
                                        className="rounded-md border px-2 py-1"
                                    >
                                        <option value="">—</option>
                                        {paymentModes.map((mode) => (
                                            <option
                                                key={mode.id}
                                                value={mode.id}
                                            >
                                                {mode.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="grid gap-2 md:grid-cols-2">
                                    <label className="flex flex-col gap-1 text-sm">
                                        Amount received
                                        <input
                                            name="amount_received"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            className="rounded-md border px-2 py-1"
                                        />
                                    </label>
                                    <label className="flex flex-col gap-1 text-sm">
                                        TDS
                                        <input
                                            name="tds_amount"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            className="rounded-md border px-2 py-1"
                                        />
                                    </label>
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="fill_net"
                                        value="1"
                                    />
                                    Fill net (auto amount received)
                                </label>
                                <Button type="submit" disabled={processing}>
                                    Save payment
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {permissions.can_cancel && (
                    <Form
                        action={`/trip-invoices/${invoice.id}/cancel`}
                        method="post"
                        className="max-w-md space-y-2"
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Cancel reason
                            <textarea
                                name="cancellation_remark"
                                required
                                className="min-h-20 rounded-md border px-2 py-1"
                            />
                        </label>
                        <Button type="submit" variant="destructive">
                            Cancel invoice
                        </Button>
                    </Form>
                )}
                {permissions.can_delete && (
                    <Form
                        action={`/trip-invoices/${invoice.id}`}
                        method="delete"
                    >
                        <Button type="submit" variant="outline">
                            Soft delete (latest only)
                        </Button>
                    </Form>
                )}
                <Button asChild variant="ghost">
                    <Link href="/trip-invoices">Back to list</Link>
                </Button>
            </div>
        </>
    );
}
