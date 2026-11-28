import { Head, router } from '@inertiajs/react';
import { Eye, Pencil } from 'lucide-react';
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

type Props = {
    filters: { status: string; pending_memo: boolean };
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

export default function LorryHiresIndex({
    filters,
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
        status: filters.status || undefined,
        pending_memo: filters.pending_memo ? 1 : undefined,
    };
    const columnCount = canSelect ? 12 : 11;

    return (
        <>
            <Head title="Good Hires (Lorry Hire)" />
            <AppIndexPage className="freight-bookings-index">
                <AppIndexListCard title="Good Hires (Lorry Hire)">
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const status = (
                                form.elements.namedItem(
                                    'status',
                                ) as HTMLSelectElement
                            ).value;
                            const pendingMemo = (
                                form.elements.namedItem(
                                    'pending_memo',
                                ) as HTMLInputElement
                            ).checked;
                            router.get(
                                urls.index,
                                {
                                    status: status || undefined,
                                    pending_memo: pendingMemo ? 1 : undefined,
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
                        <label className="flex h-9 items-center gap-2 self-end text-sm">
                            <input
                                type="checkbox"
                                name="pending_memo"
                                className="size-4 rounded border-input"
                                defaultChecked={filters.pending_memo}
                            />
                            Pending Memo / POD
                        </label>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

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
                                        <tr key={row.id}>
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
                                                {row.lr_date ??
                                                    row.hire_date ??
                                                    row.created_at ??
                                                    '—'}
                                            </td>
                                            <td className="font-medium">
                                                {row.lr_number ?? '—'}
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
                                                    ? `${row.weight_mt} MT`
                                                    : '—'}
                                            </td>
                                            <td>{row.rate ?? '—'}</td>
                                            <td>{row.freight_total ?? '—'}</td>
                                            <td
                                                className={`text-end tabular-nums ${balanceClass(row.outstanding_amount)}`}
                                            >
                                                {row.outstanding_amount ?? '—'}
                                            </td>
                                            <td>
                                                <div className="btn-action-row">
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
