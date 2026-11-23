import { Head, Link, router } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type VoucherRow = {
    id: string;
    voucher_number: string;
    status: string;
    payment_mode: string | null;
    payment_type: string;
    amount: string | null;
    hire_number: string | null;
    lr_number: string | null;
    transporter_name: string | null;
    vehicle_registration: string | null;
    created_at: string | null;
    urls: { show: string };
};

type Props = {
    filters: { status: string; payment_mode: string };
    permissions: { can_create: boolean };
    statusOptions: Array<{ value: string; label: string }>;
    paymentModeOptions: Array<{ value: string; label: string }>;
    vouchers: {
        data: VoucherRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string; create: string };
};

export default function VouchersIndex({
    filters,
    permissions,
    statusOptions,
    paymentModeOptions,
    vouchers,
    urls,
}: Props) {
    return (
        <>
            <Head title="Transporter Vouchers" />
            <PageShell
                title="Transporter Voucher Payments"
                description="Voucher register. Filter status=pending for the pending voucher queue."
                actions={
                    permissions.can_create ? (
                        <Link href={urls.create}>
                            <Button className="bg-[#2FB36F] text-white hover:bg-[#279960]">
                                Create voucher
                            </Button>
                        </Link>
                    ) : null
                }
            >
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const form = event.currentTarget;
                        const status = (
                            form.elements.namedItem(
                                'status',
                            ) as HTMLSelectElement
                        ).value;
                        const payment_mode = (
                            form.elements.namedItem(
                                'payment_mode',
                            ) as HTMLSelectElement
                        ).value;
                        router.get(
                            urls.index,
                            {
                                status: status || undefined,
                                payment_mode: payment_mode || undefined,
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <div className="space-y-1">
                        <Label htmlFor="status">Status</Label>
                        <select
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All</option>
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="payment_mode">Mode</Label>
                        <select
                            id="payment_mode"
                            name="payment_mode"
                            defaultValue={filters.payment_mode}
                            className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All</option>
                            {paymentModeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button
                        type="submit"
                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                    >
                        Filter
                    </Button>
                </form>

                <div className="overflow-hidden rounded-lg border border-border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/40 text-left">
                            <tr>
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
                                <th className="px-4 py-3 font-medium">Mode</th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No vouchers yet.
                                    </td>
                                </tr>
                            ) : (
                                vouchers.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={row.urls.show}
                                                className="font-medium text-[#2FB36F] hover:underline"
                                            >
                                                {row.voucher_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.hire_number}
                                            {row.lr_number
                                                ? ` · LR ${row.lr_number}`
                                                : ''}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.transporter_name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.amount ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.payment_mode}
                                        </td>
                                        <td className="px-4 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.status}
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
