import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Eye,
    Pencil,
    Plus,
    Printer,
    RotateCcw,
    Trash2,
    Truck,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { formatDisplayDate } from '@/lib/dates';
import { formatMtLabel, kgToMt } from '@/lib/operational-weight';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type PrimaryOperation = {
    label: string;
    url: string;
    kind: string;
    expenses_url?: string;
    change_status_url?: string;
    next_status?: string | null;
};

type LrRow = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    status: string;
    status_label?: string;
    from_text: string | null;
    to_text: string | null;
    loading_station: string | null;
    unloading_station: string | null;
    vehicle_registration: string | null;
    vehicle_ownership_label: string | null;
    billing_customer_name: string | null;
    charged_weight_total: string;
    freight_total: string;
    primary_operation?: PrimaryOperation | null;
    show_url: string;
    edit_url?: string | null;
    print_url?: string;
    destroy_url?: string | null;
    consignor_name: string | null;
    consignee_name: string | null;
};

type Option = { value: string; label: string };

type CreateAction =
    | {
          mode: 'direct';
          label: string;
          url: string;
      }
    | {
          mode: 'blocked';
          label: string;
          reason: string;
      };

type Props = {
    filters: {
        q: string;
        status: string;
        workflow_context: string;
        ownership?: string;
        awaiting_trip?: string;
        has_trip?: string;
    };
    permissions: {
        can_create: boolean;
        can_edit?: boolean;
        can_view_freight?: boolean;
        can_delete?: boolean;
    };
    statusOptions: Option[];
    contextOptions: Option[];
    createAction: CreateAction | null;
    partloadCreateUrl: string | null;
    lorryReceipts: {
        data: LrRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page?: number;
        };
    };
    urls: { index: string; create: string };
};

const selectClass =
    'h-9 min-w-[9rem] rounded-md border border-input bg-background px-3 text-sm';

function routeLabel(row: LrRow): string {
    const from = row.loading_station ?? row.from_text ?? '—';
    const to = row.unloading_station ?? row.to_text ?? '—';

    return `${from} → ${to}`;
}

function CreateBiltyAction({ action }: { action: CreateAction }) {
    if (action.mode === 'blocked') {
        return (
            <p className="max-w-xs text-right text-xs text-white/90">
                {action.reason}
            </p>
        );
    }

    return (
        <Link
            href={action.url}
            data-testid="create-lr-direct"
            className="app-index-header-btn gap-1.5"
        >
            <Plus className="size-3.5" aria-hidden />
            Add New Good Receipt (Bilty)
        </Link>
    );
}

function nextStatusLabel(status: string | null | undefined): string {
    if (status === 'in_progress') {
        return 'In Progress';
    }
    if (status === 'delivered') {
        return 'Delivered';
    }

    return status ?? '';
}

