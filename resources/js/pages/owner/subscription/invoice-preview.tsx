import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    document: {
        configured: boolean;
        document_title: string;
        banner: string | null;
        supplier: Record<string, string | null> | null;
        customer: Record<string, string | null>;
        invoice: {
            id: string;
            invoice_number: string;
            status: string;
            financial_year: string;
            sac_code: string | null;
            service_period_start: string | null;
            service_period_end: string | null;
            place_of_supply_state: string | null;
            is_inter_state: boolean;
            issued_at: string | null;
            email_status: string | null;
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
            total_tax_amount: string;
            amount: string;
            currency: string;
        };
    };
    links: {
        invoices: string;
        download: string;
        email: string | null;
    };
};

export default function InvoicePreview({
    company,
    ownerPanelScope,
    document,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: { email?: string };
    }>().props;
    const inv = document.invoice;
    const totals = document.totals;

    return (
        <>
            <Head title={`${inv.invoice_number} · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section={document.document_title}
                width="form"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight">
                            {inv.invoice_number}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Issued {inv.issued_at || '—'} · FY{' '}
                            {inv.financial_year}
                            {inv.service_period_start
                                ? ` · Period ${inv.service_period_start} to ${inv.service_period_end}`
                                : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.invoices}>Back to invoices</Link>
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
                        {links.email ? (
                            <Form action={links.email} method="post">
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    Email Invoice
                                </Button>
                            </Form>
                        ) : null}
                    </div>
                </div>

                {document.banner ? (
                    <p
                        role="status"
                        className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
                    >
                        {document.banner}
                    </p>
                ) : null}
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.email ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.email}
                    </p>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-border p-4 text-xs">
                        <p className="mb-1 text-[11px] font-semibold text-muted-foreground uppercase">
                            Supplier
                        </p>
                        {document.supplier ? (
                            <>
                                <p className="text-sm font-semibold">
                                    {document.supplier.legal_name}
                                </p>
                                <p>{document.supplier.registered_address}</p>
                                <p>
                                    {[
                                        document.supplier.city,
                                        document.supplier.state_name,
                                        document.supplier.pin_code,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>
                                {document.supplier.gstin ? (
                                    <p className="mt-1 font-mono">
                                        GSTIN: {document.supplier.gstin}
                                    </p>
                                ) : null}
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                Not configured at issuance.
                            </p>
                        )}
                    </div>

                    <div className="rounded-xl border border-border p-4 text-xs">
                        <p className="mb-1 text-[11px] font-semibold text-muted-foreground uppercase">
                            Billed To (Customer Company)
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
                        <p className="mt-1 font-mono">
                            GSTIN: {document.customer.gstin || 'Unregistered'}
                        </p>
                        <p className="text-muted-foreground">
                            Payment Ref: {document.payment_reference || '—'}
                        </p>
                    </div>
                </div>

                <section className="overflow-x-auto rounded-xl border border-border">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-border bg-muted/30">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Description
                                </th>
                                <th className="px-3 py-2 font-medium">SAC</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Qty
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {document.line_items.map((item, index) => (
                                <tr
                                    key={`${item.description || item.name}-${index}`}
                                    className="border-b border-border last:border-0"
                                >
                                    <td className="px-3 py-2">
                                        {item.description ||
                                            item.name ||
                                            'Subscription'}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {inv.sac_code || '998314'}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {item.quantity ?? 1}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {totals.currency}{' '}
                                        {item.amount || '0.00'}
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
                                    Subtotal
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    {totals.currency} {totals.subtotal_amount}
                                </td>
                            </tr>
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right"
                                >
                                    Discounts
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    - {totals.currency} {totals.discount_amount}
                                </td>
                            </tr>
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right font-medium"
                                >
                                    Taxable Value
                                </td>
                                <td className="px-3 py-1.5 text-right font-medium">
                                    {totals.currency} {totals.taxable_amount}
                                </td>
                            </tr>
                            {Number(totals.igst_amount) > 0 ? (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="px-3 py-1.5 text-right"
                                    >
                                        IGST ({totals.gst_rate_percent}%)
                                    </td>
                                    <td className="px-3 py-1.5 text-right">
                                        {totals.currency} {totals.igst_amount}
                                    </td>
                                </tr>
                            ) : Number(totals.cgst_amount) > 0 ? (
                                <>
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-3 py-1.5 text-right"
                                        >
                                            CGST (
                                            {Number(totals.gst_rate_percent) /
                                                2}
                                            %)
                                        </td>
                                        <td className="px-3 py-1.5 text-right">
                                            {totals.currency}{' '}
                                            {totals.cgst_amount}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-3 py-1.5 text-right"
                                        >
                                            SGST (
                                            {Number(totals.gst_rate_percent) /
                                                2}
                                            %)
                                        </td>
                                        <td className="px-3 py-1.5 text-right">
                                            {totals.currency}{' '}
                                            {totals.sgst_amount}
                                        </td>
                                    </tr>
                                </>
                            ) : null}
                            <tr className="border-t border-border text-sm font-bold">
                                <td
                                    colSpan={3}
                                    className="px-3 py-2.5 text-right"
                                >
                                    Grand Total
                                </td>
                                <td className="px-3 py-2.5 text-right">
                                    {totals.currency} {totals.amount}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>
            </CompanyOwnerPageShell>
        </>
    );
}
