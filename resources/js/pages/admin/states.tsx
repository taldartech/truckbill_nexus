import { QuickCreateStateSheet } from '@/components/geography/quick-create-state-sheet';
import { Form, Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type StateRow = {
    id: string;
    name: string;
    code: string;
    gst_code: string;
    country_code: string;
    cities_count: number;
    is_active: boolean;
    toggle_url?: string;
    edit_url?: string;
};

type PaginationMeta = {
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
};

type Props = {
    note: string;
    filters: {
        q: string;
        status: string;
    };
    states: StateRow[] | { data: StateRow[]; meta: PaginationMeta };
    urls: {
        index: string;
        store: string;
    };
    pagination?: PaginationMeta;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function resolveStates(
    states: Props['states'],
    pagination?: PaginationMeta,
): { rows: StateRow[]; meta: PaginationMeta | null } {
    if (Array.isArray(states)) {
        return { rows: states, meta: pagination ?? null };
    }
    return { rows: states.data, meta: states.meta };
}

export default function AdminStates({
    note,
    filters,
    states,
    urls,
    pagination,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [createOpen, setCreateOpen] = useState(false);

    const { rows, meta } = useMemo(
        () => resolveStates(states, pagination),
        [states, pagination],
    );

    const columns = useMemo<DataTableColumn<StateRow>[]>(
        () => [
            {
                id: 'name',
                header: 'State name',
                priority: 0,
                cell: (row) => row.name,
            },
            {
                id: 'code',
                header: 'State code',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.code,
            },
            {
                id: 'gst_code',
                header: 'GST state code',
                priority: 2,
                className: 'font-mono text-xs',
                cell: (row) => row.gst_code,
            },
            {
                id: 'country',
                header: 'Country',
                priority: 3,
                className: 'font-mono text-xs',
                cell: (row) => row.country_code,
            },
            {
                id: 'cities',
                header: '# cities',
                priority: 2,
                cell: (row) => row.cities_count,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    const applyFilters = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = new FormData(form);
        router.get(
            urls.index,
            {
                q: (data.get('q') as string)?.trim() || undefined,
                status: (data.get('status') as string) || 'all',
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        router.get(urls.index, {}, { preserveState: true, replace: true });
    };

    const goToPage = (page: number) => {
        router.get(
            urls.index,
            {
                q: filters.q || undefined,
                status: filters.status || 'all',
                page,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="States" />
            <PageShell
                title="States"
                description={note}
                actions={
                    <Button
                        type="button"
                        className="gap-1.5 bg-[#2FB36F] text-white hover:bg-[#279960]"
                        onClick={() => setCreateOpen(true)}
                    >
                        <Plus className="size-4" aria-hidden />
                        Add State
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['name', 'code', 'gst_code', 'remark']}
                />

                <form onSubmit={applyFilters}>
                    <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4 shadow-xs">
                        <div className="min-w-[12rem] flex-1 space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="State name or code"
                            />
                        </div>
                        <div className="w-full min-w-[10rem] space-y-2 sm:w-auto">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status || 'all'}
                                className={selectClass}
                            >
                                <option value="all">All</option>
                                <option value="active">Active</option>
                                <option value="deactivated">Deactivated</option>
                            </select>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" variant="secondary">
                                Apply
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={resetFilters}
                            >
                                Reset
                            </Button>
                        </div>
                    </div>
                </form>

                <QuickCreateStateSheet
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                    storeUrl={urls.store}
                    onCreated={() => {
                        router.reload({ only: ['states', 'pagination'] });
                    }}
                />

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="States"
                        columns={columns}
                        rows={rows}
                        rowKey={(row) => row.id}
                        emptyTitle="No states match these filters"
                        emptyDescription="Adjust filters or add a new state."
                        actions={(row) => (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                {row.edit_url ? (
                                    <Button asChild size="sm" variant="outline">
                                        <a href={row.edit_url}>Edit</a>
                                    </Button>
                                ) : null}
                                {row.toggle_url ? (
                                    <Form
                                        action={row.toggle_url}
                                        method="post"
                                        className="flex flex-wrap items-center justify-end gap-2"
                                    >
                                        <Input
                                            name="remark"
                                            required
                                            placeholder="Toggle remark"
                                            aria-label={`Remark for ${row.name}`}
                                            className="h-8 max-w-[10rem] text-xs"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant={
                                                row.is_active
                                                    ? 'destructive'
                                                    : 'default'
                                            }
                                        >
                                            {row.is_active
                                                ? 'Deactivate'
                                                : 'Reactivate'}
                                        </Button>
                                    </Form>
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </div>
                        )}
                    />
                    {meta && meta.last_page > 1 ? (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-xs text-muted-foreground">
                            <span>
                                Page {meta.current_page} of {meta.last_page} (
                                {meta.total} total)
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={meta.current_page <= 1}
                                    onClick={() =>
                                        goToPage(meta.current_page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        meta.current_page >= meta.last_page
                                    }
                                    onClick={() =>
                                        goToPage(meta.current_page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    ) : meta ? (
                        <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                            {meta.total} state{meta.total === 1 ? '' : 's'}
                        </p>
                    ) : null}
                </div>
            </PageShell>
        </>
    );
}
