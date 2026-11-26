import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EligibleLr = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    billing_customer_party_id: string | null;
    billing_customer_name: string | null;
    freight_payment_term: string | null;
    freight_total: string | null;
};

type Props = {
    eligibleReceipts: EligibleLr[];
    preselectedIds: string[];
    permissions: { can_view_freight: boolean };
    urls: { store: string; index: string; unified: string };
};

export default function PartloadInvoicesCreate({
    eligibleReceipts,
    preselectedIds,
    permissions,
    urls,
}: Props) {
    const form = useForm<{
        lorry_receipt_ids: string[];
        invoice_date: string;
    }>({
        lorry_receipt_ids: preselectedIds,
        invoice_date: new Date().toISOString().slice(0, 10),
    });

    const selectedCustomers = Array.from(
        new Set(
            eligibleReceipts
                .filter((row) => form.data.lorry_receipt_ids.includes(row.id))
                .map((row) => row.billing_customer_party_id ?? ''),
        ),
    ).filter(Boolean);

    const mixedCustomer = selectedCustomers.length > 1;

    const toggleId = (id: string) => {
        const exists = form.data.lorry_receipt_ids.includes(id);
        form.setData(
            'lorry_receipt_ids',
            exists
                ? form.data.lorry_receipt_ids.filter((item) => item !== id)
                : [...form.data.lorry_receipt_ids, id],
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(urls.store);
    };

    return (
        <>
            <Head title="Create Partload Invoice" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Create Partload Customer Invoice"
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.index}>Partload Invoices</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.unified}>All Invoices</Link>
                            </Button>
                        </div>
                    }
                >
                    <form onSubmit={submit} className="space-y-4 p-4">
                        <div className="max-w-xs space-y-1.5">
                            <Label htmlFor="invoice_date" required>
                                Invoice Date
                            </Label>
                            <Input
                                id="invoice_date"
                                type="date"
                                value={form.data.invoice_date}
                                onChange={(e) =>
                                    form.setData('invoice_date', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.invoice_date} />
                        </div>

                        {mixedCustomer ? (
                            <p
                                role="alert"
                                className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                            >
                                Select Partload consignments belonging to a
                                single customer per invoice.
                            </p>
                        ) : null}

                        <div className="overflow-x-auto rounded-md border">
                            <table className="app-index-table min-w-full">
                                <thead>
                                    <tr>
                                        <th className="w-10" />
                                        <th>Bilty</th>
                                        <th>Date</th>
                                        <th>Route</th>
                                        <th>Customer</th>
                                        <th>Term</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {eligibleReceipts.length === 0 ? (
                                        <tr>
                                            <td colSpan={7}>
                                                <p className="app-index-empty">
                                                    No delivered/TBB Partload
                                                    consignments pending
                                                    billing.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        eligibleReceipts.map((row) => (
                                            <tr key={row.id}>
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select ${row.lr_number}`}
                                                        checked={form.data.lorry_receipt_ids.includes(
                                                            row.id,
                                                        )}
                                                        onChange={() =>
                                                            toggleId(row.id)
                                                        }
                                                    />
                                                </td>
                                                <td className="font-medium">
                                                    {row.lr_number}
                                                </td>
                                                <td>{row.lr_date ?? '—'}</td>
                                                <td>
                                                    {row.from_station_name ??
                                                        '—'}{' '}
                                                    →{' '}
                                                    {row.to_station_name ?? '—'}
                                                </td>
                                                <td>
                                                    {row.billing_customer_name ??
                                                        '—'}
                                                </td>
                                                <td className="uppercase">
                                                    {row.freight_payment_term ??
                                                        '—'}
                                                </td>
                                                <td>
                                                    {permissions.can_view_freight &&
                                                    row.freight_total !== null
                                                        ? `₹${row.freight_total}`
                                                        : '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <InputError message={form.errors.lorry_receipt_ids} />

                        <div className="flex justify-end gap-2">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.lorry_receipt_ids.length === 0 ||
                                    mixedCustomer
                                }
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                {form.processing
                                    ? 'Issuing…'
                                    : 'Issue Partload Invoice'}
                            </Button>
                        </div>
                    </form>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
