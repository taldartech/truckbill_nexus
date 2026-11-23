import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    document: {
        sample_banner: string;
        company: { id: string; name: string };
        invoice: {
            id: string;
            status: string;
            proposed_number: string | null;
            approved_number: string | null;
            financial_year: string;
            amount: string;
            currency: string;
            email_status: string | null;
        };
        line_items: { description?: string; name?: string; amount?: string }[];
        payment_reference: string | null;
        totals: { amount: string; currency: string };
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
    const number =
        document.invoice.approved_number ||
        document.invoice.proposed_number ||
        'DRAFT';

    return (
        <>
            <Head title={`Invoice ${number} · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Invoice preview"
                width="form"
            >
                <h2 className="text-lg font-semibold tracking-tight">
                    {number}
                </h2>
                <p
                    role="status"
                    className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
                >
                    {document.sample_banner}
                </p>
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
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={links.invoices}>Back to invoices</Link>
                    </Button>
                    <Button asChild>
                        <a
                            href={links.download}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Download / print HTML
                        </a>
                    </Button>
                    {links.email ? (
                        <Form action={links.email} method="post">
                            <Button type="submit" variant="outline">
                                Email sample invoice
                            </Button>
                        </Form>
                    ) : null}
                </div>
                <dl className="grid gap-3 rounded-xl border border-border p-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>{document.invoice.status}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            Financial year
                        </dt>
                        <dd>{document.invoice.financial_year}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            Payment reference
                        </dt>
                        <dd className="font-mono text-xs">
                            {document.payment_reference || '—'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Email</dt>
                        <dd>{document.invoice.email_status || '—'}</dd>
                    </div>
                </dl>
                <section className="overflow-x-auto rounded-xl border border-border">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-border bg-muted/30">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Description
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
                                            'Line item'}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {item.amount || '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td className="px-3 py-3 font-semibold">
                                    Total
                                </td>
                                <td className="px-3 py-3 text-right font-semibold">
                                    {document.totals.currency}{' '}
                                    {document.totals.amount}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>
            </CompanyOwnerPageShell>
        </>
    );
}
