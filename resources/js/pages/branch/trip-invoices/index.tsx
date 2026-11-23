import { Head, Link, router } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';

type InvoiceRow = {
    id: string;
    invoice_number: string;
    invoice_date: string | null;
    billing_customer_name: string | null;
    invoice_type: string;
    payment_status: string;
    invoice_total: string;
    balance_due: string;
};

type Props = {
    filters: { payment_status: string };
    permissions: { can_create: boolean };
    invoices: {
        data: InvoiceRow[];
        meta: { total: number };
    };
    sampleBanner: string;
};

export default function TripInvoicesIndex({
    filters,
    permissions,
    invoices,
    sampleBanner,
}: Props) {
    return (
        <>
            <Head title="OwnFleet Invoices" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <MasterPageHeader title="OwnFleet Invoices" />
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>
                <div className="flex flex-wrap gap-2">
                    {permissions.can_create && (
                        <>
                            <Button asChild>
                                <Link href="/trip-invoices/create">
                                    Individual invoice
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href="/trip-invoices/consolidate">
                                    Consolidated invoice
                                </Link>
                            </Button>
                        </>
                    )}
                </div>
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const status = (
                            event.currentTarget.elements.namedItem(
                                'payment_status',
                            ) as HTMLSelectElement
                        ).value;
                        router.get(
                            '/trip-invoices',
                            { payment_status: status || undefined },
                            { preserveState: true },
                        );
                    }}
                >
                    <label className="flex flex-col gap-1 text-sm">
                        Payment status
                        <select
                            name="payment_status"
                            defaultValue={filters.payment_status}
                            className="rounded-md border px-2 py-1"
                        >
                            <option value="">All</option>
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="paid">Paid</option>
                        </select>
                    </label>
                    <Button type="submit" variant="secondary" size="sm">
                        Filter
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2">Invoice No</th>
                                <th className="px-3 py-2">Date</th>
                                <th className="px-3 py-2">Customer</th>
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">Total</th>
                                <th className="px-3 py-2">Balance</th>
                                <th className="px-3 py-2">Payment</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-t hover:bg-muted/30"
                                >
                                    <td className="px-3 py-2 font-medium">
                                        {row.invoice_number}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.invoice_date ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.billing_customer_name ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 capitalize">
                                        {row.invoice_type.replace('_', ' ')}
                                    </td>
                                    <td className="px-3 py-2">
                                        ₹{row.invoice_total}
                                    </td>
                                    <td className="px-3 py-2">
                                        ₹{row.balance_due}
                                    </td>
                                    <td className="px-3 py-2 capitalize">
                                        {row.payment_status}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Link
                                            href={`/trip-invoices/${row.id}`}
                                            className="text-primary underline"
                                        >
                                            Open
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {invoices.data.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">
                            No invoices yet.
                        </p>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    {invoices.meta.total} invoice(s)
                </p>
            </div>
        </>
    );
}
