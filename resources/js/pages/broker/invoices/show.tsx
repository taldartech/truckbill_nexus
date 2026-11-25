import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import {
    FbCompactTable,
    FbDetailCard,
    FbDetailLayout,
    FbDetailPage,
    FbFinancialSummary,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
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
        cgst_amount?: string | null;
        sgst_amount?: string | null;
        igst_amount?: string | null;
        cgst_rate?: string | number | null;
        sgst_rate?: string | number | null;
        igst_rate?: string | number | null;
        supply_type?: string | null;
        tax_liability?: string | null;
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
    const balanceNumber = Number(invoice.balance_due ?? NaN);
    const balanceDueFlag = Number.isFinite(balanceNumber) && balanceNumber > 0;
    const cleared = Number.isFinite(balanceNumber) && balanceNumber <= 0;

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <FbDetailPage title={`Invoice ${invoice.invoice_number}`}>
                {invoice.cancellation_remark ? (
                    <div
                        className="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
                        role="alert"
                    >
                        <strong>Cancelled invoice</strong>
                        <div className="text-xs">
                            {invoice.cancellation_remark}
                        </div>
                    </div>
                ) : null}

                <FbDetailLayout
                    main={
                        <>
                            <FbDetailCard
                                title={`Customer Invoice (${invoice.invoice_number})`}
                                badges={
                                    <>
                                        <WorkspaceStatusBadge
                                            status={invoice.status}
                                        />
                                        <WorkspaceStatusBadge
                                            status={invoice.payment_status}
                                        />
                                    </>
                                }
                                actions={
                                    <>
                                        <AppIconAction
                                            icon={ArrowLeft}
                                            label="All invoices"
                                            href={urls.index}
                                            variant="outline"
                                            size="sm"
                                        />
                                        {permissions.can_print && urls.print ? (
                                            <Button
                                                asChild
                                                variant="info"
                                                size="sm"
                                            >
                                                <Link href={urls.print}>
                                                    Print / Preview
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </>
                                }
                            >
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Customer"
                                        value={invoice.billing_customer_name}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="Invoice date"
                                        value={invoice.invoice_date}
                                    />
                                    <FbInfoItem
                                        label="Taxable total"
                                        value={invoice.taxable_total}
                                    />
                                    {invoice.supply_type === 'intra_state' ||
                                    (invoice.cgst_amount &&
                                        invoice.cgst_amount !== '0.00' &&
                                        invoice.cgst_amount !== '—') ? (
                                        <>
                                            <FbInfoItem
                                                label={`CGST${invoice.cgst_rate ? ` (${invoice.cgst_rate}%)` : ''}`}
                                                value={invoice.cgst_amount}
                                            />
                                            <FbInfoItem
                                                label={`SGST${invoice.sgst_rate ? ` (${invoice.sgst_rate}%)` : ''}`}
                                                value={invoice.sgst_amount}
                                            />
                                        </>
                                    ) : null}
                                    {invoice.supply_type === 'inter_state' ||
                                    (invoice.igst_amount &&
                                        invoice.igst_amount !== '0.00' &&
                                        invoice.igst_amount !== '—') ? (
                                        <FbInfoItem
                                            label={`IGST${invoice.igst_rate ? ` (${invoice.igst_rate}%)` : ''}`}
                                            value={invoice.igst_amount}
                                        />
                                    ) : null}
                                    <FbInfoItem
                                        label="Total GST"
                                        value={invoice.gst_amount}
                                    />
                                    <FbInfoItem
                                        label="Invoice total"
                                        value={invoice.invoice_total}
                                        accent
                                    />
                                </FbInfoGrid>
                            </FbDetailCard>

                            <DetailTabs
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
                                    <FbCompactTable
                                        title="Invoice lines"
                                        count={invoice.lines.length}
                                        headers={['LR', 'Memo', 'Line total']}
                                        empty="No LR lines on this invoice."
                                    >
                                        {invoice.lines.map((line) => (
                                            <tr key={line.id}>
                                                <td className="px-3 py-2">
                                                    {line.lr_number}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {line.memo_number ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {line.line_total ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </FbCompactTable>
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

                            {permissions.can_cancel ? (
                                <FbDetailCard title="Cancel invoice">
                                    <Form
                                        action={urls.cancel}
                                        method="post"
                                        className="space-y-3 px-4 py-3"
                                    >
                                        {({ processing }) => (
                                            <>
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
                                </FbDetailCard>
                            ) : null}
                        </>
                    }
                    side={
                        <FbFinancialSummary
                            balanceDue={balanceDueFlag}
                            cleared={cleared}
                            trAmount={invoice.invoice_total ?? '—'}
                            deductions={invoice.customer_tds_amount ?? '—'}
                            balance={invoice.balance_due ?? '—'}
                            rows={[
                                {
                                    label: 'Received',
                                    value: invoice.amount_received ?? '—',
                                },
                                {
                                    label: 'Customer TDS',
                                    value: invoice.customer_tds_amount ?? '—',
                                },
                                {
                                    label: 'Taxable',
                                    value: invoice.taxable_total ?? '—',
                                },
                                {
                                    label: 'GST',
                                    value: invoice.gst_amount ?? '—',
                                },
                            ]}
                        />
                    }
                />
            </FbDetailPage>
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
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);

    const openConfirmation = (form: HTMLFormElement) => {
        const data = new FormData(form);
        const read = (key: string, fallback: string): string => {
            const value = data.get(key);
            return typeof value === 'string' ? value : fallback;
        };
        setReviewFields([
            {
                label: 'Invoice',
                value: invoice.invoice_number,
            },
            {
                label: 'Invoice total',
                value: invoice.invoice_total ?? '—',
            },
            {
                label: 'Current outstanding',
                value: invoice.balance_due ?? '—',
            },
            { label: 'Proposed receipt', value: read('amount_received', '—') },
            { label: 'Customer TDS', value: read('tds_amount', '0') },
            { label: 'Deduction', value: read('deduction_amount', '0') },
        ]);
        setConfirmOpen(true);
    };

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
                    id="broker-invoice-payment-form"
                    action={urls.payment}
                    method="post"
                    className="grid max-w-xl gap-3 rounded-lg border border-border p-4"
                    onSubmit={(event) => {
                        if (!submitAllowed.current) {
                            event.preventDefault();
                            openConfirmation(event.currentTarget);
                            return;
                        }
                        submitAllowed.current = false;
                    }}
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

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="create"
                title="Record this customer receipt?"
                description="Review invoice outstanding and proposed payment before submitting."
                fields={reviewFields}
                onConfirm={() => {
                    submitAllowed.current = true;
                    setConfirmOpen(false);
                    window.setTimeout(() => {
                        const formElement = document.getElementById(
                            'broker-invoice-payment-form',
                        ) as HTMLFormElement | null;
                        formElement?.requestSubmit();
                    }, 0);
                }}
            />
        </div>
    );
}
