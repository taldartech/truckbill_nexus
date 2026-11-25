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
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { PageShell } from '@/components/page-shell';
import { formatDisplayDate } from '@/lib/dates';
import { formatMtLabel, kgToMt } from '@/lib/operational-weight';
import { Badge } from '@/components/ui/badge';
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
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

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
            <p className="max-w-xs text-right text-xs text-muted-foreground">
                {action.reason}
            </p>
        );
    }

    return (
        <Button asChild variant="primary" className="gap-2">
            <Link href={action.url} data-testid="create-lr-direct">
                <Plus className="size-4" aria-hidden />
                Add New Good Receipt (Bilty)
            </Link>
        </Button>
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

    const columns = useMemo<DataTableColumn<LrRow>[]>(() => {
        // Column order mirrors legacy Broker LR index: Date → LR → Customer →
        // Consignor → Consignee → Truck → Weight → Amount → Action.
        const base: DataTableColumn<LrRow>[] = [
            {
                id: 'lr_date',
                header: 'GR Date',
                priority: 0,
                width: '6.5rem',
                minWidth: '6.5rem',
                className: 'whitespace-nowrap text-[12.5px]',
                cell: (row) => formatDisplayDate(row.lr_date),
            },
            {
                id: 'lr_number',
                header: 'LR / Bilty',
                priority: 0,
                width: '8.5rem',
                minWidth: '8.5rem',
                className: 'whitespace-nowrap text-[12.5px] font-medium',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <div>{row.lr_number}</div>
                        <div className="flex flex-wrap items-center gap-1">
                            {row.vehicle_ownership_label ? (
                                <Badge
                                    variant={
                                        row.vehicle_ownership_label === 'Own'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    className="px-1.5 py-0 text-[10px]"
                                >
                                    {row.vehicle_ownership_label}
                                </Badge>
                            ) : null}
                            <span className="text-[10px] text-muted-foreground capitalize">
                                {statusLabel(row.status)}
                            </span>
                        </div>
                    </div>
                ),
            },
            {
                id: 'billing_customer',
                header: 'Customer',
                priority: 1,
                width: '11rem',
                minWidth: '10rem',
                wrap: true,
                className: 'text-[12.5px]',
                cell: (row) => row.billing_customer_name ?? '—',
            },
            {
                id: 'consignor',
                header: 'Consignor',
                priority: 2,
                width: '10rem',
                minWidth: '9rem',
                wrap: true,
                className: 'text-[12.5px]',
                cell: (row) => row.consignor_name ?? '—',
            },
            {
                id: 'consignee',
                header: 'Consignee',
                priority: 2,
                width: '10rem',
                minWidth: '9rem',
                wrap: true,
                className: 'text-[12.5px]',
                cell: (row) => row.consignee_name ?? '—',
            },
            {
                id: 'vehicle_registration',
                header: 'Truck',
                priority: 1,
                width: '8rem',
                minWidth: '7.5rem',
                className: 'whitespace-nowrap text-[12.5px]',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <div>{row.vehicle_registration ?? '—'}</div>
                        <div className="text-[10px] text-muted-foreground">
                            {routeLabel(row)}
                        </div>
                    </div>
                ),
            },
            {
                id: 'charged_weight_total',
                header: 'Weight',
                priority: 2,
                width: '5.5rem',
                minWidth: '5rem',
                align: 'right',
                className: 'whitespace-nowrap text-[12.5px] tabular-nums',
                cell: (row) => formatMtLabel(kgToMt(row.charged_weight_total)),
            },
        ];

        if (mayViewFreight) {
            base.push({
                id: 'freight_total',
                header: 'Amount',
                priority: 1,
                width: '6.5rem',
                minWidth: '6rem',
                align: 'right',
                className: 'whitespace-nowrap text-[12.5px] tabular-nums',
                cell: (row) => row.freight_total,
            });
        }

        return base;
    }, [mayViewFreight]);

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

    const headerActions = (
        <>
            {permissions.can_create && partloadCreateUrl ? (
                <Button asChild variant="outline">
                    <Link
                        href={partloadCreateUrl}
                        data-testid="create-partload-bilty"
                    >
                        Create Partload Bilty
                    </Link>
                </Button>
            ) : null}
            {permissions.can_create && createAction ? (
                <CreateBiltyAction action={createAction} />
            ) : null}
        </>
    );

    return (
        <>
            <Head title="Bilty Register" />
            <PageShell
                title="Bilty Register"
                chrome="compact"
                actions={headerActions}
            >
                {flash?.success ? (
                    <p className="text-sm text-emerald-700">{flash.success}</p>
                ) : null}
                {flash?.info ? (
                    <p className="text-sm text-muted-foreground">
                        {flash.info}
                    </p>
                ) : null}

                <form
                    onSubmit={applyFilters}
                    className="flex flex-col gap-3 rounded-xl border border-border bg-card p-3 shadow-xs md:flex-row md:flex-wrap md:items-end"
                >
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <Label htmlFor="lr-q" className="text-xs">
                            Search
                        </Label>
                        <Input
                            id="lr-q"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Bilty No, route, customer…"
                            className="h-9"
                        />
                    </div>
                    <div className="w-full min-w-[9rem] space-y-1 md:w-40">
                        <Label htmlFor="lr-status" className="text-xs">
                            Status
                        </Label>
                        <select
                            id="lr-status"
                            className={selectClass}
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="">All</option>
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    {contextOptions.length > 0 ? (
                        <div className="w-full min-w-[9rem] space-y-1 md:w-44">
                            <Label htmlFor="lr-context" className="text-xs">
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
                        <Button type="submit" variant="secondary" size="sm">
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
                </form>

                <ResponsiveDataTable
                    caption="Bilty Register"
                    columns={columns}
                    rows={lorryReceipts.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No bilties found"
                    emptyDescription="Create a bilty or adjust filters."
                    actions={(row) => {
                        const printUrl = lrPrintUrl(row);
                        const op = row.primary_operation;
                        const OpIcon = op ? operationIcon(op.kind) : Wallet;
                        const showEdit = mayEdit && row.status === 'pending';

                        return (
                            <div className="flex items-center justify-end gap-1">
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
                                        href={lrEditUrl(row)}
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
                        );
                    }}
                />

                <TablePagination
                    baseUrl={urls.index}
                    meta={lorryReceipts.meta}
                    query={{
                        q: filters.q || undefined,
                        status: filters.status || undefined,
                        workflow_context: filters.workflow_context || undefined,
                    }}
                />
            </PageShell>
        </>
    );
}
