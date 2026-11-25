import { Head, Link, router } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
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
            <AppIndexPage>
                <AppIndexListCard
                    title="Broker Customer Invoices"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={urls.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create invoice
                            </Link>
                        ) : null
                    }
                >
                    <AppIndexFilters
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
                        <div className="space-y-1">
                            <Label htmlFor="payment_status">
                                Payment status
                            </Label>
                            <select
                                id="payment_status"
                                name="payment_status"
                                defaultValue={filters.payment_status}
                                className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {paymentStatusOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th className="text-end">Total</th>
                                    <th className="text-end">Balance</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <p className="app-index-empty">
                                                No customer invoices yet.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    invoices.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.invoice_number}
                                            </td>
                                            <td>
                                                {row.billing_customer_name ??
                                                    '—'}
                                            </td>
                                            <td>{row.invoice_date ?? '—'}</td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={row.payment_status}
                                                />
                                            </td>
                                            <td className="text-end tabular-nums">
                                                {row.invoice_total ?? '—'}
                                            </td>
                                            <td className="text-end tabular-nums">
                                                {row.balance_due ?? '—'}
                                            </td>
                                            <td>
                                                <div className="flex justify-center">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="Open invoice"
                                                        href={row.urls.show}
                                                        variant="info"
                                                    />
                                                </div>
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
