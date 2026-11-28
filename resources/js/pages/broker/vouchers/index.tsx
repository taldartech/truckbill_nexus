import { Head, Link, router } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { DisplayDate } from '@/components/display-date';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type VoucherRow = {
    id: string;
    voucher_number: string;
    voucher_date: string | null;
    status: string;
    payment_mode: string | null;
    payment_type: string;
    amount: string | null;
    lr_number: string | null;
    billing_customer_name: string | null;
    transporter_name: string | null;
    vehicle_registration: string | null;
    source: string | null;
    destination: string | null;
    next_status: string | null;
    urls: { show: string; status?: string };
};

type FilterOption = { value: string; label: string };

type Props = {
    filters: {
        search: string;
        lr_number: string;
        hire_number: string;
        truck_no: string;
        transporter_party_id: string;
        start_date: string;
        end_date: string;
        status: string;
        payment_mode: string;
    };
    filterOptions: { transporters: FilterOption[] };
    permissions: { can_create: boolean; can_status?: boolean };
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
    urls: {
        index: string;
        lorry_hires: string;
        export_payout?: string;
    };
};

export default function VouchersIndex({
    filters,
    filterOptions,
    permissions,
    statusOptions,
    paymentModeOptions,
    vouchers,
    urls,
}: Props) {
    const [rowProcessingId, setRowProcessingId] = useState<string | null>(null);
    const filterQuery = {
        search: filters.search || undefined,
        lr_number: filters.lr_number || undefined,
        hire_number: filters.hire_number || undefined,
        truck_no: filters.truck_no || undefined,
        transporter_party_id: filters.transporter_party_id || undefined,
        start_date: filters.start_date || undefined,
        end_date: filters.end_date || undefined,
        status: filters.status || undefined,
        payment_mode: filters.payment_mode || undefined,
    };

    return (
        <>
            <Head title="Voucher Payments" />
            <AppIndexPage className="voucher-register-index voucher-payments-index">
                <AppIndexListCard
                    title="Voucher Payments"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            {urls.export_payout ? (
                                <a
                                    href={urls.export_payout}
                                    className="app-index-header-btn"
                                >
                                    Export bank payout
                                </a>
                            ) : null}
                            {permissions.can_create ? (
                                <Link
                                    href={urls.lorry_hires}
                                    className="app-index-header-btn"
                                    title="Select Lorry Hires on the Good Hires register to record a Voucher Payment"
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    Record payment
                                </Link>
                            ) : null}
                        </div>
                    }
                >
                    <AppIndexFilters
                        className="app-index-filters--legacy"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const data = new FormData(event.currentTarget);
                            router.get(urls.index, {
                                search:
                                    (data.get('search') as string) || undefined,
                                lr_number:
                                    (data.get('lr_number') as string) ||
                                    undefined,
                                hire_number:
                                    (data.get('hire_number') as string) ||
                                    undefined,
                                truck_no:
                                    (data.get('truck_no') as string) ||
                                    undefined,
                                transporter_party_id:
                                    (data.get(
                                        'transporter_party_id',
                                    ) as string) || undefined,
                                start_date:
                                    (data.get('start_date') as string) ||
                                    undefined,
                                end_date:
                                    (data.get('end_date') as string) ||
                                    undefined,
                                status:
                                    (data.get('status') as string) || undefined,
                                payment_mode:
                                    (data.get('payment_mode') as string) ||
                                    undefined,
                            });
                        }}
                    >
                        <div className="broker-filter-field">
                            <Label htmlFor="search">Search</Label>
                            <input
                                id="search"
                                name="search"
                                defaultValue={filters.search}
                                className="broker-filter-control broker-filter-control--search"
                            />
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="lr_number">Bilty / LR</Label>
                            <input
                                id="lr_number"
                                name="lr_number"
                                defaultValue={filters.lr_number}
                                className="broker-filter-control"
                            />
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="hire_number">Good Hire No</Label>
                            <input
                                id="hire_number"
                                name="hire_number"
                                defaultValue={filters.hire_number}
                                className="broker-filter-control"
                            />
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="truck_no">Truck No</Label>
                            <input
                                id="truck_no"
                                name="truck_no"
                                defaultValue={filters.truck_no}
                                className="broker-filter-control"
                            />
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="transporter_party_id">
                                Transporter
                            </Label>
                            <select
                                id="transporter_party_id"
                                name="transporter_party_id"
                                defaultValue={filters.transporter_party_id}
                                className="broker-filter-control"
                            >
                                <option value="">All</option>
                                {filterOptions.transporters.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="start_date">From</Label>
                            <input
                                id="start_date"
                                name="start_date"
                                type="date"
                                defaultValue={filters.start_date}
                                className="broker-filter-control"
                            />
                        </div>
                        <div className="broker-filter-field">
                            <Label htmlFor="end_date">To</Label>
                            <input
                                id="end_date"
                                name="end_date"
                                type="date"
                                defaultValue={filters.end_date}
                                className="broker-filter-control"
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
                        <div className="broker-filter-field">
                            <Label htmlFor="payment_mode">Mode</Label>
                            <select
                                id="payment_mode"
                                name="payment_mode"
                                defaultValue={filters.payment_mode}
                                className="broker-filter-control"
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
                            className="h-8 bg-[#2FB36F] px-3 text-xs text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    <div
                        className="overflow-x-auto"
                        data-testid="voucher-payments-register"
                    >
                        <table
                            id="voucherPaymentsTable"
                            className="app-index-table min-w-full"
                        >
                            <thead>
                                <tr>
                                    <th>Voucher #</th>
                                    <th>Good Receipt (Bilty) No</th>
                                    <th>Customer</th>
                                    <th>Truck No</th>
                                    <th>Transporter</th>
                                    <th>Source</th>
                                    <th>Destination</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={11}>
                                            <p className="app-index-empty">
                                                No payments found.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    vouchers.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                <Link
                                                    href={row.urls.show}
                                                    className="text-[#0f766e] hover:underline"
                                                >
                                                    {row.voucher_number}
                                                </Link>
                                            </td>
                                            <td>{row.lr_number ?? '—'}</td>
                                            <td>
                                                {row.billing_customer_name ??
                                                    '—'}
                                            </td>
                                            <td>
                                                {row.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td>
                                                {row.transporter_name ?? '—'}
                                            </td>
                                            <td>{row.source ?? '—'}</td>
                                            <td>{row.destination ?? '—'}</td>
                                            <td className="text-nowrap">
                                                <DisplayDate
                                                    value={row.voucher_date}
                                                />
                                            </td>
                                            <td className="tabular-nums">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={row.status}
                                                />
                                            </td>
                                            <td>
                                                <div className="btn-action-row flex flex-nowrap items-center justify-center gap-1">
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
                                                                        preserveState: true,
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

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={urls.index}
                            meta={vouchers.meta}
                            query={filterQuery}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
