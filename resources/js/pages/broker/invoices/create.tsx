import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { PageShell } from '@/components/page-shell';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type EligibleLr = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    billing_customer_party_id: string | null;
    billing_customer_name: string | null;
    freight_total: string | null;
    memo_number: string | null;
    pod_number: string | null;
};

type Preview = {
    freight_total: string | null;
    charges_total: string | null;
    shortage_damage_total: string | null;
    taxable_total: string | null;
    gst_amount: string | null;
    invoice_total: string | null;
};

type Props = {
    filters: { billing_customer_party_id: string | null };
    billingCustomers: Array<{ id: string; name: string }>;
    eligibleWithMemo: EligibleLr[];
    eligibleWithoutMemo: EligibleLr[];
    permissions: { can_view_freight: boolean };
    urls: {
        index: string;
        store: string;
        preview: string;
        filter: string;
        parties_options?: string;
    };
};

function ReceiptSection({
    title,
    receipts,
    selected,
    onToggle,
    customerId,
}: {
    title: string;
    receipts: EligibleLr[];
    selected: string[];
    onToggle: (id: string) => void;
    customerId: string;
}) {
    const visible = useMemo(() => {
        if (!customerId) {
            return receipts;
        }

        return receipts.filter(
            (lr) => lr.billing_customer_party_id === customerId,
        );
    }, [receipts, customerId]);

    if (visible.length === 0) {
        return null;
    }

    return (
        <section className="space-y-3 rounded-lg border border-border p-4">
            <h2 className="text-sm font-semibold text-[#2FB36F]">{title}</h2>
            <ul className="space-y-2">
                {visible.map((lr) => (
                    <li key={lr.id}>
                        <label className="flex cursor-pointer items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={selected.includes(lr.id)}
                                onChange={() => onToggle(lr.id)}
                            />
                            <span>
                                <span className="font-medium">
                                    {lr.lr_number}
                                </span>
                                {lr.lr_date ? ` · ${lr.lr_date}` : ''}
                                {lr.memo_number
                                    ? ` · Memo ${lr.memo_number}`
                                    : ''}
                                {lr.pod_number ? ` · POD ${lr.pod_number}` : ''}
                                <br />
                                <span className="text-muted-foreground">
                                    {lr.billing_customer_name ?? 'No customer'}{' '}
                                    {lr.freight_total
                                        ? `· ${lr.freight_total}`
                                        : ''}
                                </span>
                            </span>
                        </label>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function BrokerInvoiceCreate({
    filters,
    billingCustomers,
    eligibleWithMemo,
    eligibleWithoutMemo,
    urls,
}: Props) {
    const [customerId, setCustomerId] = useState(
        filters.billing_customer_party_id ?? '',
    );
    const [selected, setSelected] = useState<string[]>([]);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);

    const toggle = (id: string) => {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    };

    useEffect(() => {
        if (selected.length === 0) {
            setPreview(null);

            return;
        }

        const token = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content');

        setPreviewLoading(true);
        fetch(urls.preview, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': token ?? '',
            },
            body: JSON.stringify({ lorry_receipt_ids: selected }),
        })
            .then((res) => res.json())
            .then((data: Preview) => setPreview(data))
            .catch(() => setPreview(null))
            .finally(() => setPreviewLoading(false));
    }, [selected, urls.preview]);

    const allReceipts = [...eligibleWithMemo, ...eligibleWithoutMemo];
    useEffect(() => {
        setSelected((prev) =>
            prev.filter((id) => {
                const lr = allReceipts.find((r) => r.id === id);
                if (!lr) {
                    return false;
                }
                if (!customerId) {
                    return true;
                }

                return lr.billing_customer_party_id === customerId;
            }),
        );
    }, [customerId, allReceipts]);

    const customerName =
        billingCustomers.find((row) => row.id === customerId)?.name ?? '—';

    const openConfirmation = () => {
        setReviewFields([
            { label: 'Billing customer', value: customerName },
            { label: 'Bilties selected', value: String(selected.length) },
            {
                label: 'Freight',
                value: preview?.freight_total ?? '—',
            },
            {
                label: 'Charges',
                value: preview?.charges_total ?? '—',
            },
            {
                label: 'GST',
                value: preview?.gst_amount ?? '—',
            },
            {
                label: 'Invoice total',
                value: preview?.invoice_total ?? '—',
            },
        ]);
        setConfirmOpen(true);
    };

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        if (!submitAllowed.current) {
            event.preventDefault();
            openConfirmation();
            return;
        }
        submitAllowed.current = false;
    };

    return (
        <>
            <Head title="Create Broker Invoice" />
            <PageShell width="form" title="Create Customer Invoice">
                <div className="mb-4 max-w-md">
                    <SearchablePartySelect
                        id="billing_customer_filter"
                        name="billing_customer_filter"
                        label="Billing customer filter"
                        options={billingCustomers}
                        searchUrl={urls.parties_options}
                        role="customer"
                        value={customerId}
                        onValueChange={(value) => {
                            setCustomerId(value);
                            router.get(
                                urls.filter,
                                {
                                    billing_customer_party_id:
                                        value || undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                        emptyMessage="No eligible Customers in this branch."
                    />
                </div>

                <Form
                    id="broker-invoice-create-form"
                    action={urls.store}
                    method="post"
                    className="space-y-4"
                    onSubmit={handleSubmit}
                >
                    {({ processing }) => (
                        <>
                            <ReceiptSection
                                title="Delivered with Memo / POD"
                                receipts={eligibleWithMemo}
                                selected={selected}
                                onToggle={toggle}
                                customerId={customerId}
                            />
                            <ReceiptSection
                                title="Delivered without Memo"
                                receipts={eligibleWithoutMemo}
                                selected={selected}
                                onToggle={toggle}
                                customerId={customerId}
                            />

                            {selected.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="lorry_receipt_ids[]"
                                    value={id}
                                />
                            ))}

                            <SearchablePartySelect
                                id="billing_customer_party_id"
                                name="billing_customer_party_id"
                                label="Billing customer"
                                options={billingCustomers}
                                searchUrl={urls.parties_options}
                                role="customer"
                                required
                                value={customerId}
                                onValueChange={setCustomerId}
                                emptyMessage="No eligible Customers in this branch."
                            />

                            <Label className="flex flex-col gap-1 text-sm">
                                Invoice date
                                <input
                                    type="date"
                                    name="invoice_date"
                                    className="rounded-md border border-border px-2 py-1.5"
                                />
                            </Label>

                            {preview && (
                                <div className="rounded-lg border border-[#2D8CFF]/30 bg-[#2D8CFF]/5 p-4 text-sm">
                                    <h3 className="font-semibold text-[#2D8CFF]">
                                        Freight preview
                                    </h3>
                                    <dl className="mt-2 grid grid-cols-2 gap-2">
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Freight
                                            </dt>
                                            <dd>
                                                {previewLoading
                                                    ? '…'
                                                    : (preview.freight_total ??
                                                      '—')}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Charges
                                            </dt>
                                            <dd>
                                                {preview.charges_total ?? '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Shortage / damage
                                            </dt>
                                            <dd>
                                                {preview.shortage_damage_total ??
                                                    '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                GST
                                            </dt>
                                            <dd>{preview.gst_amount ?? '—'}</dd>
                                        </div>
                                        <div className="col-span-2">
                                            <dt className="text-muted-foreground">
                                                Invoice total
                                            </dt>
                                            <dd className="text-lg font-semibold">
                                                {preview.invoice_total ?? '—'}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            )}

                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        selected.length === 0 ||
                                        !customerId
                                    }
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    Issue invoice
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href={urls.index}>Back</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Issue this customer invoice?"
                    description="Review customer, selected Bilties, and invoice totals before submitting."
                    fields={reviewFields}
                    onConfirm={() => {
                        submitAllowed.current = true;
                        setConfirmOpen(false);
                        window.setTimeout(() => {
                            const formElement = document.getElementById(
                                'broker-invoice-create-form',
                            ) as HTMLFormElement | null;
                            formElement?.requestSubmit();
                        }, 0);
                    }}
                />
            </PageShell>
        </>
    );
}
