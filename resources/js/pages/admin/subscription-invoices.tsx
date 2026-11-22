import { Form, Head, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    issuer_note: string;
    invoices: {
        id: string;
        company_name: string | null;
        status: string;
        proposed_number: string | null;
        financial_year: string | null;
        amount: string;
        currency: string;
        approve_url: string;
        created_at: string | null;
    }[];
};

export default function AdminSubscriptionInvoices({
    issuer_note,
    invoices,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: { approved_number?: string };
    }>().props;

    return (
        <>
            <Head title="Subscription invoice approval" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Super Admin
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Subscription invoice numbers
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {issuer_note}
                    </p>
                </div>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.approved_number ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.approved_number}
                    </p>
                ) : null}
                {invoices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No invoices awaiting number approval.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/30">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Company
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Proposed
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        FY
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Approve
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.map((invoice) => (
                                    <tr
                                        key={invoice.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            {invoice.company_name || '—'}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {invoice.proposed_number || '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {invoice.financial_year}
                                        </td>
                                        <td className="px-3 py-2">
                                            {invoice.currency} {invoice.amount}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Form
                                                action={invoice.approve_url}
                                                method="post"
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                <input
                                                    name="approved_number"
                                                    defaultValue={
                                                        invoice.proposed_number ||
                                                        ''
                                                    }
                                                    aria-label={`Approved number for ${invoice.company_name || invoice.id}`}
                                                    className="rounded-md border border-border bg-background px-2 py-1 font-mono text-xs"
                                                />
                                                <Button type="submit" size="sm">
                                                    Approve
                                                </Button>
                                            </Form>
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
