import { Head, router } from '@inertiajs/react';
import { Eye, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

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
    selectable_for_bulk: boolean;
    urls: { show: string; voucher_create?: string | null };
};

type Props = {
    filters: { status: string };
    permissions: {
        can_view: boolean;
        can_create: boolean;
        can_create_voucher?: boolean;
        can_bulk_voucher?: boolean;
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
    urls: { index: string; bulk_voucher?: string | null };
};

function balanceClass(amount: string | null): string {
    const value = Number(amount ?? NaN);
    if (!Number.isFinite(value)) {
        return '';
    }
    return value > 0 ? 'is-balance-due' : 'is-balance-clear';
}

function selectionKey(row: HireRow): string {
    return `${row.transporter_party_id ?? ''}|${row.vehicle_id ?? ''}`;
}

export default function LorryHiresIndex({
    filters,
    permissions,
    statusOptions,
    lorryHires,
    urls,
}: Props) {
    const [selectedIds, setSelectedIds] = useState<string[]>([]);

    const selectedRows = useMemo(
        () => lorryHires.data.filter((row) => selectedIds.includes(row.id)),
        [lorryHires.data, selectedIds],
    );

    const selectionGroup = selectedRows[0]
        ? selectionKey(selectedRows[0])
        : null;

    const incompatibleSelection = selectedRows.some(
        (row) => selectionKey(row) !== selectionGroup,
    );

    const toggleRow = (row: HireRow) => {
        if (!row.selectable_for_bulk) {
            return;
        }
        setSelectedIds((current) =>
            current.includes(row.id)
                ? current.filter((id) => id !== row.id)
                : [...current, row.id],
        );
    };

    const paySelected = () => {
        if (
            !urls.bulk_voucher ||
            selectedIds.length === 0 ||
            incompatibleSelection
        ) {
            return;
        }
        const params = new URLSearchParams();
        for (const id of selectedIds) {
            params.append('hire_ids[]', id);
        }
        router.get(`${urls.bulk_voucher}?${params.toString()}`);
    };

    return (
        <>
            <Head title="Good Hires (Lorry Hire)" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Good Hires (Lorry Hire)"
                    toolbar={
                        permissions.can_bulk_voucher && urls.bulk_voucher ? (
                            <Button
                                type="button"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                disabled={
                                    selectedIds.length === 0 ||
                                    incompatibleSelection
                                }
                                onClick={paySelected}
                            >
                                Pay selected
                                {selectedIds.length > 0
                                    ? ` (${selectedIds.length})`
                                    : ''}
                            </Button>
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
                            setSelectedIds([]);
                            router.get(
                                urls.index,
                                { status: status || undefined },
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
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    {incompatibleSelection ? (
                        <p className="px-4 pb-2 text-sm text-amber-700">
                            Bulk pay requires the same transporter and vehicle.
                            Clear the selection or pick matching hires.
                        </p>
                    ) : null}

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    {permissions.can_bulk_voucher ? (
                                        <th className="w-10 text-center">
                                            <span className="sr-only">
                                                Select
                                            </span>
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
                                        <td
                                            colSpan={
                                                permissions.can_bulk_voucher
                                                    ? 12
                                                    : 11
                                            }
                                        >
                                            <p className="app-index-empty">
                                                No Lorry Hires yet. Create one
                                                from a Broker Bilty.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    lorryHires.data.map((row) => (
                                        <tr key={row.id}>
                                            {permissions.can_bulk_voucher ? (
                                                <td className="text-center">
                                                    <input
                                                        type="checkbox"
                                                        className="size-4 rounded border-input"
                                                        checked={selectedIds.includes(
                                                            row.id,
                                                        )}
                                                        disabled={
                                                            !row.selectable_for_bulk
                                                        }
                                                        aria-label={`Select hire ${row.hire_number}`}
                                                        onChange={() =>
                                                            toggleRow(row)
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
                                                <div className="flex items-center justify-center gap-1">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="Open lorry hire"
                                                        href={row.urls.show}
                                                        variant="info"
                                                    />
                                                    {row.urls.voucher_create ? (
                                                        <AppIconAction
                                                            icon={Wallet}
                                                            label="Record voucher"
                                                            href={
                                                                row.urls
                                                                    .voucher_create
                                                            }
                                                            variant="default"
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
                            query={{ status: filters.status || undefined }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
