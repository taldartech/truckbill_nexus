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

type Props = {
    filters: { q: string; status: string; workflow_context: string };
    permissions: { can_create: boolean };
    statusOptions: Option[];
    contextOptions: Option[];
    lorryReceipts: {
        data: LrRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    urls: { index: string; create: string };
};

export default function LorryReceiptsIndex({
    filters,
    permissions,
    statusOptions,
    contextOptions,
    lorryReceipts,
    urls,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [workflowContext, setWorkflowContext] = useState(
        filters.workflow_context,
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
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<LrRow>[]>(
        () => [
            {
                id: 'lr_number',
                header: 'LR No',
                priority: 0,
                cell: (row) => (
                    <Link
                        href={`/lorry-receipts/${row.id}`}
                        className="font-medium hover:underline"
                    >
                        {row.lr_number}
                    </Link>
                ),
            },
            {
                id: 'lr_date',
                header: 'Date',
                priority: 1,
                cell: (row) => row.lr_date || '—',
            },
            {
                id: 'context',
                header: 'Context',
                priority: 2,
                cell: (row) => row.workflow_context.replaceAll('_', ' '),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => row.status,
            },
            {
                id: 'route',
                header: 'Route',
                priority: 2,
                className: 'text-muted-foreground',
                cell: (row) =>
                    [row.from_text, row.to_text].filter(Boolean).join(' → ') ||
                    '—',
            },
            {
                id: 'parties',
                header: 'Parties',
                priority: 3,
                className: 'text-muted-foreground',
                cell: (row) =>
                    [row.consignor_name, row.consignee_name]
                        .filter(Boolean)
                        .join(' / ') || '—',
            },
            {
                id: 'freight',
                header: 'Freight',
                priority: 2,
                cell: (row) => row.freight_total,
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Lorry Receipts" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <MasterPageHeader title="Lorry Receipts" />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <form
                        onSubmit={applyFilters}
                        className="flex w-full flex-col gap-3 lg:max-w-4xl lg:flex-row"
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
                            <Label htmlFor="lr-context">Context</Label>
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
                    {permissions.can_create ? (
                        <Button asChild>
                            <Link href={urls.create}>Create LR</Link>
                        </Button>
                    ) : null}
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