export default function LorryReceiptsIndex({
    filters,
    permissions,
    statusOptions,
    createAction,
    partloadCreateUrl,
    lorryReceipts,
    urls,
}: Props) {
    const { flash } = usePage().props as {
        flash?: { success?: string; info?: string };
    };
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [ownership, setOwnership] = useState(filters.ownership ?? '');
    const [deleteTarget, setDeleteTarget] = useState<LrRow | null>(null);
    const [deleteRemark, setDeleteRemark] = useState('');
    const [statusTarget, setStatusTarget] = useState<LrRow | null>(null);
    const [statusRemark, setStatusRemark] = useState('');
    const [startingOdometer, setStartingOdometer] = useState('');
    const [endingOdometer, setEndingOdometer] = useState('');
    const mayViewFreight = permissions.can_view_freight !== false;
    const mayEdit = permissions.can_edit !== false;
    const columnCount = mayViewFreight ? 11 : 10;

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            urls.index,
            {
                q: q || undefined,
                status: status || undefined,
                ownership: ownership || undefined,
            },
            { preserveState: true },
        );
    };

    const resetFilters = () => {
        setQ('');
        setStatus('');
        setOwnership('');
        router.get(urls.index, {}, { preserveState: true });
    };

    const submitDelete = () => {
        if (!deleteTarget?.destroy_url || deleteRemark.trim() === '') {
            return;
        }

        router.delete(deleteTarget.destroy_url, {
            data: { remark: deleteRemark.trim() },
            preserveScroll: true,
            onSuccess: () => {
                setDeleteTarget(null);
                setDeleteRemark('');
            },
        });
    };

    const submitStatusChange = () => {
        const op = statusTarget?.primary_operation;
        if (!op?.change_status_url || !op.next_status) {
            return;
        }

        const payload: Record<string, string> = {
            status: op.next_status,
            remark: statusRemark.trim(),
        };

        if (
            op.next_status === 'in_progress' &&
            startingOdometer.trim() !== ''
        ) {
            payload.starting_odometer = startingOdometer.trim();
        }

        if (op.next_status === 'delivered' && endingOdometer.trim() !== '') {
            payload.ending_odometer = endingOdometer.trim();
        }

        router.post(op.change_status_url, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setStatusTarget(null);
                setStatusRemark('');
                setStartingOdometer('');
                setEndingOdometer('');
            },
        });
    };

    const toolbar = (
        <div className="flex flex-wrap items-center gap-2">
            {permissions.can_create && partloadCreateUrl ? (
                <Link
                    href={partloadCreateUrl}
                    data-testid="create-partload-bilty"
                    className="app-index-header-btn"
                >
                    Create Partload Bilty
                </Link>
            ) : null}
            {permissions.can_create && createAction ? (
                <CreateBiltyAction action={createAction} />
            ) : null}
        </div>
    );

    const statusDialogTitle = useMemo(() => {
        if (!statusTarget?.primary_operation?.next_status) {
            return 'Change Status';
        }

        return `Change Status → ${nextStatusLabel(statusTarget.primary_operation.next_status)}`;
    }, [statusTarget]);

    return (
        <>
            <Head title="Bilty Register" />
            <AppIndexPage>
                <AppIndexListCard title="Bilty Register" toolbar={toolbar}>
                    {flash?.success ? (
                        <p className="border-b border-[#e5e7eb] bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                            {flash.success}
                        </p>
                    ) : null}
                    {flash?.info ? (
                        <p className="border-b border-[#e5e7eb] bg-slate-50 px-4 py-2 text-sm text-slate-600">
                            {flash.info}
                        </p>
                    ) : null}

                    <AppIndexFilters onSubmit={applyFilters}>
                        <div className="min-w-[12rem] flex-1 space-y-1">
                            <Label htmlFor="lr-q">Search</Label>
                            <Input
                                id="lr-q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Bilty No, route, customer…"
                                className="h-9"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="lr-status">Status</Label>
                            <select
                                id="lr-status"
                                className={selectClass}
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
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
                            <Label htmlFor="lr-ownership">Ownership</Label>
                            <select
                                id="lr-ownership"
                                className={selectClass}
                                value={ownership}
                                onChange={(e) => setOwnership(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="own">Own Vehicle</option>
                                <option value="market">Market Vehicle</option>
                            </select>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="submit"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Apply
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="gap-1.5"
                                onClick={resetFilters}
                            >
                                <RotateCcw className="size-3.5" aria-hidden />
                                Reset
                            </Button>
                        </div>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table w-full min-w-[72rem] table-fixed">
                            <thead>
                                <tr>
                                    <th className="w-[7rem]">GR Date</th>
                                    <th className="w-[8rem]">Bilty No</th>
                                    <th className="w-[9rem]">Customer</th>
                                    <th className="w-[8rem]">Consignor</th>
                                    <th className="w-[8rem]">Consignee</th>
                                    <th className="w-[7rem]">Truck No</th>
                                    <th className="w-[10rem]">Route</th>
                                    <th className="w-[5.5rem]">Weight</th>
                                    {mayViewFreight ? (
                                        <th className="w-[7rem]">Freight</th>
                                    ) : null}
                                    <th className="w-[6.5rem]">Status</th>
                                    <th className="sticky right-0 z-10 w-[11rem] bg-[#f8fafc] text-center shadow-[-6px_0_8px_-8px_rgba(15,23,42,0.25)]">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {lorryReceipts.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={columnCount}>
                                            <p className="app-index-empty">
                                                No bilties found. Create a bilty
                                                or adjust filters.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    lorryReceipts.data.map((row) => {
                                        const printUrl =
                                            row.print_url ??
                                            `${row.show_url}/print`;
                                        const op = row.primary_operation;
                                        const showEdit =
                                            mayEdit &&
                                            row.status === 'pending' &&
                                            Boolean(row.edit_url);
                                        const statusText =
                                            row.status_label ??
                                            row.status
                                                .replaceAll('_', ' ')
                                                .replace(/\b\w/g, (c) =>
                                                    c.toUpperCase(),
                                                );

                                        return (
                                            <tr key={row.id}>
                                                <td className="whitespace-nowrap">
                                                    {formatDisplayDate(
                                                        row.lr_date,
                                                    )}
                                                </td>
                                                <td>
                                                    <div className="space-y-0.5">
                                                        <div className="font-medium">
                                                            {row.lr_number}
                                                        </div>
                                                        {row.vehicle_ownership_label ? (
                                                            <span className="rounded border border-slate-200 bg-slate-50 px-1.5 py-0 text-[11px] font-medium text-slate-700">
                                                                {
                                                                    row.vehicle_ownership_label
                                                                }
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="truncate">
                                                    {row.billing_customer_name ??
                                                        '—'}
                                                </td>
                                                <td className="truncate">
                                                    {row.consignor_name ?? '—'}
                                                </td>
                                                <td className="truncate">
                                                    {row.consignee_name ?? '—'}
                                                </td>
                                                <td className="whitespace-nowrap">
                                                    {row.vehicle_registration ??
                                                        '—'}
                                                </td>
                                                <td
                                                    className="truncate text-[12px] text-slate-600"
                                                    title={routeLabel(row)}
                                                >
                                                    {routeLabel(row)}
                                                </td>
                                                <td className="tabular-nums">
                                                    {formatMtLabel(
                                                        kgToMt(
                                                            row.charged_weight_total,
                                                        ),
                                                    )}
                                                </td>
                                                {mayViewFreight ? (
                                                    <td className="tabular-nums">
                                                        {row.freight_total}
                                                    </td>
                                                ) : null}
                                                <td>
                                                    <span className="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-slate-700">
                                                        {statusText}
                                                    </span>
                                                </td>
                                                <td className="sticky right-0 z-10 bg-white shadow-[-6px_0_8px_-8px_rgba(15,23,42,0.25)]">
                                                    <div className="flex flex-wrap items-center justify-center gap-1">
                                                        <AppIconAction
                                                            icon={Eye}
                                                            label={`View Bilty ${row.lr_number}`}
                                                            href={row.show_url}
                                                            variant="info"
                                                            size="sm"
                                                        />
                                                        {showEdit &&
                                                        row.edit_url ? (
                                                            <AppIconAction
                                                                icon={Pencil}
                                                                label={`Edit Bilty ${row.lr_number}`}
                                                                href={
                                                                    row.edit_url
                                                                }
                                                                variant="outline"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                        {op?.kind ===
                                                            'manage_expenses' &&
                                                        op.change_status_url &&
                                                        op.next_status ? (
                                                            <AppIconAction
                                                                icon={
                                                                    ArrowRightLeft
                                                                }
                                                                label={`Change Status to ${nextStatusLabel(op.next_status)}`}
                                                                onClick={() => {
                                                                    setStatusTarget(
                                                                        row,
                                                                    );
                                                                    setStatusRemark(
                                                                        '',
                                                                    );
                                                                    setStartingOdometer(
                                                                        '',
                                                                    );
                                                                    setEndingOdometer(
                                                                        '',
                                                                    );
                                                                }}
                                                                variant="primary"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                        {op?.kind ===
                                                            'manage_expenses' &&
                                                        op.expenses_url ? (
                                                            <AppIconAction
                                                                icon={Wallet}
                                                                label="Manage Expenses"
                                                                href={
                                                                    op.expenses_url
                                                                }
                                                                variant="outline"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                        {op?.kind ===
                                                            'create_lorry_hire' ||
                                                        op?.kind ===
                                                            'view_lorry_hire' ? (
                                                            <AppIconAction
                                                                icon={Truck}
                                                                label={op.label}
                                                                href={op.url}
                                                                variant="primary"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                        {printUrl ? (
                                                            <AppIconAction
                                                                icon={Printer}
                                                                label={`Print Bilty ${row.lr_number}`}
                                                                href={printUrl}
                                                                variant="outline"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                        {row.destroy_url ? (
                                                            <AppIconAction
                                                                icon={Trash2}
                                                                label={`Delete Bilty ${row.lr_number}`}
                                                                onClick={() => {
                                                                    setDeleteTarget(
                                                                        row,
                                                                    );
                                                                    setDeleteRemark(
                                                                        '',
                                                                    );
                                                                }}
                                                                variant="destructive"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={urls.index}
                            meta={lorryReceipts.meta}
                            query={{
                                q: filters.q || undefined,
                                status: filters.status || undefined,
                                ownership: filters.ownership || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            <Dialog
                open={deleteTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTarget(null);
                        setDeleteRemark('');
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Pending Bilty</DialogTitle>
                        <DialogDescription>
                            Soft-delete Bilty {deleteTarget?.lr_number}. This
                            cannot be undone from the register. Enter a
                            mandatory remark.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="delete-remark" required>
                            Deletion remark
                        </Label>
                        <Input
                            id="delete-remark"
                            required
                            value={deleteRemark}
                            onChange={(e) => setDeleteRemark(e.target.value)}
                            placeholder="Reason for deletion"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setDeleteTarget(null);
                                setDeleteRemark('');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={deleteRemark.trim() === ''}
                            onClick={submitDelete}
                        >
                            Delete Bilty
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={statusTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setStatusTarget(null);
                        setStatusRemark('');
                        setStartingOdometer('');
                        setEndingOdometer('');
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{statusDialogTitle}</DialogTitle>
                        <DialogDescription>
                            Current status:{' '}
                            {statusTarget?.status_label ??
                                statusTarget?.status ??
                                '—'}
                            . Associated Trip state will update with this
                            change.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        {statusTarget?.primary_operation?.next_status ===
                        'in_progress' ? (
                            <div className="space-y-1">
                                <Label htmlFor="starting-odo">
                                    Starting odometer
                                </Label>
                                <Input
                                    id="starting-odo"
                                    value={startingOdometer}
                                    onChange={(e) =>
                                        setStartingOdometer(e.target.value)
                                    }
                                    inputMode="decimal"
                                />
                            </div>
                        ) : null}
                        {statusTarget?.primary_operation?.next_status ===
                        'delivered' ? (
                            <div className="space-y-1">
                                <Label htmlFor="ending-odo">
                                    Ending odometer
                                </Label>
                                <Input
                                    id="ending-odo"
                                    value={endingOdometer}
                                    onChange={(e) =>
                                        setEndingOdometer(e.target.value)
                                    }
                                    inputMode="decimal"
                                />
                            </div>
                        ) : null}
                        <div className="space-y-1">
                            <Label htmlFor="status-remark">Remark</Label>
                            <Input
                                id="status-remark"
                                value={statusRemark}
                                onChange={(e) =>
                                    setStatusRemark(e.target.value)
                                }
                                placeholder="Optional remark"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setStatusTarget(null);
                                setStatusRemark('');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="button" onClick={submitStatusChange}>
                            Confirm Status Change
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
