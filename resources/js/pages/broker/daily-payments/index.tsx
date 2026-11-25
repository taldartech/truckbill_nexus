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
    amount: string | null;
    hire_number: string | null;
    transporter_name: string | null;
    next_status: string | null;
    urls: { show: string };
};

type Props = {
    filters: { date: string; status: string };
    permissions: { can_status: boolean };
    vouchers: VoucherRow[];
    urls: { index: string; bulk_status: string };
};

export default function DailyPaymentsIndex({
    filters,
    permissions,
    vouchers,
    urls,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const bulkForm = useForm({
        voucher_ids: [] as string[],
        status: '',
        date: filters.date,
    });

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
            <AppIndexPage>
                <AppIndexListCard title="Daily Transporter Payments">
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const date = (
                                form.elements.namedItem(
                                    'date',
                                ) as HTMLInputElement
                            ).value;
                            router.get(
                                urls.index,
                                { date: date || undefined },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="date">Date</Label>
                            <Input
                                id="date"
                                name="date"
                                type="date"
                                defaultValue={filters.date}
                            />
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Load day
                        </Button>
                    </AppIndexFilters>

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

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    {permissions.can_status ? (
                                        <th className="text-center"> </th>
                                    ) : null}
                                    <th>Voucher #</th>
                                    <th>Hire</th>
                                    <th>Transporter</th>
                                    <th className="text-end">Amount</th>
                                    <th>Mode</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                permissions.can_status ? 8 : 7
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
                                            <td>{row.hire_number ?? '—'}</td>
                                            <td>
                                                {row.transporter_name ?? '—'}
                                            </td>
                                            <td className="text-end tabular-nums">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td>{row.payment_mode ?? '—'}</td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={row.status}
                                                />
                                            </td>
                                            <td>
                                                <div className="flex justify-center">
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
