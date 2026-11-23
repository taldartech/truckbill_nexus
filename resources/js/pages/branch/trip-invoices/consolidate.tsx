import { Form, Head, Link } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { useState } from 'react';

type Eligible = {
    id: string;
    lr_number: string;
    freight_total: string;
    billing_customer_name: string | null;
};

type Props = {
    eligibleReceipts: Eligible[];
    particularsOptions: Array<{ value: string; label: string }>;
    sampleBanner: string;
};

export default function TripInvoiceConsolidate({
    eligibleReceipts,
    particularsOptions,
    sampleBanner,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);

    const toggle = (id: string) => {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    };

    return (
        <>
            <Head title="Consolidated Invoice" />
            <PageShell width="form" title="Consolidated OwnFleet Invoice">
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>
                <Form
                    action="/trip-invoices/consolidated"
                    method="post"
                    className="flex w-full flex-col gap-4"
                >
                    {({ processing }) => (
                        <>
                            <div className="space-y-2 rounded-lg border p-3">
                                {eligibleReceipts.map((lr) => (
                                    <label
                                        key={lr.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(lr.id)}
                                            onChange={() => toggle(lr.id)}
                                        />
                                        <span>
                                            {lr.lr_number} —{' '}
                                            {lr.billing_customer_name} (₹
                                            {lr.freight_total})
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {selected.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="lorry_receipt_ids[]"
                                    value={id}
                                />
                            ))}
                            <label className="flex flex-col gap-1 text-sm">
                                Particulars
                                <select
                                    name="particulars_mode"
                                    className="rounded-md border px-2 py-1"
                                    defaultValue="combined_freight"
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
                            </label>
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={processing || selected.length < 2}
                                >
                                    Create consolidated invoice
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
