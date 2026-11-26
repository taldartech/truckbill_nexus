import { Head, Link } from '@inertiajs/react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type InvoiceRow = {
    id: string;
    invoice_number: string;
    invoice_date: string;
    billing_customer_name: string | null;
    status: string;
    payment_status: string;
    invoice_total: string | null;
    balance_due: string | null;
    urls: { show: string };
};

type Props = {
    invoices: {
        data: InvoiceRow[];
    };
    permissions: {
        can_create: boolean;
        can_view_freight: boolean;
    };
    urls: {
        create: string;
        unified: string;
    };
};

export default function PartloadInvoicesIndex({
    invoices,
    permissions,
    urls,
}: Props) {
    return (
        <>
            <Head title="Partload Customer Invoices" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Partload Customer Invoices"
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.unified}>All Invoices</Link>
                            </Button>
                            {permissions.can_create ? (
                                <Button
                                    asChild
                                    size="sm"
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    <Link href={urls.create}>
                                        New Partload Invoice
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                >
                    <p className="border-b bg-amber-50 px-4 py-2 text-xs text-amber-900">
                        SAMPLE / NON-PRODUCTION — Not a legally operative tax
                        invoice.
                    </p>
                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Invoice No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Total</th>
                                    <th>Balance</th>
                                    <th className="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={8}>
                                            <p className="app-index-empty">
                                                No Partload customer invoices
                                                issued yet.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    invoices.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.invoice_number}
                                            </td>
                                            <td>{row.invoice_date}</td>
                                            <td>
                                                {row.billing_customer_name ??
                                                    '—'}
                                            </td>
                                            <td>
                                                <Badge variant="outline">
                                                    {row.status}
                                                </Badge>
                                            </td>
                                            <td className="capitalize">
                                                {row.payment_status}
                                            </td>
                                            <td>
                                                {permissions.can_view_freight &&
                                                row.invoice_total !== null
                                                    ? `₹${row.invoice_total}`
                                                    : '—'}
                                            </td>
                                            <td>
                                                {permissions.can_view_freight &&
                                                row.balance_due !== null
                                                    ? `₹${row.balance_due}`
                                                    : '—'}
                                            </td>
                                            <td className="text-right">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <Link href={row.urls.show}>
                                                        View
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
