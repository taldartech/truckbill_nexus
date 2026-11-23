import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type LrRow = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    status: string;
    from_text: string | null;
    to_text: string | null;
    freight_total: string;
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
    permissions: { can_create: boolean };
    statusOptions: Option[];
    contextOptions: Option[];
    createAction: CreateAction | null;
    partloadCreateUrl: string | null;
    lorryReceipts: {
        data: LrRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    urls: { index: string; create: string };
};

function formatLrDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
    if (!match) {
        return value;
    }

    return `${match[3]}-${match[2]}-${match[1]}`;
}

function statusLabel(status: string): string {
    return status.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function contextLabel(context: string): string {
    return context
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function CreateLrAction({ action }: { action: CreateAction }) {
    if (action.mode === 'blocked') {
        return (
            <p className="max-w-xs text-right text-xs text-muted-foreground">
                {action.reason}
            </p>
        );
    }

    return (
        <Button asChild variant="primary">
            <Link href={action.url} data-testid="create-lr-direct">
                {action.label}
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

    const columns = useMemo<DataTableColumn<LrRow>[]>(
        () => [
            {
                id: 'lr_number',
                header: 'LR No',
                priority: 0,
                cell: (row) => (
                    <Link
                        href={`/lorry-receipts/${row.id}`}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        {row.lr_number}
                    </Link>
                ),
            },
            {
                id: 'lr_date',
                header: 'LR Date',
                priority: 0,
                cell: (row) => formatLrDate(row.lr_date),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium capitalize">
                        {statusLabel(row.status)}
                    </span>
                ),
            },
            {
                id: 'workflow_context',
                header: 'Module',
                priority: 2,
                cell: (row) => contextLabel(row.workflow_context),
            },
            {
                id: 'route',
                header: 'Route',
                priority: 2,
                cell: (row) =>
                    [row.from_text, row.to_text].filter(Boolean).join(' → ') ||
                    '—',
            },
            {
                id: 'freight_total',
                header: 'Freight',
                priority: 1,
                cell: (row) => row.freight_total,
            },
        ],
        [],
    );

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

    return (
        <>
            <Head title="Lorry Receipts" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <MasterPageHeader title="Lorry Receipts" />

                {flash?.success ? (
                    <p className="text-sm text-emerald-700">{flash.success}</p>
                ) : null}
                {flash?.info ? (
                    <p className="text-sm text-muted-foreground">
                        {flash.info}
                    </p>
                ) : null}

                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <form
                        onSubmit={applyFilters}
                        className="flex w-full flex-col gap-3 md:flex-row md:items-end"
                    >
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="lr-q">Search</Label>
                            <Input
                                id="lr-q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="LR number, from, to…"
                            />
                        </div>
                        <div className="space-y-2 md:w-40">
                            <Label htmlFor="lr-status">Status</Label>
                            <select
                                id="lr-status"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
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
                        <div className="space-y-2 md:w-44">
                            <Label htmlFor="lr-context">Module</Label>
                            <select
                                id="lr-context"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
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
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </form>
                    <div className="flex flex-wrap items-center justify-end gap-2">
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
                            <CreateLrAction action={createAction} />
                        ) : null}
                    </div>
                </div>

                <ResponsiveDataTable
                    caption="Lorry Receipts"
                    columns={columns}
                    rows={lorryReceipts.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No Lorry Receipts found"
                    emptyDescription="Create an LR or adjust filters."
                />

                {lorryReceipts.data.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Page {lorryReceipts.meta.current_page} of{' '}
                        {lorryReceipts.meta.last_page} ·{' '}
                        {lorryReceipts.meta.total} total
                    </p>
                ) : null}
            </div>
        </>
    );
}
