import { Form, Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';

type EligibleReceipt = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    billing_customer_name: string | null;
    freight_total: string | null;
    source: string;
    source_label: string;
};

type Props = {
    eligibleReceipts: EligibleReceipt[];
    permissions: { can_view_freight: boolean };
    urls: { index: string; route_create: string };
    sampleBanner: string;
};

export default function CustomerInvoicesCreate({
    eligibleReceipts,
    permissions,
    urls,
    sampleBanner,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);

    const selectedSources = useMemo(() => {
        const ids = new Set(selected);
        return [
            ...new Set(
                eligibleReceipts
                    .filter((row) => ids.has(row.id))
                    .map((row) => row.source),
            ),
        ];
    }, [eligibleReceipts, selected]);

    const mixedSource = selectedSources.length > 1;

    const toggle = (id: string) => {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    return (
        <>
            <Head title="Create Customer Invoice" />
            <PageShell
                title="Create invoice"
                description="Pick eligible lorry receipts. The system routes to Own Fleet or Broker billing rules."
                actions={
                    <Button asChild variant="outline">
                        <Link href={urls.index}>Back to invoices</Link>
                    </Button>
                }
            >
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>

                {mixedSource ? (
                    <p
                        role="alert"
                        className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    >
                        Selected lorry receipts span more than one billing
                        source. Choose only Own Fleet or only Broker receipts.
                    </p>
                ) : null}

                <Form
                    action={urls.route_create}
                    method="post"
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <ul className="divide-y rounded-lg border border-border">
                                {eligibleReceipts.length === 0 ? (
                                    <li className="p-4 text-sm text-muted-foreground">
                                        No eligible lorry receipts for
                                        invoicing.
                                    </li>
                                ) : (
                                    eligibleReceipts.map((row) => (
                                        <li key={row.id} className="p-4">
                                            <label className="flex cursor-pointer items-start gap-3 text-sm">
                                                <input
                                                    type="checkbox"
                                                    name="lorry_receipt_ids[]"
                                                    value={row.id}
                                                    checked={selected.includes(
                                                        row.id,
                                                    )}
                                                    onChange={() =>
                                                        toggle(row.id)
                                                    }
                                                    className="mt-1"
                                                />
                                                <span>
                                                    <span className="font-medium">
                                                        {row.lr_number}
                                                    </span>{' '}
                                                    · {row.source_label}
                                                    {row.billing_customer_name
                                                        ? ` · ${row.billing_customer_name}`
                                                        : null}
                                                    {permissions.can_view_freight &&
                                                    row.freight_total
                                                        ? ` · ₹${row.freight_total}`
                                                        : null}
                                                </span>
                                            </label>
                                        </li>
                                    ))
                                )}
                            </ul>
                            {errors.lorry_receipt_ids ? (
                                <p className="text-sm text-destructive">
                                    {errors.lorry_receipt_ids}
                                </p>
                            ) : null}
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    selected.length === 0 ||
                                    mixedSource
                                }
                            >
                                Continue to source invoice form
                            </Button>
                        </>
                    )}
                </Form>
            </PageShell>
        </>
    );
}
