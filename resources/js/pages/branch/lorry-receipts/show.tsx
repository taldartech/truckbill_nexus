import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type LorryReceipt = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    status: string;
    from_text: string | null;
    to_text: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    consignor_name: string | null;
    consignee_name: string | null;
    goods_value: string | null;
    freight_total: string;
    additional_charges_total: string;
    cancellation_remark: string | null;
    goods: Array<{
        id: string;
        goods_description: string | null;
        package_count: string | null;
        actual_weight: string | null;
        charged_weight: string | null;
        freight_rate_type: string;
        freight_rate: string;
        freight_amount: string;
        goods_value: string | null;
    }>;
    eway_bills: Array<{
        id: string;
        ewb_number: string;
        ewb_date: string | null;
        valid_until: string | null;
    }>;
    additional_charges: Array<{
        id: string;
        description: string;
        amount: string;
    }>;
};

type Props = {
    lorryReceipt: LorryReceipt;
    permissions: {
        can_edit: boolean;
        can_cancel: boolean;
        can_delete: boolean;
        can_create_trip?: boolean;
    };
    linkedTrip?: {
        id: string;
        status: string;
        show_url: string;
    } | null;
    urls: {
        index: string;
        edit: string;
        cancel: string;
        destroy: string;
        print: string;
        create_trip?: string;
    };
};

export default function LorryReceiptShow({
    lorryReceipt,
    permissions,
    linkedTrip = null,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;
    const [confirmDelete, setConfirmDelete] = useState(false);
    const isPending = lorryReceipt.status === 'pending';

    return (
        <>
            <Head title={`LR ${lorryReceipt.lr_number}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Lorry Receipts
                    </Link>
                    <MasterPageHeader title={`LR ${lorryReceipt.lr_number}`} />
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {permissions.can_edit && isPending ? (
                        <Button asChild variant="secondary">
                            <Link href={urls.edit}>Edit</Link>
                        </Button>
                    ) : null}
                    {permissions.can_create_trip && urls.create_trip ? (
                        <Button
                            asChild
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            <Link href={urls.create_trip}>Create Trip</Link>
                        </Button>
                    ) : null}
                    {linkedTrip ? (
                        <Button asChild variant="secondary">
                            <Link href={linkedTrip.show_url}>Open Trip</Link>
                        </Button>
                    ) : null}
                    <Button asChild variant="secondary">
                        <Link href={urls.print}>Print</Link>
                    </Button>
                    {permissions.can_delete && isPending ? (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => setConfirmDelete((value) => !value)}
                        >
                            Delete
                        </Button>
                    ) : null}
                </div>

                {confirmDelete ? (
                    <Form
                        action={urls.destroy}
                        method="delete"
                        className="rounded-lg border border-destructive/40 bg-destructive/5 p-4"
                    >
                        {({ processing }) => (
                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <p className="text-sm">
                                    Soft-delete this pending LR? This cannot be
                                    undone from the UI.
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setConfirmDelete(false)}
                                    >
                                        Keep
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Confirm delete
                                    </Button>
                                </div>
                                {errors?.delete ? (
                                    <p className="text-sm text-destructive">
                                        {errors.delete}
                                    </p>
                                ) : null}
                            </div>
                        )}
                    </Form>
                ) : null}

                <section className="grid gap-4 rounded-xl border border-border bg-card p-4 md:grid-cols-2 md:p-5 lg:grid-cols-3">
                    <Detail label="Status" value={lorryReceipt.status} />
                    <Detail
                        label="Context"
                        value={lorryReceipt.workflow_context.replaceAll(
                            '_',
                            ' ',
                        )}
                    />
                    <Detail label="Date" value={lorryReceipt.lr_date || '—'} />
                    <Detail
                        label="From"
                        value={
                            lorryReceipt.from_station_name ||
                            lorryReceipt.from_text ||
                            '—'
                        }
                    />
                    <Detail
                        label="To"
                        value={
                            lorryReceipt.to_station_name ||
                            lorryReceipt.to_text ||
                            '—'
                        }
                    />
                    <Detail
                        label="Consignor"
                        value={lorryReceipt.consignor_name || '—'}
                    />
                    <Detail
                        label="Consignee"
                        value={lorryReceipt.consignee_name || '—'}
                    />
                    <Detail
                        label="Freight total"
                        value={lorryReceipt.freight_total}
                    />
                    <Detail
                        label="Additional charges"
                        value={lorryReceipt.additional_charges_total}
                    />
                    <Detail
                        label="Goods value"
                        value={lorryReceipt.goods_value || '—'}
                    />
                </section>

                <section className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                    <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        Goods
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="px-2 py-2 font-medium">
                                        Description
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Pkgs
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Weight
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Rate
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Freight
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {lorryReceipt.goods.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-border/70"
                                    >
                                        <td className="px-2 py-2">
                                            {row.goods_description || '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.package_count || '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.actual_weight || '—'} /{' '}
                                            {row.charged_weight || '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.freight_rate_type.replaceAll(
                                                '_',
                                                ' ',
                                            )}{' '}
                                            @ {row.freight_rate}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.freight_amount}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            E-way bills
                        </h2>
                        {lorryReceipt.eway_bills.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                None
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {lorryReceipt.eway_bills.map((bill) => (
                                    <li key={bill.id}>
                                        {bill.ewb_number} · valid until{' '}
                                        {bill.valid_until}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <div className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Additional charges
                        </h2>
                        {lorryReceipt.additional_charges.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                None
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {lorryReceipt.additional_charges.map(
                                    (charge) => (
                                        <li
                                            key={charge.id}
                                            className="flex justify-between gap-3"
                                        >
                                            <span>{charge.description}</span>
                                            <span>{charge.amount}</span>
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                    </div>
                </section>

                {permissions.can_cancel &&
                lorryReceipt.status !== 'cancelled' &&
                lorryReceipt.status !== 'delivered' ? (
                    <section className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Cancel LR
                        </h2>
                        <Form
                            action={urls.cancel}
                            method="post"
                            className="grid gap-3 md:max-w-xl"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="remark">Remark</Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required
                                        />
                                        {errors?.remark ? (
                                            <p className="text-sm text-destructive">
                                                {errors.remark}
                                            </p>
                                        ) : null}
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Cancel Lorry Receipt
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                ) : null}

                {lorryReceipt.cancellation_remark ? (
                    <p className="text-sm text-muted-foreground">
                        Cancellation remark: {lorryReceipt.cancellation_remark}
                    </p>
                ) : null}
            </div>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="text-sm">{value}</p>
        </div>
    );
}
