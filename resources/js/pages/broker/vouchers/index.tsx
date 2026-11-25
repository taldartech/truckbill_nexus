import { Head, Link, router } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
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
            <Head title="Voucher Payments" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Voucher Payments"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={urls.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create voucher
                            </Link>
                        ) : null
                    }
                >
                    <AppIndexFilters
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
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
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
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
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
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Voucher #</th>
                                    <th>LR / Hire</th>
                                    <th>Transporter</th>
                                    <th>Truck</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th className="text-end">Amount</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={10}>
                                            <p className="app-index-empty">
                                                No vouchers yet.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    vouchers.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>{row.created_at ?? '—'}</td>
                                            <td className="font-medium">
                                                {row.voucher_number}
                                            </td>
                                            <td>
                                                {row.lr_number
                                                    ? `LR ${row.lr_number}`
                                                    : '—'}
                                                {row.hire_number
                                                    ? ` · ${row.hire_number}`
                                                    : ''}
                                            </td>
                                            <td>
                                                {row.transporter_name ?? '—'}
                                            </td>
                                            <td>
                                                {row.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td>{row.payment_type}</td>
                                            <td>{row.payment_mode ?? '—'}</td>
                                            <td className="text-end tabular-nums">
                                                {row.amount ?? '—'}
                                            </td>
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
