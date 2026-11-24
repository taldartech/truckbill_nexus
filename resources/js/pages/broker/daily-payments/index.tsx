import { Head, router, useForm } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
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
            <PageShell title="Daily Transporter Payments">
                <p className="text-sm text-muted-foreground">
                    Vouchers created on the selected date. Advance status in
                    bulk when authorised.
                </p>

                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const form = event.currentTarget;
                        const date = (
                            form.elements.namedItem('date') as HTMLInputElement
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
                </form>

                {permissions.can_status && selected.length > 0 && (
                    <div className="flex flex-wrap gap-2">
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
                )}

                <div className="overflow-hidden rounded-lg border border-border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/40 text-left">
                            <tr>
                                {permissions.can_status && (
                                    <th className="px-4 py-3" />
                                )}
                                <th className="px-4 py-3 font-medium">
                                    Voucher #
                                </th>
                                <th className="px-4 py-3 font-medium">Hire</th>
                                <th className="px-4 py-3 font-medium">
                                    Transporter
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Amount
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={permissions.can_status ? 7 : 6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No vouchers for this date.
                                    </td>
                                </tr>
                            ) : (
                                vouchers.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        {permissions.can_status && (
                                            <td className="px-4 py-3">
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
                                        )}
                                        <td className="px-4 py-3 font-medium">
                                            {row.voucher_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.hire_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.transporter_name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.amount ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.status}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <AppIconAction
                                                icon={Eye}
                                                label="Open voucher"
                                                href={row.urls.show}
                                                variant="info"
                                            />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </>
    );
}
