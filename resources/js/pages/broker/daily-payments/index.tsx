import { Head, router, useForm } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type VoucherRow = {
    id: string;
    voucher_number: string;
    status: string;
    payment_mode: string | null;
    payment_type: string;
    amount: string | null;
    lr_number: string | null;
    transporter_name: string | null;
    source: string | null;
    destination: string | null;
    created_at: string | null;
    next_status: string | null;
    urls: { show: string; status?: string };
};

type Props = {
    filters: { date: string; status: string };
    permissions: { can_status: boolean; can_view_freight?: boolean };
    totals: { count: number; amount: string | null };
    vouchers: VoucherRow[];
    urls: { index: string; bulk_status: string };
    statusOptions?: Array<{ value: string; label: string }>;
};

const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    { value: 'pending', label: 'Pending' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'paid', label: 'Paid' },
];

export default function DailyPaymentsIndex({
    filters,
    permissions,
    totals,
    vouchers,
    urls,
    statusOptions = STATUS_OPTIONS,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const bulkForm = useForm({
        voucher_ids: [] as string[],
        status: '',
        date: filters.date,
    });
    const [rowProcessingId, setRowProcessingId] = useState<string | null>(null);

    const toggle = (id: string) => {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((row) => row !== id)
                : [...current, id],
        );
    };

    return (
        <>
            <Head title="Daily Transporter Payments" />
            <AppIndexPage className="daily-payments-index">
                <AppIndexListCard title="Daily Transporter Payments">
                    <AppIndexFilters
                        className="app-index-filters--legacy"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const data = new FormData(event.currentTarget);
                            router.get(urls.index, {
                                date: (data.get('date') as string) || undefined,
                                status:
                                    (data.get('status') as string) || undefined,
                            });
                        }}
                    >
                        <div className="broker-filter-field">
                            <Label htmlFor="date">Payment date</Label>
                            <Input
                                id="date"
                                name="date"
                                type="date"
                                defaultValue={filters.date}
                                className="broker-filter-control h-8 text-xs"
                            />
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status}
                                className="broker-filter-control"
                            >
                                {statusOptions.map((option) => (
                                    <option
                                        key={option.value || 'all'}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button
                            type="submit"
                            className="h-8 bg-[#2FB36F] px-3 text-xs text-white hover:bg-[#279960]"
                        >
                            Load day
                        </Button>
                    </AppIndexFilters>

                    <div className="daily-payments-totals">
                        <span>Vouchers: {totals.count}</span>
                        {permissions.can_view_freight && totals.amount ? (
                            <span>Total amount: ₹{totals.amount}</span>
                        ) : null}
                    </div>

                    {permissions.can_status && selected.length > 0 ? (
                        <div className="flex flex-wrap gap-2 border-b border-[#e5e7eb] bg-white px-4 py-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    bulkForm.setData({
                                        voucher_ids: selected,
                                        status: 'in_progress',
                                        date: filters.date,
                                    });
                                    bulkForm.post(urls.bulk_status);
                                }}
                            >
                                Mark selected In Progress
                            </Button>
                            <Button
                                type="button"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                onClick={() => {
                                    bulkForm.setData({
                                        voucher_ids: selected,
                                        status: 'paid',
                                        date: filters.date,
                                    });
                                    bulkForm.post(urls.bulk_status);
                                }}
                            >
                                Mark selected Paid
                            </Button>
                        </div>
                    ) : null}

                    <div
                        className="overflow-x-auto"
                        data-testid="daily-transporter-payments"
                    >
                        <table
                            id="paymentsTable"
                            className="app-index-table min-w-full"
                        >
                            <thead>
                                <tr>
                                    {permissions.can_status ? (
                                        <th className="text-center"> </th>
                                    ) : null}
                                    <th>Voucher #</th>
                                    <th>LR No</th>
                                    <th>Transporter</th>
                                    <th>Source</th>
                                    <th>Destination</th>
                                    <th>Payment Type</th>
                                    <th>Payment Mode</th>
                                    <th className="text-end">Amount</th>
                                    <th>Status</th>
                                    <th>Created Time</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                permissions.can_status ? 12 : 11
                                            }
                                        >
                                            <p className="app-index-empty">
                                                No vouchers for this date.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    vouchers.map((row) => (
                                        <tr key={row.id}>
                                            {permissions.can_status ? (
                                                <td className="text-center">
                                                    <input
                                                        type="checkbox"
                                                        checked={selected.includes(
                                                            row.id,
                                                        )}
                                                        onChange={() =>
                                                            toggle(row.id)
                                                        }
                                                    />
                                                </td>
                                            ) : null}
                                            <td className="font-medium">
                                                {row.voucher_number}
                                            </td>
                                            <td>{row.lr_number ?? '—'}</td>
                                            <td>
                                                {row.transporter_name ?? '—'}
                                            </td>
                                            <td>{row.source ?? '—'}</td>
                                            <td>{row.destination ?? '—'}</td>
                                            <td>{row.payment_type ?? '—'}</td>
                                            <td>{row.payment_mode ?? '—'}</td>
                                            <td className="text-end tabular-nums">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={row.status}
                                                />
                                            </td>
                                            <td className="text-nowrap tabular-nums">
                                                {row.created_at ?? '—'}
                                            </td>
                                            <td>
                                                <div className="flex flex-nowrap items-center justify-center gap-1">
                                                    {permissions.can_status &&
                                                    row.next_status &&
                                                    row.urls.status ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-7 text-xs"
                                                            disabled={
                                                                rowProcessingId ===
                                                                row.id
                                                            }
                                                            onClick={() => {
                                                                setRowProcessingId(
                                                                    row.id,
                                                                );
                                                                router.post(
                                                                    row.urls
                                                                        .status!,
                                                                    {
                                                                        status: row.next_status,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                        onFinish:
                                                                            () =>
                                                                                setRowProcessingId(
                                                                                    null,
                                                                                ),
                                                                    },
                                                                );
                                                            }}
                                                        >
                                                            Mark{' '}
                                                            {row.next_status.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Button>
                                                    ) : null}
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="Open voucher"
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
