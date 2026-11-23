import { Form, Head } from '@inertiajs/react';
import { ArrowLeft, Banknote, Pencil, Printer } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDate } from '@/lib/dates';

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
        can_edit_charges: boolean;
        can_view_freight: boolean;
    };
    paymentModes: Array<{ id: string; name: string }>;
    sampleBanner: string;
};

function moneyLabel(
    value: string | null | undefined,
    mayView: boolean,
): string {
    if (!mayView || value === null || value === undefined) {
        return '—';
    }

    return `₹${value}`;
}

export default function TripInvoiceShow({
    invoice,
    permissions,
    paymentModes,
    sampleBanner,
}: Props) {
    const [showPayment, setShowPayment] = useState(false);
    const [tab, setTab] = useState('overview');
    const mayViewFreight = permissions.can_view_freight;

    const lineCount =
        invoice.lines.length + (invoice.charges.length > 0 ? 1 : 0);

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <PageShell
                title={`Invoice ${invoice.invoice_number}`}
                description="OwnFleet billing, TDS, and payment settlement."
                actions={
                    <>
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to invoices"
                            href="/trip-invoices"
                            variant="outline"
                        />
                        {permissions.can_print ? (
                            <AppIconAction
                                icon={Printer}
                                label="Print sample"
                                href={`/trip-invoices/${invoice.id}/print`}
                                variant="secondary"
                            />
                        ) : null}
                        {permissions.can_edit_charges ? (
                            <AppIconAction
                                icon={Pencil}
                                label="Edit charges"
                                href={`/trip-invoices/${invoice.id}/edit-charges`}
                                variant="warning"
                            />
                        ) : null}
                    </>
                }
            >
                <p className="rounded-xl border border-[#FFC107]/40 bg-[#FFC107]/15 px-4 py-3 text-sm text-[#8a6a00]">
                    {sampleBanner}
                </p>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Invoice total"
                        value={moneyLabel(
                            invoice.invoice_total,
                            mayViewFreight,
                        )}
                        tone="blue"
                    />
                    <MetricCard
                        label="Balance due"
                        value={moneyLabel(invoice.balance_due, mayViewFreight)}
                        tone="amber"
                    />
                    <MetricCard
                        label="Payment status"
                        value={
                            <WorkspaceStatusBadge
                                status={invoice.payment_status}
                            />
                        }
                        tone="green"
                    />
                    <MetricCard
                        label="TDS rate"
                        value={`${invoice.invoice_tds_rate_percent}%`}
                        hint={`Basis freight ₹${invoice.tds_basis_freight}`}
                        tone="neutral"
                    />
                </div>

                <DetailTabs
                    aria-label="Invoice detail sections"
                    activeId={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'overview', label: 'Overview' },
                        {
                            id: 'lines',
                            label: 'Lines',
                            count: lineCount,
                        },
                        {
                            id: 'payments',
                            label: 'Payments',
                            count: invoice.payments.length,
                            tone:
                                invoice.balance_due !== '0.00' && mayViewFreight
                                    ? 'warning'
                                    : undefined,
                        },
                        { id: 'actions', label: 'Actions' },
                    ]}
                >
                    <DetailTabPanel id="overview" activeId={tab}>
                        <FormSection title="Invoice details" columns={2}>
                            <ReadonlyField
                                label="Date"
                                value={formatDisplayDate(invoice.invoice_date)}
                            />
                            <ReadonlyField
                                label="Customer"
                                value={invoice.billing_customer?.name ?? '—'}
                            />
                            <ReadonlyField
                                label="Base freight"
                                value={moneyLabel(
                                    invoice.base_freight_total,
                                    mayViewFreight,
                                )}
                            />
                            <ReadonlyField
                                label="Additional charges"
                                value={moneyLabel(
                                    invoice.additional_charges_total,
                                    mayViewFreight,
                                )}
                            />
                            <ReadonlyField
                                label="GST"
                                value={moneyLabel(
                                    invoice.gst_amount,
                                    mayViewFreight,
                                )}
                            />
                            <ReadonlyField
                                label="Invoice total"
                                value={moneyLabel(
                                    invoice.invoice_total,
                                    mayViewFreight,
                                )}
                            />
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="lines" activeId={tab}>
                        <FormSection title="LR lines" columns={2}>
                            {invoice.lines.length === 0 ? (
                                <p className="text-sm text-muted-foreground md:col-span-2">
                                    No LR lines on this invoice.
                                </p>
                            ) : (
                                invoice.lines.map((line, index) => (
                                    <div key={index} className="text-sm">
                                        <p className="font-medium">
                                            {line.lr_number ?? '—'}
                                        </p>
                                        <p className="text-muted-foreground">
                                            Base freight{' '}
                                            {moneyLabel(
                                                line.base_freight,
                                                mayViewFreight,
                                            )}
                                        </p>
                                    </div>
                                ))
                            )}
                        </FormSection>

                        {invoice.charges.length > 0 ? (
                            <FormSection
                                title="Additional charge lines"
                                columns={2}
                            >
                                {invoice.charges.map((c, index) => (
                                    <div key={index} className="text-sm">
                                        <p className="font-medium">
                                            {c.description}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {c.charge_type} ·{' '}
                                            {moneyLabel(
                                                c.amount,
                                                mayViewFreight,
                                            )}
                                        </p>
                                    </div>
                                ))}
                            </FormSection>
                        ) : null}
                    </DetailTabPanel>

                    <DetailTabPanel id="payments" activeId={tab}>
                        <FormSection
                            title="Payment history & settlement"
                            columns={1}
                        >
                            {invoice.payments.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No payments yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {invoice.payments.map((p, index) => (
                                        <li
                                            key={index}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-3 py-2"
                                        >
                                            <span>
                                                {formatDisplayDate(
                                                    p.payment_date,
                                                )}
                                            </span>
                                            <span>
                                                Received{' '}
                                                {moneyLabel(
                                                    p.amount_received,
                                                    mayViewFreight,
                                                )}{' '}
                                                · TDS{' '}
                                                {moneyLabel(
                                                    p.tds_amount,
                                                    mayViewFreight,
                                                )}
                                            </span>
                                            <span className="font-medium">
                                                Settlement{' '}
                                                {moneyLabel(
                                                    p.settlement_amount,
                                                    mayViewFreight,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {permissions.can_record_payment &&
                            mayViewFreight &&
                            invoice.balance_due !== '0.00' &&
                            invoice.balance_due !== null ? (
                                <div className="mt-4 space-y-3 border-t border-border pt-4">
                                    <Button
                                        type="button"
                                        variant="primary"
                                        className="gap-2"
                                        onClick={() =>
                                            setShowPayment((v) => !v)
                                        }
                                    >
                                        <Banknote className="size-4" />
                                        {showPayment
                                            ? 'Hide payment form'
                                            : 'Record payment'}
                                    </Button>
                                    {showPayment ? (
                                        <Form
                                            action={`/trip-invoices/${invoice.id}/payments`}
                                            method="post"
                                            className="grid gap-3 md:grid-cols-2"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <div className="space-y-2">
                                                        <Label htmlFor="payment_date">
                                                            Payment date
                                                        </Label>
                                                        <Input
                                                            id="payment_date"
                                                            name="payment_date"
                                                            type="date"
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label htmlFor="payment_mode_id">
                                                            Mode
                                                        </Label>
                                                        <select
                                                            id="payment_mode_id"
                                                            name="payment_mode_id"
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        >
                                                            <option value="">
                                                                —
                                                            </option>
                                                            {paymentModes.map(
                                                                (mode) => (
                                                                    <option
                                                                        key={
                                                                            mode.id
                                                                        }
                                                                        value={
                                                                            mode.id
                                                                        }
                                                                    >
                                                                        {
                                                                            mode.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label htmlFor="amount_received">
                                                            Amount received
                                                        </Label>
                                                        <Input
                                                            id="amount_received"
                                                            name="amount_received"
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label htmlFor="tds_amount">
                                                            TDS
                                                        </Label>
                                                        <Input
                                                            id="tds_amount"
                                                            name="tds_amount"
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                        />
                                                    </div>
                                                    <label className="flex items-center gap-2 text-sm md:col-span-2">
                                                        <input
                                                            type="checkbox"
                                                            name="fill_net"
                                                            value="1"
                                                        />
                                                        Fill net (auto amount
                                                        received)
                                                    </label>
                                                    <Button
                                                        type="submit"
                                                        variant="primary"
                                                        disabled={processing}
                                                    >
                                                        Save payment
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : null}
                                </div>
                            ) : null}
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="actions" activeId={tab}>
                        {permissions.can_cancel ? (
                            <FormSection title="Cancel invoice" columns={1}>
                                <Form
                                    action={`/trip-invoices/${invoice.id}/cancel`}
                                    method="post"
                                    className="space-y-3"
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="cancellation_remark">
                                            Cancel reason
                                        </Label>
                                        <textarea
                                            id="cancellation_remark"
                                            name="cancellation_remark"
                                            required
                                            className="min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <Button type="submit" variant="destructive">
                                        Cancel invoice
                                    </Button>
                                </Form>
                            </FormSection>
                        ) : null}

                        {permissions.can_delete ? (
                            <FormSection
                                title="Delete invoice"
                                columns={1}
                                description="Soft delete is available for the latest revision only."
                            >
                                <Form
                                    action={`/trip-invoices/${invoice.id}`}
                                    method="delete"
                                >
                                    <Button type="submit" variant="outline">
                                        Soft delete (latest only)
                                    </Button>
                                </Form>
                            </FormSection>
                        ) : null}

                        {!permissions.can_cancel && !permissions.can_delete ? (
                            <p className="text-sm text-muted-foreground">
                                No destructive actions are available for this
                                invoice.
                            </p>
                        ) : null}
                    </DetailTabPanel>
                </DetailTabs>
            </PageShell>
        </>
    );
}

function ReadonlyField({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}
