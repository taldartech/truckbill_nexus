import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Props = {
    invoice: {
        id: string;
        invoice_number: string;
        status: string;
        payment_status: string;
        invoice_date: string | null;
        billing_customer_name: string | null;
        taxable_total: string | null;
        gst_amount: string | null;
        invoice_total: string | null;
        amount_received: string | null;
        customer_tds_amount: string | null;
        balance_due: string | null;
        cancellation_remark: string | null;
        lines: Array<{
            id: string;
            lr_number: string;
            memo_number: string | null;
            pod_number: string | null;
            freight_amount: string | null;
            charges_amount: string | null;
            shortage_damage_amount: string | null;
            line_total: string | null;
        }>;
        payments: Array<{
            id: string;
            payment_date: string | null;
            payment_mode: string | null;
            amount_received: string | null;
            tds_amount: string | null;
            deduction_amount: string | null;
            reference_number: string | null;
            remark: string | null;
        }>;
    };
    permissions: {
        can_cancel: boolean;
        can_record_payment: boolean;
        can_print?: boolean;
        can_view_freight: boolean;
    };
    bankCashAccounts: Array<{ id: string; label: string }>;
    urls: {
        index: string;
        cancel: string;
        payment: string;
        print?: string;
    };
};

export default function BrokerInvoiceShow({
    invoice,
    permissions,
    bankCashAccounts,
    urls,
}: Props) {
    const cancelled = invoice.status === 'cancelled';
    const [tab, setTab] = useState('lines');

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <PageShell
                title={`Invoice ${invoice.invoice_number}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {permissions.can_print && urls.print ? (
                            <Button asChild variant="info" size="sm">
                                <Link href={urls.print}>Print / Preview</Link>
                            </Button>
                        ) : null}
                        <Link
                            href={urls.index}
                            className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            All invoices
                        </Link>
                    </div>
                }
            >
                <div className="flex flex-wrap items-center gap-2">
                    <WorkspaceStatusBadge status={invoice.status} />
                    <WorkspaceStatusBadge status={invoice.payment_status} />
                </div>

                <p className="mt-2 text-sm text-muted-foreground">
                    {invoice.billing_customer_name ?? 'Customer'} ·{' '}
                    {invoice.invoice_date ?? '—'}
                </p>

                {invoice.cancellation_remark && (
                    <p className="mt-2 text-sm text-destructive">
                        Cancelled: {invoice.cancellation_remark}
                    </p>
                )}

                <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Invoice total"
                        value={invoice.invoice_total ?? '—'}
                    />
                    <MetricCard
                        label="Received"
                        value={invoice.amount_received ?? '—'}
                    />
                    <MetricCard
                        label="Customer TDS"
                        value={invoice.customer_tds_amount ?? '—'}
                    />
                    <MetricCard
                        label="Balance due"
                        value={invoice.balance_due ?? '—'}
                    />
                </div>

                <DetailTabs
                    className="mt-8"
                    tabs={[
                        {
                            id: 'lines',
                            label: 'LR lines',
                            count: invoice.lines.length,
                        },
                        {
                            id: 'payments',
                            label: 'Payments',
                            count: invoice.payments.length,
                        },
                    ]}
                    activeId={tab}
                    onChange={setTab}
                >
                    <DetailTabPanel id="lines" activeId={tab}>
                        <div className="overflow-x-auto rounded-lg border border-border">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-3 py-2">LR</th>
                                        <th className="px-3 py-2">Memo</th>
                                        <th className="px-3 py-2 text-right">
                                            Line total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoice.lines.map((line) => (
                                        <tr
                                            key={line.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-3 py-2">
                                                {line.lr_number}
                                            </td>
                                            <td className="px-3 py-2">
                                                {line.memo_number ?? '—'}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {line.line_total ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </DetailTabPanel>
                    <DetailTabPanel id="payments" activeId={tab}>
                        <PaymentsPanel
                            invoice={invoice}
                            permissions={permissions}
                            bankCashAccounts={bankCashAccounts}
                            urls={urls}
                            cancelled={cancelled}
                        />
                    </DetailTabPanel>
                </DetailTabs>

                {permissions.can_cancel && (
                    <Form
                        action={urls.cancel}
                        method="post"
                        className="mt-8 max-w-lg space-y-3 rounded-lg border border-destructive/30 p-4"
                    >
                        {({ processing }) => (
                            <>
                                <h2 className="text-sm font-semibold text-destructive">
                                    Cancel invoice
                                </h2>
                                <Label className="flex flex-col gap-1 text-sm">
                                    Reason
                                    <textarea
                                        name="cancellation_remark"
                                        required
                                        rows={3}
                                        className="rounded-md border border-border px-2 py-1.5"
                                    />
                                </Label>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Cancel invoice
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </PageShell>
        </>
    );
}

function PaymentsPanel({
    invoice,
    permissions,
    bankCashAccounts,
    urls,
    cancelled,
}: {
    invoice: Props['invoice'];
    permissions: Props['permissions'];
    bankCashAccounts: Props['bankCashAccounts'];
    urls: Props['urls'];
    cancelled: boolean;
}) {
    return (
        <div className="space-y-6">
            <div className="overflow-x-auto rounded-lg border border-border">
                <table className="min-w-full text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                        <tr>
                            <th className="px-3 py-2">Date</th>
                            <th className="px-3 py-2">Received</th>
                            <th className="px-3 py-2">TDS</th>
                            <th className="px-3 py-2">Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.payments.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-3 py-6 text-center text-muted-foreground"
                                >
                                    No payments recorded.
                                </td>
                            </tr>
                        ) : (
                            invoice.payments.map((p) => (
                                <tr
                                    key={p.id}
                                    className="border-t border-border"
                                >
                                    <td className="px-3 py-2">
                                        {p.payment_date ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {p.amount_received ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {p.tds_amount ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {p.reference_number ?? '—'}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {permissions.can_record_payment && !cancelled && (
                <Form
                    action={urls.payment}
                    method="post"
                    className="grid max-w-xl gap-3 rounded-lg border border-border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex items-center justify-between">
                                <h3 className="text-sm font-semibold">
                                    Record payment
                                </h3>
                                <span className="text-xs font-medium text-muted-foreground">
                                    Remaining Balance: ₹
                                    {invoice.balance_due ?? '0.00'}
                                </span>
                            </div>
                            <Label className="flex flex-col gap-1 text-sm">
                                Amount received
                                <input
                                    name="amount_received"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max={invoice.balance_due ?? undefined}
                                    defaultValue={invoice.balance_due ?? ''}
                                    required
                                    className="rounded-md border border-border px-2 py-1.5"
                                />
                                <InputError message={errors.amount_received} />
                            </Label>
                            <Label className="flex flex-col gap-1 text-sm">
                                Customer TDS
                                <input
                                    name="tds_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    defaultValue="0"
                                    className="rounded-md border border-border px-2 py-1.5"
                                />
                                <InputError message={errors.tds_amount} />
                            </Label>
                            <Label className="flex flex-col gap-1 text-sm">
                                Deduction
                                <input
                                    name="deduction_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    defaultValue="0"
                                    className="rounded-md border border-border px-2 py-1.5"
                                />
                                <InputError message={errors.deduction_amount} />
                            </Label>
                            <Label className="flex flex-col gap-1 text-sm">
                                Payment mode
                                <input
                                    name="payment_mode"
                                    className="rounded-md border border-border px-2 py-1.5"
                                />
                            </Label>
                            <Label className="flex flex-col gap-1 text-sm">
                                Bank / cash account
                                <select
                                    name="bank_cash_account_id"
                                    className="rounded-md border border-border px-2 py-1.5"
                                >
                                    <option value="">Optional</option>
                                    {bankCashAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.label}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label className="flex flex-col gap-1 text-sm">
                                Reference
                                <input
                                    name="reference"
                                    className="rounded-md border border-border px-2 py-1.5"
                                />
                            </Label>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="bg-[#2D8CFF] text-white hover:bg-[#2573d9]"
                            >
                                Save payment
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
