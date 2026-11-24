import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Filter } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { workflowContextLabel } from '@/lib/workflow-context-label';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { PageShell } from '@/components/page-shell';
import { formatDisplayDate } from '@/lib/dates';
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

function statusLabel(status: string): string {
    return status.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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
                    <span className="font-medium">{row.lr_number}</span>
                ),
            },
            {
                id: 'lr_date',
                header: 'LR Date',
                priority: 0,
                cell: (row) => formatDisplayDate(row.lr_date),
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
                header: 'Service',
                priority: 2,
                cell: (row) => workflowContextLabel(row.workflow_context),
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
            <PageShell title={'Lorry Receipts'}>
                {flash?.success ? (
                    <p className="text-sm text-emerald-700">{flash.success}</p>
                ) : null}
                {flash?.info ? (
                    <p className="text-sm text-muted-foreground">
                        {flash.info}
                    </p>
                ) : null}

                <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                    <FormSection title="Filters" columns={3}>
                        <form onSubmit={applyFilters} className="contents">
                            <div className="space-y-2">
                                <Label htmlFor="lr-q">Search</Label>
                                <Input
                                    id="lr-q"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="LR number, from, to…"
                                />
                            </div>
                            <div className="space-y-2">
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
                            <div className="space-y-2">
                                <Label htmlFor="lr-context">Service</Label>
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
                            <div className="flex items-end md:col-span-3">
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    className="gap-2"
                                >
                                    <Filter className="size-4" aria-hidden />
                                    Filter
                                </Button>
                            </div>
                        </form>
                    </FormSection>
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
                    actions={(row) => (
                        <AppIconAction
                            icon={Eye}
                            label={`Open LR ${row.lr_number}`}
                            href={`/lorry-receipts/${row.id}`}
                            variant="info"
                        />
                    )}
                />

                {lorryReceipts.data.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Page {lorryReceipts.meta.current_page} of{' '}
                        {lorryReceipts.meta.last_page} ·{' '}
                        {lorryReceipts.meta.total} total
                    </p>
                ) : null}
            </PageShell>
        </>
    );
}
