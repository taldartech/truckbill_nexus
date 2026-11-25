import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Eye,
    Pencil,
    Plus,
    Printer,
    RotateCcw,
    Truck,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
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

type PrimaryOperation = {
    label: string;
    url: string;
    kind: string;
};

type LrRow = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    status: string;
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
    edit_url?: string;
    print_url?: string;
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
    filters: { q: string; status: string; workflow_context: string };
    permissions: {
        can_create: boolean;
        can_edit?: boolean;
        can_view_freight?: boolean;
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

function statusLabel(status: string): string {
    return status.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function routeLabel(row: LrRow): string {
    const from = row.loading_station ?? row.from_text ?? '—';
    const to = row.unloading_station ?? row.to_text ?? '—';

    return `${from} → ${to}`;
}

function lrEditUrl(row: LrRow): string {
    return row.edit_url ?? `${row.show_url}/edit`;
}

function lrPrintUrl(row: LrRow): string | undefined {
    return row.print_url ?? `${row.show_url}/print`;
}

function operationIcon(kind: string) {
    if (kind === 'manage_expenses' || kind === 'init_trip') {
        return Wallet;
    }

    if (kind === 'manage_lorry_hire' || kind === 'create_lorry_hire') {
        return Truck;
    }

    return Truck;
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

export default function LorryReceiptsIndex({
    filters,
    permissions,
    statusOptions,
    contextOptions,
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
    const [workflowContext, setWorkflowContext] = useState(
        filters.workflow_context,
    );
    const mayViewFreight = permissions.can_view_freight !== false;
    const mayEdit = permissions.can_edit !== false;
    const columnCount = mayViewFreight ? 9 : 8;

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            urls.index,
            {
                q: q || undefined,
                status: status || undefined,
                workflow_context: workflowContext || undefined,
            },
            { preserveState: true },
        );
    };

    const resetFilters = () => {
        setQ('');
        setStatus('');
        setWorkflowContext('');
        router.get(urls.index, {}, { preserveState: true });
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
                        {contextOptions.length > 0 ? (
                            <div className="space-y-1">
                                <Label htmlFor="lr-context">
                                    Operational type
                                </Label>
                                <select
                                    id="lr-context"
                                    className={selectClass}
                                    value={workflowContext}
                                    onChange={(e) =>
                                        setWorkflowContext(e.target.value)
                                    }
                                >
                                    <option value="">All</option>
                                    {contextOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        ) : null}
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
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>GR Date</th>
                                    <th>Bilty No</th>
                                    <th>Customer</th>
                                    <th>Consignor</th>
                                    <th>Consignee</th>
                                    <th>Truck No</th>
                                    <th>Weight</th>
                                    {mayViewFreight ? <th>Amount</th> : null}
                                    <th className="text-center">Actions</th>
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
                                        const printUrl = lrPrintUrl(row);
                                        const op = row.primary_operation;
                                        const OpIcon = op
                                            ? operationIcon(op.kind)
                                            : Wallet;
                                        const showEdit =
                                            mayEdit && row.status === 'pending';

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
                                                        <div className="flex flex-wrap items-center gap-1 text-[11px] text-slate-500">
                                                            {row.vehicle_ownership_label ? (
                                                                <span className="rounded border border-slate-200 bg-slate-50 px-1.5 py-0 font-medium text-slate-700">
                                                                    {
                                                                        row.vehicle_ownership_label
                                                                    }
                                                                </span>
                                                            ) : null}
                                                            <span className="capitalize">
                                                                {statusLabel(
                                                                    row.status,
                                                                )}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    {row.billing_customer_name ??
                                                        '—'}
                                                </td>
                                                <td>
                                                    {row.consignor_name ?? '—'}
                                                </td>
                                                <td>
                                                    {row.consignee_name ?? '—'}
                                                </td>
                                                <td>
                                                    <div className="space-y-0.5">
                                                        <div>
                                                            {row.vehicle_registration ??
                                                                '—'}
                                                        </div>
                                                        <div className="text-[11px] text-slate-500">
                                                            {routeLabel(row)}
                                                        </div>
                                                    </div>
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
                                                    <div className="flex items-center justify-center gap-1">
                                                        <AppIconAction
                                                            icon={Eye}
                                                            label={`View Bilty ${row.lr_number}`}
                                                            href={row.show_url}
                                                            variant="info"
                                                            size="sm"
                                                        />
                                                        {showEdit ? (
                                                            <AppIconAction
                                                                icon={Pencil}
                                                                label={`Edit Bilty ${row.lr_number}`}
                                                                href={lrEditUrl(
                                                                    row,
                                                                )}
                                                                variant="outline"
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                        {op ? (
                                                            <AppIconAction
                                                                icon={OpIcon}
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
                                workflow_context:
                                    filters.workflow_context || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
