import { Head, router } from '@inertiajs/react';
import { Ban, Eye, Pencil } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { MemoPodDialog } from '@/components/broker/memo-pod-dialog';
import { VoucherPaymentDialog } from '@/components/broker/voucher-payment-dialog';
import { BulkSelectionToolbar } from '@/components/bulk-selection-toolbar';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useCrossPageSelection } from '@/hooks/use-cross-page-selection';
import { DisplayDate } from '@/components/display-date';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatRate2, formatWeightMt2 } from '@/lib/decimal-format';

type HireRow = {
    id: string;
    hire_number: string;
    status: string;
    hire_date: string | null;
    lr_date: string | null;
    lr_number: string | null;
    billing_customer_name: string | null;
    vehicle_registration: string | null;
    transporter_name: string | null;
    transporter_party_id: string | null;
    vehicle_id: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    weight_mt: string | null;
    rate: string | null;
    freight_total: string | null;
    outstanding_amount: string | null;
    created_at: string | null;
    payable: boolean;
    lorry_receipt_id: string;
    billing_customer_party_id: string | null;
    memo_number: string | null;
    memo_pending: boolean;
    row_tone?: string;
    urls: { show: string; edit?: string | null };
};

type SelectedHire = Pick<
    HireRow,
    | 'id'
    | 'hire_number'
    | 'transporter_party_id'
    | 'vehicle_id'
    | 'payable'
    | 'lorry_receipt_id'
    | 'billing_customer_party_id'
    | 'memo_pending'
>;

type FilterOption = { value: string; label: string };

