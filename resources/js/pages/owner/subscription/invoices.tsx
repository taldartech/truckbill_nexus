import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    issuer_note: string;
    invoices: {
        id: string;
        status: string;
        proposed_number: string | null;
        approved_number: string | null;
        financial_year: string;
        amount: string;
        currency: string;
        email_status: string | null;
        approved_at: string | null;
        created_at: string | null;
    }[];
    links: { overview: string };
};

export default function SubscriptionInvoices({
    company,
    ownerPanelScope,
    issuer_note,
    invoices,
    links,
}: Props) {
    return (
        <>
            <Head title={`Invoices · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Owner Panel · Subscription invoices
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {company.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Scope: {ownerPanelScope}
                    </p>
                </div>
                <p className="text-sm text-muted-foreground">{issuer_note}</p>
                <Button asChild variant="outline" className="w-fit">
                    <Link href={links.overview}>Back to subscription</Link>
                </Button>
                {invoices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No subscription invoices yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Number
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        FY
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Email
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.map((invoice) => (
                                    <tr
                                        key={invoice.id}
                                        className="border-b border-border/60"
                                    >
                                        <td className="px-3 py-2">
                                            {invoice.status}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {invoice.approved_number ||
                                                invoice.proposed_number ||
                                                '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {invoice.financial_year}
                                        </td>
                                        <td className="px-3 py-2">
                                            {invoice.currency} {invoice.amount}
                                        </td>
                                        <td className="px-3 py-2">
                                            {invoice.email_status || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
