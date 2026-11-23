import { Head, Link, router } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type InvoiceRow = {
    id: string;
    invoice_number: string;
    status: string;
    payment_status: string;
    invoice_date: string | null;
    billing_customer_name: string | null;
    invoice_total: string | null;
    balance_due: string | null;
    urls: { show: string };
};

type Props = {
    filters: { payment_status: string };
    permissions: { can_create: boolean; can_view_freight: boolean };
    paymentStatusOptions: Array<{ value: string; label: string }>;
    invoices: {
        data: InvoiceRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string; create: string };
};

export default function BrokerInvoicesIndex({
    filters,
    permissions,
    paymentStatusOptions,
    invoices,
    urls,
}: Props) {
    return (
        <>
            <Head title="Broker Customer Invoices" />
            <PageShell
                title="Broker Customer Invoices"
                actions={
                    permissions.can_create ? (
                        <Link href={urls.create}>
                            <Button className="bg-[#2FB36F] text-white hover:bg-[#279960]">
                                Create invoice
                            </Button>
                        </Link>
                    ) : null
                }
            >
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const form = event.currentTarget;
                        const payment_status = (
                            form.elements.namedItem(
                                'payment_status',
                            ) as HTMLSelectElement
                        ).value;
                        router.get(
                            urls.index,
                            {
                                payment_status: payment_status || undefined,
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <Label className="flex flex-col gap-1 text-sm">
                        Payment status
                        <select
                            name="payment_status"
                            defaultValue={filters.payment_status}
                            className="rounded-md border border-border px-2 py-1.5"
                        >
                            {paymentStatusOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </Label>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="mt-6 overflow-x-auto rounded-lg border border-border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th className="px-4 py-3">Invoice</th>
                                <th className="px-4 py-3">Customer</th>
                                <th className="px-4 py-3">Date</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Total</th>
                                <th className="px-4 py-3 text-right">
                                    Balance
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No customer invoices yet.
                                    </td>
                                </tr>
                            ) : (
                                invoices.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={row.urls.show}
                                                className="font-medium text-[#2D8CFF] hover:underline"
                                            >
                                                {row.invoice_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.billing_customer_name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.invoice_date ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.payment_status}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {row.invoice_total ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {row.balance_due ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </>
    );
}