type Props = {
    filters: {
        search: string;
        lr_number: string;
        customer_party_id: string;
        transporter_party_id: string;
        vehicle_id: string;
        start_date: string;
        end_date: string;
        status: string;
        pending_memo: boolean;
        payable_only: boolean;
    };
    filterOptions: {
        customers: FilterOption[];
        transporters: FilterOption[];
        vehicles: FilterOption[];
    };
    permissions: {
        can_view: boolean;
        can_create: boolean;
        can_create_voucher?: boolean;
        can_bulk_voucher?: boolean;
        can_assign_memo?: boolean;
    };
    statusOptions: Array<{ value: string; label: string }>;
    lorryHires: {
        data: HireRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: {
        index: string;
        payment_context: string | null;
        voucher_store: string | null;
        memo_context: string | null;
        memo_store: string | null;
    };
};

function balanceClass(amount: string | null): string {
    const value = Number(amount ?? NaN);
    if (!Number.isFinite(value)) {
        return '';
    }
    return value > 0 ? 'is-balance-due' : 'is-balance-clear';
}

/** Legacy voucher rule: one Payee and one Truck per payment. */
function voucherBlocker(rows: SelectedHire[], canBulk: boolean): string | null {
    const [first] = rows;
    if (rows.some((row) => !row.payable)) {
        return 'Voucher Payment: some selected Lorry Hires have nothing to pay.';
    }
    if (rows.length > 1 && !canBulk) {
        return 'Voucher Payment: you are not authorized for bulk payment. Select one Lorry Hire.';
    }
    if (
        rows.some(
            (row) => row.transporter_party_id !== first?.transporter_party_id,
        )
    ) {
        return 'Voucher Payment: select Lorry Hires of the same Payee.';
    }
    if (rows.some((row) => row.vehicle_id !== first?.vehicle_id)) {
        return 'Voucher Payment: selected Lorry Hires belong to different Truck Numbers.';
    }

    return null;
}

/** Legacy memo rule: Bilties without a memo, all for one customer. */
function memoBlocker(rows: SelectedHire[]): string | null {
    const [first] = rows;
    if (rows.some((row) => !row.memo_pending)) {
        return 'Memo / POD: some selected Bilties already have a memo or are not awaiting delivery.';
    }
    if (
        rows.some(
            (row) =>
                row.billing_customer_party_id !==
                first?.billing_customer_party_id,
        )
    ) {
        return 'Memo / POD: selected Bilties belong to different customers.';
    }

    return null;
}

function rowToneClass(tone?: string): string {
    if (tone === 'cancelled') {
        return 'is-row-cancelled';
    }
    if (tone === 'delivered') {
        return 'is-row-delivered';
    }

    return '';
}

export default function LorryHiresIndex({
    filters,
    filterOptions,
    permissions,
    statusOptions,
    lorryHires,
    urls,
}: Props) {
    const selection = useCrossPageSelection<SelectedHire>(
        'broker.lorry-hires.voucher-selection',
    );
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [memoOpen, setMemoOpen] = useState(false);
    const voucherUrls =
        urls.payment_context && urls.voucher_store
            ? {
                  payment_context: urls.payment_context,
                  voucher_store: urls.voucher_store,
              }
            : null;
    const memoUrls =
        urls.memo_context && urls.memo_store
            ? { memo_context: urls.memo_context, memo_store: urls.memo_store }
            : null;
    const canSelect = voucherUrls !== null || memoUrls !== null;
    const voucherReason = voucherUrls
        ? voucherBlocker(selection.rows, Boolean(permissions.can_bulk_voucher))
        : null;
    const memoReason = memoUrls ? memoBlocker(selection.rows) : null;
    const selectableOnPage = lorryHires.data.filter(
        (row) => row.payable || row.memo_pending,
    );
    const allOnPageSelected =
        selectableOnPage.length > 0 &&
        selectableOnPage.every((row) => selection.isSelected(row.id));
    const snapshot = (row: HireRow): SelectedHire => ({
        id: row.id,
        hire_number: row.hire_number,
        transporter_party_id: row.transporter_party_id,
        vehicle_id: row.vehicle_id,
        payable: row.payable,
        lorry_receipt_id: row.lorry_receipt_id,
        billing_customer_party_id: row.billing_customer_party_id,
        memo_pending: row.memo_pending,
    });
    const filterQuery = {
        search: filters.search || undefined,
        lr_number: filters.lr_number || undefined,
        customer_party_id: filters.customer_party_id || undefined,
        transporter_party_id: filters.transporter_party_id || undefined,
        vehicle_id: filters.vehicle_id || undefined,
        start_date: filters.start_date || undefined,
        end_date: filters.end_date || undefined,
        status: filters.status || undefined,
        pending_memo: filters.pending_memo ? 1 : undefined,
        payable_only: filters.payable_only ? 1 : undefined,
    };
    const columnCount = canSelect ? 12 : 11;

    return (
        <>
            <Head title="Good Hires (Lorry Hire)" />
            <AppIndexPage className="freight-bookings-index">
                <AppIndexListCard title="Good Hires (Lorry Hire)">
                    <AppIndexFilters
                        className="app-index-filters--legacy"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const data = new FormData(form);
                            router.get(
                                urls.index,
                                {
                                    search:
                                        (data.get('search') as string) ||
                                        undefined,
                                    lr_number:
                                        (data.get('lr_number') as string) ||
                                        undefined,
                                    customer_party_id:
                                        (data.get(
                                            'customer_party_id',
                                        ) as string) || undefined,
                                    transporter_party_id:
                                        (data.get(
                                            'transporter_party_id',
                                        ) as string) || undefined,
                                    vehicle_id:
                                        (data.get('vehicle_id') as string) ||
                                        undefined,
                                    start_date:
                                        (data.get('start_date') as string) ||
                                        undefined,
                                    end_date:
                                        (data.get('end_date') as string) ||
                                        undefined,
                                    status:
                                        (data.get('status') as string) ||
                                        undefined,
                                    pending_memo: data.get('pending_memo')
                                        ? 1
                                        : undefined,
                                    payable_only: data.get('payable_only')
                                        ? 1
                                        : undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="broker-filter-field">
                            <Label htmlFor="search">Search</Label>
                            <input
                                id="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="LR, city, route…"
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
                            <Label htmlFor="customer_party_id">Customer</Label>
                            <select
                                id="customer_party_id"
                                name="customer_party_id"
                                defaultValue={filters.customer_party_id}
                                className="broker-filter-control"
                            >
                                <option value="">All Customers</option>
                                {filterOptions.customers.map((option) => (
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
                            <Label htmlFor="transporter_party_id">
                                Transporter
                            </Label>
                            <select
                                id="transporter_party_id"
                                name="transporter_party_id"
                                defaultValue={filters.transporter_party_id}
                                className="broker-filter-control"
                            >
                                <option value="">All Transporters</option>
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
                            <Label htmlFor="vehicle_id">Truck</Label>
                            <select
                                id="vehicle_id"
                                name="vehicle_id"
                                defaultValue={filters.vehicle_id}
                                className="broker-filter-control"
                            >
                                <option value="">All Trucks</option>
                                {filterOptions.vehicles.map((option) => (
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
                        <label className="app-index-filters__chip">
                            <input
                                type="checkbox"
                                name="pending_memo"
                                value="1"
                                className="size-3.5 rounded border-input"
                                defaultChecked={filters.pending_memo}
                            />
                            Pending Memo / POD
                        </label>
                        <label className="app-index-filters__chip">
                            <input
                                type="checkbox"
                                name="payable_only"
                                value="1"
                                className="size-3.5 rounded border-input"
                                defaultChecked={filters.payable_only}
                            />
                            Payable balance
                        </label>
                        <Button
                            type="submit"
                            className="h-8 bg-[#2FB36F] px-3 text-xs text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-8 px-3 text-xs"
                            onClick={() => router.get(urls.index)}
                        >
                            Reset
                        </Button>
                    </AppIndexFilters>

                    {filters.search ? (
                        <p className="border-b border-[#e5e7eb] bg-white px-4 py-2 text-xs text-slate-600">
                            Results for: <strong>{filters.search}</strong>
                        </p>
                    ) : null}

                    <BulkSelectionToolbar
                        count={selection.count}
                        noun="Lorry Hire"
                        onClear={selection.clear}
                        warnings={[voucherReason, memoReason].filter(
                            (reason): reason is string => reason !== null,
                        )}
                    >
                        {memoUrls ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="header-pill-btn"
                                disabled={memoReason !== null}
                                onClick={() => setMemoOpen(true)}
                            >
                                Memo / POD
                            </Button>
                        ) : null}
                        {voucherUrls ? (
                            <Button
                                type="button"
                                className="header-pill-btn bg-[#2FB36F] text-white hover:bg-[#279960]"
                                disabled={voucherReason !== null}
                                onClick={() => setPaymentOpen(true)}
                            >
                                Voucher Payment
                            </Button>
                        ) : null}
                    </BulkSelectionToolbar>

                    <div
                        className="overflow-x-auto"
                        data-testid="good-hires-register"
                    >
                        <table
                            id="freightBookingsTable"
                            className="app-index-table min-w-full"
                        >
                            <thead>
                                <tr>
                                    {canSelect ? (
                                        <th className="w-10 text-center">
                                            <input
                                                type="checkbox"
                                                className="size-4 rounded border-input"
                                                aria-label="Select all actionable Lorry Hires on this page"
                                                checked={allOnPageSelected}
                                                disabled={
                                                    selectableOnPage.length ===
                                                    0
                                                }
                                                onChange={(event) =>
                                                    selection.setMany(
                                                        selectableOnPage.map(
                                                            snapshot,
                                                        ),
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                        </th>
                                    ) : null}
                                    <th className="text-center">GR Date</th>
                                    <th>LR No</th>
                                    <th>Customer</th>
                                    <th>Transporter</th>
                                    <th>Truck No</th>
                                    <th>Route</th>
                                    <th>Weight</th>
                                    <th>Rate</th>
                                    <th>Total</th>
                                    <th className="text-end">Balance</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lorryHires.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={columnCount}>
                                            <p className="app-index-empty">
                                                No Lorry Hires yet. Create one
                                                from a Broker Bilty.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    lorryHires.data.map((row) => (
                                        <tr
                                            key={row.id}
                                            className={rowToneClass(
                                                row.row_tone,
                                            )}
                                        >
                                            {canSelect ? (
                                                <td className="text-center">
                                                    <input
                                                        type="checkbox"
                                                        className="size-4 rounded border-input"
                                                        checked={selection.isSelected(
                                                            row.id,
                                                        )}
                                                        disabled={
                                                            !row.payable &&
                                                            !row.memo_pending
                                                        }
                                                        title={
                                                            row.payable ||
                                                            row.memo_pending
                                                                ? undefined
                                                                : 'Nothing to pay and memo already assigned'
                                                        }
                                                        aria-label={`Select hire ${row.hire_number}`}
                                                        onChange={() =>
                                                            selection.toggle(
                                                                snapshot(row),
                                                            )
                                                        }
                                                    />
                                                </td>
                                            ) : null}
                                            <td className="text-center">
                                                <DisplayDate
                                                    value={
                                                        row.lr_date ??
                                                        row.hire_date ??
                                                        row.created_at
                                                    }
                                                />
                                            </td>
                                            <td className="font-medium">
                                                <div className="flex flex-col gap-1">
                                                    <span>
                                                        {row.lr_number ?? '—'}
                                                    </span>
                                                    {row.status ===
                                                    'cancelled' ? (
                                                        <span className="inline-flex items-center gap-1">
                                                            <Ban
                                                                className="size-3.5 text-destructive"
                                                                aria-hidden
                                                            />
                                                            <WorkspaceStatusBadge status="cancelled" />
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td>
                                                {row.billing_customer_name ??
                                                    '—'}
                                                {row.memo_number ? (
                                                    <span className="mt-0.5 block w-fit rounded bg-emerald-50 px-1.5 text-xs font-medium text-emerald-700">
                                                        Memo {row.memo_number}
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td>
                                                {row.transporter_name ?? '—'}
                                            </td>
                                            <td>
                                                {row.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td className="text-slate-500">
                                                {row.from_station_name ?? '—'} →{' '}
                                                {row.to_station_name ?? '—'}
                                            </td>
                                            <td>
                                                {row.weight_mt
                                                    ? formatWeightMt2(
                                                          row.weight_mt,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td>
                                                {row.rate
                                                    ? formatRate2(row.rate)
                                                    : '—'}
                                            </td>
                                            <td>
                                                {row.freight_total
                                                    ? formatRate2(
                                                          row.freight_total,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td
                                                className={`text-end tabular-nums ${balanceClass(row.outstanding_amount)}`}
                                            >
                                                {row.outstanding_amount ?? '—'}
                                            </td>
                                            <td>
                                                <div className="btn-action-row flex-nowrap whitespace-nowrap">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="Open lorry hire"
                                                        href={row.urls.show}
                                                        variant="info"
                                                    />
                                                    {row.urls.edit ? (
                                                        <AppIconAction
                                                            icon={Pencil}
                                                            label={`Edit lorry hire ${row.hire_number}`}
                                                            href={row.urls.edit}
                                                            variant="outline"
                                                        />
                                                    ) : null}
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
                            meta={lorryHires.meta}
                            query={filterQuery}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            {voucherUrls ? (
                <VoucherPaymentDialog
                    open={paymentOpen}
                    onOpenChange={setPaymentOpen}
                    hireIds={selection.ids}
                    urls={voucherUrls}
                    onRecorded={selection.remove}
                />
            ) : null}

            {memoUrls ? (
                <MemoPodDialog
                    open={memoOpen}
                    onOpenChange={setMemoOpen}
                    lorryReceiptIds={selection.rows.map(
                        (row) => row.lorry_receipt_id,
                    )}
                    urls={memoUrls}
                    onAssigned={(receiptIds) =>
                        selection.remove(
                            selection.rows
                                .filter((row) =>
                                    receiptIds.includes(row.lorry_receipt_id),
                                )
                                .map((row) => row.id),
                        )
                    }
                />
            ) : null}
        </>
    );
}
