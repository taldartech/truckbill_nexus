import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EligibleRow = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    billing_customer_party_id: string | null;
    billing_customer_name: string | null;
    delivery_status: string | null;
    memo_number: string | null;
    urls: { complete: string };
};

type RecentRow = {
    id: string;
    lr_number: string | null;
    status: string;
    memo_number: string | null;
    pod_number: string | null;
    delivery_date: string | null;
};

type Props = {
    permissions: { can_view: boolean; can_manage: boolean };
    eligible: {
        data: EligibleRow[];
        meta: { total: number };
    };
    recentDeliveries: RecentRow[];
    urls: { index: string; memo: string };
};

export default function DeliveriesIndex({
    permissions,
    eligible,
    recentDeliveries,
    urls,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const memoForm = useForm({
        lorry_receipt_ids: [] as string[],
        memo_number: '',
        pod_number: '',
        remarks: '',
    });

    const selectedCustomer = useMemo(() => {
        if (selected.length === 0) {
            return null;
        }
        const first = eligible.data.find((row) => row.id === selected[0]);
        return first?.billing_customer_party_id ?? null;
    }, [eligible.data, selected]);

    const toggle = (id: string, billingCustomerId: string | null) => {
        setSelected((current) => {
            if (current.includes(id)) {
                return current.filter((rowId) => rowId !== id);
            }
            if (
                selectedCustomer !== null &&
                billingCustomerId !== selectedCustomer
            ) {
                return current;
            }
            return [...current, id];
        });
    };

    const submitMemo = (event: React.FormEvent) => {
        event.preventDefault();
        memoForm
            .transform((data) => ({
                ...data,
                lorry_receipt_ids: selected,
            }))
            .post(urls.memo, {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    memoForm.reset();
                },
            });
    };

    return (
        <>
            <Head title="Broker Delivery & Memo" />
            <PageShell
                title="Delivery, Memo & POD"
                description="Assign one memo to multiple Broker LRs for the same billing customer, then record delivery."
            >
                {permissions.can_manage ? (
                    <form
                        onSubmit={submitMemo}
                        className="rounded-lg border border-border bg-card p-4"
                    >
                        <h2 className="text-sm font-semibold text-foreground">
                            Bulk memo / POD assignment
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Select LRs with the same billing customer ({' '}
                            {selected.length} selected).
                        </p>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <Label htmlFor="memo_number">Memo number</Label>
                                <Input
                                    id="memo_number"
                                    value={memoForm.data.memo_number}
                                    onChange={(e) =>
                                        memoForm.setData(
                                            'memo_number',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="pod_number">POD number</Label>
                                <Input
                                    id="pod_number"
                                    value={memoForm.data.pod_number}
                                    onChange={(e) =>
                                        memoForm.setData(
                                            'pod_number',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <Label htmlFor="remarks">Remarks</Label>
                                <Input
                                    id="remarks"
                                    value={memoForm.data.remarks}
                                    onChange={(e) =>
                                        memoForm.setData(
                                            'remarks',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <Button
                            type="submit"
                            className="mt-4 bg-[#2FB36F] text-white hover:bg-[#279960]"
                            disabled={
                                selected.length === 0 || memoForm.processing
                            }
                        >
                            Assign memo to selected
                        </Button>
                    </form>
                ) : null}

                <div className="mt-8 overflow-hidden rounded-lg border border-border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/40 text-left">
                            <tr>
                                {permissions.can_manage ? (
                                    <th className="w-10 px-4 py-3"> </th>
                                ) : null}
                                <th className="px-4 py-3">LR</th>
                                <th className="px-4 py-3">Billing customer</th>
                                <th className="px-4 py-3">Memo</th>
                                <th className="px-4 py-3">Status</th>
                                {permissions.can_manage ? (
                                    <th className="px-4 py-3">Complete</th>
                                ) : null}
                            </tr>
                        </thead>
                        <tbody>
                            {eligible.data.map((row) => (
                                <EligibleRowView
                                    key={row.id}
                                    row={row}
                                    canManage={permissions.can_manage}
                                    checked={selected.includes(row.id)}
                                    disabled={
                                        selectedCustomer !== null &&
                                        row.billing_customer_party_id !==
                                            selectedCustomer &&
                                        !selected.includes(row.id)
                                    }
                                    onToggle={() =>
                                        toggle(
                                            row.id,
                                            row.billing_customer_party_id,
                                        )
                                    }
                                />
                            ))}
                        </tbody>
                    </table>
                    <p className="px-4 py-2 text-xs text-muted-foreground">
                        {eligible.meta.total} eligible LR
                        {eligible.meta.total === 1 ? '' : 's'}
                    </p>
                </div>

                {recentDeliveries.length > 0 ? (
                    <div className="mt-8">
                        <h2 className="text-sm font-semibold">
                            Recent activity
                        </h2>
                        <ul className="mt-2 space-y-2 text-sm">
                            {recentDeliveries.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-center gap-2 rounded-md border border-border px-3 py-2"
                                >
                                    <span className="font-medium">
                                        {row.lr_number ?? '—'}
                                    </span>
                                    <WorkspaceStatusBadge status={row.status} />
                                    {row.memo_number ? (
                                        <span className="text-muted-foreground">
                                            Memo {row.memo_number}
                                        </span>
                                    ) : null}
                                    {row.delivery_date ? (
                                        <span className="text-muted-foreground">
                                            Delivered {row.delivery_date}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </PageShell>
        </>
    );
}

function EligibleRowView({
    row,
    canManage,
    checked,
    disabled,
    onToggle,
}: {
    row: EligibleRow;
    canManage: boolean;
    checked: boolean;
    disabled: boolean;
    onToggle: () => void;
}) {
    const completeForm = useForm({ delivery_date: '', remarks: '' });

    return (
        <tr className="border-t border-border">
            {canManage ? (
                <td className="px-4 py-3">
                    <input
                        type="checkbox"
                        checked={checked}
                        disabled={disabled}
                        onChange={onToggle}
                        aria-label={`Select LR ${row.lr_number}`}
                    />
                </td>
            ) : null}
            <td className="px-4 py-3 font-medium">{row.lr_number}</td>
            <td className="px-4 py-3">{row.billing_customer_name ?? '—'}</td>
            <td className="px-4 py-3">{row.memo_number ?? '—'}</td>
            <td className="px-4 py-3">
                {row.delivery_status ? (
                    <WorkspaceStatusBadge status={row.delivery_status} />
                ) : (
                    '—'
                )}
            </td>
            {canManage ? (
                <td className="px-4 py-3">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            completeForm.post(row.urls.complete, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Input
                            type="date"
                            className="w-36"
                            value={completeForm.data.delivery_date}
                            onChange={(e) =>
                                completeForm.setData(
                                    'delivery_date',
                                    e.target.value,
                                )
                            }
                            required
                            aria-label={`Delivery date for ${row.lr_number}`}
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={completeForm.processing}
                        >
                            Record delivery
                        </Button>
                    </form>
                </td>
            ) : null}
        </tr>
    );
}
