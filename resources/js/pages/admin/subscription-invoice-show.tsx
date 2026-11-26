import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    document: {
        document_title: string;
        banner: string | null;
        supplier: Record<string, string | null> | null;
        customer: Record<string, string | null>;
        invoice: {
            id: string;
            invoice_number: string;
            document_type: string;
            status: string;
            financial_year: string;
            sac_code: string | null;
            service_period_start: string | null;
            service_period_end: string | null;
            issued_at: string | null;
            credit_note_reason: string | null;
        };
        line_items: {
            description?: string;
            name?: string;
            quantity?: number;
            amount?: string;
        }[];
        payment_reference: string | null;
        totals: {
            subtotal_amount: string;
            discount_amount: string;
            taxable_amount: string;
            gst_rate_percent: string;
            cgst_amount: string;
            sgst_amount: string;
            igst_amount: string;
            amount: string;
            currency: string;
        };
    };
    canManage: boolean;
    links: {
        index: string;
        download: string;
        credit_note: string | null;
    };
};

export default function AdminSubscriptionInvoiceShow({
    document,
    canManage,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: { credit_note?: string; reason?: string; amount?: string };
    }>().props;
    const inv = document.invoice;
    const totals = document.totals;

    return (
        <>
            <Head title={`${inv.invoice_number} · Admin`} />
            <PageShell
                title={`${document.document_title} · ${inv.invoice_number}`}
                description={`FY ${inv.financial_year} · Issued ${inv.issued_at || '—'} · Status: ${inv.status}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.index}>Back to Register</Link>
                        </Button>
                        <Button asChild size="sm">
                            <a
                                href={links.download}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Download / Print
                            </a>
                        </Button>
                    </div>
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.credit_note ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.credit_note}
                    </p>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-border bg-card p-4 text-xs">
                        <p className="mb-1 font-semibold text-muted-foreground uppercase">
                            Supplier Snapshot
                        </p>
                        {document.supplier ? (
                            <>
                                <p className="text-sm font-semibold">
                                    {document.supplier.legal_name}
                                </p>
                                <p>{document.supplier.registered_address}</p>
                                <p className="font-mono">
                                    GSTIN:{' '}
                                    {document.supplier.gstin || 'Unregistered'}
                                </p>
                            </>
                        ) : (
                            <p className="text-muted-foreground">—</p>
                        )}
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4 text-xs">
                        <p className="mb-1 font-semibold text-muted-foreground uppercase">
                            Customer Snapshot
                        </p>
                        <p className="text-sm font-semibold">
                            {document.customer.legal_name}
                        </p>
                        <p>
                            {[
                                document.customer.registered_address,
                                document.customer.city,
                                document.customer.state,
                                document.customer.pin_code,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </p>
                        <p className="font-mono">
                            GSTIN: {document.customer.gstin || 'Unregistered'}
                        </p>
                        <p className="text-muted-foreground">
                            Payment Ref: {document.payment_reference || '—'}
                        </p>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-border bg-card">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-border bg-muted/30">
                            <tr>
                                <th className="px-3 py-2">Description</th>
                                <th className="px-3 py-2">SAC</th>
                                <th className="px-3 py-2 text-right">Qty</th>
                                <th className="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {document.line_items.map((item, idx) => (
                                <tr
                                    key={idx}
                                    className="border-b border-border last:border-0"
                                >
                                    <td className="px-3 py-2">
                                        {item.description || item.name}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {inv.sac_code || '998314'}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {item.quantity ?? 1}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {totals.currency} {item.amount}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/15 text-xs">
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right"
                                >
                                    Taxable
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    {totals.currency} {totals.taxable_amount}
                                </td>
                            </tr>
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right"
                                >
                                    GST ({totals.gst_rate_percent}%)
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    {Number(totals.igst_amount) > 0
                                        ? `IGST ${totals.currency} ${totals.igst_amount}`
                                        : `CGST ${totals.currency} ${totals.cgst_amount} + SGST ${totals.currency} ${totals.sgst_amount}`}
                                </td>
                            </tr>
                            <tr className="border-t border-border text-sm font-bold">
                                <td
                                    colSpan={3}
                                    className="px-3 py-2 text-right"
                                >
                                    Grand Total
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {totals.currency} {totals.amount}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {canManage && links.credit_note ? (
                    <Form
                        action={links.credit_note}
                        method="post"
                        className="space-y-3 rounded-xl border border-border bg-card p-4"
                    >
                        <h3 className="text-sm font-semibold">
                            Issue Credit Note (Refund / Adjustment)
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Original issued tax invoice remains immutable. A
                            linked Credit Note will be created.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1 sm:col-span-2">
                                <Label htmlFor="cn_reason" required>
                                    Reason
                                </Label>
                                <Input
                                    id="cn_reason"
                                    name="reason"
                                    required
                                    placeholder="Refund or billing adjustment reason"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="cn_amount">
                                    Credit Amount (optional full if blank)
                                </Label>
                                <Input
                                    id="cn_amount"
                                    name="amount"
                                    type="number"
                                    step="0.01"
                                    placeholder={totals.amount}
                                />
                            </div>
                        </div>
                        <Button type="submit" variant="destructive" size="sm">
                            Issue Credit Note
                        </Button>
                    </Form>
                ) : null}
            </PageShell>
        </>
    );
}
