import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    invoice: {
        id: string;
        invoice_number: string;
        invoice_date: string;
        status: string;
        payment_status: string;
        billing_customer_name: string | null;
        billing_customer_gstin: string | null;
        taxable_total: string | null;
        gst_amount: string | null;
        invoice_total: string | null;
        amount_received: string | null;
        customer_tds_amount: string | null;
        balance_due: string | null;
        cancellation_remark: string | null;
        lines: Array<{
            id: string;
            description: string;
            lr_number: string | null;
            freight_amount: string | null;
            charges_amount: string | null;
            line_total: string | null;
        }>;
        payments: Array<{
            id: string;
            payment_date: string;
            amount_received: string | null;
            tds_amount: string | null;
            deduction_amount: string | null;
            payment_mode: string;
            reference: string | null;
            remark: string | null;
        }>;
    };
    bankCashAccounts: Array<{ id: string; name: string; account_type: string }>;
    permissions: {
        can_view_freight: boolean;
        can_record_payment: boolean;
        can_cancel: boolean;
    };
    urls: {
        index: string;
        unified: string;
        record_payment: string;
        cancel: string;
    };
};

export default function PartloadInvoiceShow({
    invoice,
    bankCashAccounts,
    permissions,
    urls,
}: Props) {
    const paymentForm = useForm({
        amount_received: '',
        tds_amount: '0',
        deduction_amount: '0',
        payment_date: new Date().toISOString().slice(0, 10),
        payment_mode: 'bank',
        bank_cash_account_id: bankCashAccounts[0]?.id ?? '',
        reference: '',
        remark: '',
    });

    const cancelForm = useForm({
        cancellation_remark: '',
    });

    const money = (value: string | null) =>
        permissions.can_view_freight && value !== null ? `₹${value}` : '—';

    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        paymentForm.post(urls.record_payment, {
            preserveScroll: true,
            onSuccess: () =>
                paymentForm.setData({
                    ...paymentForm.data,
                    amount_received: '',
                    tds_amount: '0',
                    deduction_amount: '0',
                    reference: '',
                    remark: '',
                }),
        });
    };

    const submitCancel = (e: FormEvent) => {
        e.preventDefault();
        cancelForm.post(urls.cancel, { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Partload Invoice ${invoice.invoice_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Partload Invoice ${invoice.invoice_number}`}
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.index}>Partload Invoices</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.unified}>All Invoices</Link>
                            </Button>
                        </div>
                    }
                >
                    <p className="border-b bg-amber-50 px-4 py-2 text-xs text-amber-900">
                        SAMPLE / NON-PRODUCTION — Not a legally operative tax
                        invoice.
                    </p>

                    <div className="grid gap-4 p-4 md:grid-cols-4">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Customer
                            </div>
                            <div className="font-medium">
                                {invoice.billing_customer_name ?? '—'}
                            </div>
                            {invoice.billing_customer_gstin ? (
                                <div className="text-xs text-muted-foreground">
                                    GSTIN {invoice.billing_customer_gstin}
                                </div>
                            ) : null}
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Invoice Date / Status
                            </div>
                            <div className="flex items-center gap-2 font-medium">
                                <span>{invoice.invoice_date}</span>
                                <Badge variant="outline">
                                    {invoice.status}
                                </Badge>
                                <Badge variant="secondary">
                                    {invoice.payment_status}
                                </Badge>
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Taxable / GST / Total
                            </div>
                            <div className="font-medium">
                                {money(invoice.taxable_total)} +{' '}
                                {money(invoice.gst_amount)} ={' '}
                                {money(invoice.invoice_total)}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Received / TDS / Balance Due
                            </div>
                            <div className="font-medium">
                                {money(invoice.amount_received)} /{' '}
                                {money(invoice.customer_tds_amount)} /{' '}
                                {money(invoice.balance_due)}
                            </div>
                        </div>
                    </div>

                    {invoice.cancellation_remark ? (
                        <div className="border-t bg-destructive/5 px-4 py-2 text-sm text-destructive">
                            Cancelled: {invoice.cancellation_remark}
                        </div>
                    ) : null}

                    <div className="overflow-x-auto border-t">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Bilty</th>
                                    <th>Description</th>
                                    <th>Freight</th>
                                    <th>Charges</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.lines.map((line) => (
                                    <tr key={line.id}>
                                        <td className="font-medium">
                                            {line.lr_number ?? '—'}
                                        </td>
                                        <td>{line.description}</td>
                                        <td>{money(line.freight_amount)}</td>
                                        <td>{money(line.charges_amount)}</td>
                                        <td className="font-medium">
                                            {money(line.line_total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t p-4">
                        <h3 className="mb-3 text-sm font-semibold">
                            Customer Receipts
                        </h3>
                        <div className="overflow-x-auto rounded-md border">
                            <table className="app-index-table min-w-full">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Mode</th>
                                        <th>Received</th>
                                        <th>TDS</th>
                                        <th>Deduction</th>
                                        <th>Reference</th>
                                        <th>Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoice.payments.length === 0 ? (
                                        <tr>
                                            <td colSpan={7}>
                                                <p className="app-index-empty">
                                                    No customer receipts
                                                    recorded.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        invoice.payments.map((p) => (
                                            <tr key={p.id}>
                                                <td>{p.payment_date}</td>
                                                <td className="uppercase">
                                                    {p.payment_mode}
                                                </td>
                                                <td>
                                                    {money(p.amount_received)}
                                                </td>
                                                <td>{money(p.tds_amount)}</td>
                                                <td>
                                                    {money(p.deduction_amount)}
                                                </td>
                                                <td>{p.reference ?? '—'}</td>
                                                <td>{p.remark ?? '—'}</td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {permissions.can_record_payment ? (
                        <form
                            onSubmit={submitPayment}
                            className="grid gap-3 border-t p-4 md:grid-cols-3"
                        >
                            <h3 className="text-sm font-semibold md:col-span-3">
                                Record Customer Receipt
                            </h3>
                            <div className="space-y-1.5">
                                <Label htmlFor="amount_received" required>
                                    Amount Received
                                </Label>
                                <Input
                                    id="amount_received"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={paymentForm.data.amount_received}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'amount_received',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    message={paymentForm.errors.amount_received}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="tds_amount">TDS Deducted</Label>
                                <Input
                                    id="tds_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={paymentForm.data.tds_amount}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'tds_amount',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="deduction_amount">
                                    Shortage / Deduction
                                </Label>
                                <Input
                                    id="deduction_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={paymentForm.data.deduction_amount}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'deduction_amount',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="payment_date" required>
                                    Receipt Date
                                </Label>
                                <Input
                                    id="payment_date"
                                    type="date"
                                    value={paymentForm.data.payment_date}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'payment_date',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="bank_cash_account_id" required>
                                    Bank / Cash Account
                                </Label>
                                <select
                                    id="bank_cash_account_id"
                                    value={
                                        paymentForm.data.bank_cash_account_id
                                    }
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'bank_cash_account_id',
                                            e.target.value,
                                        )
                                    }
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    required
                                >
                                    {bankCashAccounts.map((acc) => (
                                        <option key={acc.id} value={acc.id}>
                                            {acc.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="payment_mode" required>
                                    Mode
                                </Label>
                                <select
                                    id="payment_mode"
                                    required
                                    value={paymentForm.data.payment_mode}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'payment_mode',
                                            e.target.value,
                                        )
                                    }
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="bank">Bank</option>
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="reference">Reference</Label>
                                <Input
                                    id="reference"
                                    value={paymentForm.data.reference}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'reference',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5 md:col-span-2">
                                <Label htmlFor="remark">Remark</Label>
                                <Input
                                    id="remark"
                                    value={paymentForm.data.remark}
                                    onChange={(e) =>
                                        paymentForm.setData(
                                            'remark',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="md:col-span-3">
                                <Button
                                    type="submit"
                                    disabled={paymentForm.processing}
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    {paymentForm.processing
                                        ? 'Saving…'
                                        : 'Record Receipt'}
                                </Button>
                            </div>
                        </form>
                    ) : null}

                    {permissions.can_cancel ? (
                        <form
                            onSubmit={submitCancel}
                            className="space-y-2 border-t p-4"
                        >
                            <Label htmlFor="cancellation_remark" required>
                                Cancellation Remark
                            </Label>
                            <Input
                                id="cancellation_remark"
                                value={cancelForm.data.cancellation_remark}
                                onChange={(e) =>
                                    cancelForm.setData(
                                        'cancellation_remark',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={cancelForm.errors.cancellation_remark}
                            />
                            <Button
                                type="submit"
                                variant="destructive"
                                size="sm"
                                disabled={cancelForm.processing}
                            >
                                Cancel Invoice
                            </Button>
                        </form>
                    ) : null}
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
