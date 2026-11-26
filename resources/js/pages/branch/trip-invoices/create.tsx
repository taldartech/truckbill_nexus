import { Form, Head, Link } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useState } from 'react';

type Eligible = {
    id: string;
    lr_number: string;
    freight_total: string;
    billing_customer_name: string | null;
};

type Props = {
    preselectedLrId?: string | null;
    eligibleReceipts: Eligible[];
    chargeTypeOptions: Array<{ value: string; label: string }>;
    particularsOptions: Array<{ value: string; label: string }>;
    sampleBanner: string;
};

export default function TripInvoiceCreate({
    preselectedLrId,
    eligibleReceipts,
    chargeTypeOptions,
    particularsOptions,
    sampleBanner,
}: Props) {
    const [lorryReceiptId, setLorryReceiptId] = useState(preselectedLrId ?? '');
    const [charges, setCharges] = useState<
        Array<{ charge_type: string; description: string; amount: string }>
    >([]);

    return (
        <>
            <Head title="Create Customer Invoice (Own Fleet)" />
            <PageShell title="Individual customer invoice">
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>
                <Form
                    action="/trip-invoices/individual"
                    method="post"
                    className="flex w-full min-w-0 flex-col gap-4"
                >
                    {({ processing }) => (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="lorry_receipt_id" required>
                                    Completed Own Fleet LR
                                </Label>
                                <select
                                    id="lorry_receipt_id"
                                    name="lorry_receipt_id"
                                    required
                                    className="rounded-md border px-2 py-1 text-sm"
                                    value={lorryReceiptId}
                                    onChange={(e) =>
                                        setLorryReceiptId(e.target.value)
                                    }
                                >
                                    <option value="">Select…</option>
                                    {eligibleReceipts.map((lr) => (
                                        <option key={lr.id} value={lr.id}>
                                            {lr.lr_number} —{' '}
                                            {lr.billing_customer_name} (₹
                                            {lr.freight_total})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="invoice_date">
                                    Invoice date
                                </Label>
                                <Input
                                    id="invoice_date"
                                    type="date"
                                    name="invoice_date"
                                />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="particulars_mode">
                                    Particulars
                                </Label>
                                <select
                                    id="particulars_mode"
                                    name="particulars_mode"
                                    className="rounded-md border px-2 py-1 text-sm"
                                    defaultValue="separate_lines"
                                >
                                    {particularsOptions.map((opt) => (
                                        <option
                                            key={opt.value}
                                            value={opt.value}
                                        >
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {charges.map((charge, index) => (
                                <div
                                    key={index}
                                    className="grid gap-2 rounded border p-3 md:grid-cols-3"
                                >
                                    <select
                                        name={`charges[${index}][charge_type]`}
                                        defaultValue={charge.charge_type}
                                        className="rounded-md border px-2 py-1"
                                    >
                                        {chargeTypeOptions.map((opt) => (
                                            <option
                                                key={opt.value}
                                                value={opt.value}
                                            >
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                    <input
                                        name={`charges[${index}][description]`}
                                        placeholder="Description"
                                        defaultValue={charge.description}
                                        className="rounded-md border px-2 py-1"
                                    />
                                    <input
                                        name={`charges[${index}][amount]`}
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        defaultValue={charge.amount}
                                        className="rounded-md border px-2 py-1"
                                    />
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    setCharges((prev) => [
                                        ...prev,
                                        {
                                            charge_type:
                                                chargeTypeOptions[0]?.value ??
                                                'other',
                                            description: '',
                                            amount: '',
                                        },
                                    ])
                                }
                            >
                                Add charge
                            </Button>
                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    Create invoice
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href="/trip-invoices">Back</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PageShell>
        </>
    );
}
