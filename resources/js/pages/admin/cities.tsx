import { Form, Head, router, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type StateOption = {
    id: string;
    name: string;
};

type CityRow = {
    id: string;
    name: string;
    state_name: string;
    state_id: string;
    is_active: boolean;
    toggle_url: string;
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
        state_id: string;
        status: string;
    };
    states: StateOption[];
    cities: CityRow[] | { data: CityRow[]; meta: PaginationMeta };
    urls: {
        index: string;
        store: string;
    };
    pagination?: PaginationMeta;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function resolveCities(
    cities: Props['cities'],
    pagination?: PaginationMeta,
): { rows: CityRow[]; meta: PaginationMeta | null } {
    if (Array.isArray(cities)) {
        return { rows: cities, meta: pagination ?? null };
    }
    return { rows: cities.data, meta: cities.meta };
}

export default function AdminCities({
    note,
    filters,
    states,
    cities,
    urls,
    pagination,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const { rows, meta } = useMemo(
        () => resolveCities(cities, pagination),
        [cities, pagination],
    );

    const columns = useMemo<DataTableColumn<CityRow>[]>(
        () => [
            {
                id: 'name',
                header: 'City name',
                priority: 0,
                cell: (row) => row.name,
            },
            {
                id: 'state',
                header: 'State',
                priority: 2,
                cell: (row) => row.state_name,
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
        const stateId = (data.get('state_id') as string) || '';
        router.get(
            urls.index,
            {
                q: (data.get('q') as string)?.trim() || undefined,
                state_id: stateId || undefined,
                status: (data.get('status') as string) || 'all',
            },
            { preserveState: true, replace: true },
        );
    };

    const goToPage = (page: number) => {
        router.get(
            urls.index,
            {
                q: filters.q || undefined,
                state_id: filters.state_id || undefined,
                status: filters.status || 'all',
                page,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="City master" />
            <PageShell title="City master" description={note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['name', 'state_id', 'remark']}
                />

                <form onSubmit={applyFilters}>
                    <FormSection title="Search & filters" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="city_q">Search</Label>
                            <Input
                                id="city_q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="City name"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="filter_state_id">State</Label>
                            <select
                                id="filter_state_id"
                                name="state_id"
                                defaultValue={filters.state_id || ''}
                                className={selectClass}
                            >
                                <option value="">All states</option>
                                {states.map((state) => (
                                    <option key={state.id} value={state.id}>
                                        {state.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="city_status">Status</Label>
                            <select
                                id="city_status"
                                name="status"
                                defaultValue={filters.status || 'all'}
                                className={selectClass}
                            >
                                <option value="all">All</option>
                                <option value="active">Active</option>
                                <option value="deactivated">Deactivated</option>
                            </select>
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" variant="secondary">
                                Apply filters
                            </Button>
                        </div>
                    </FormSection>
                </form>

                <Form action={urls.store} method="post">
                    <FormSection title="Create city" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="state_id" required>
                                State
                            </Label>
                            <select
                                id="state_id"
                                name="state_id"
                                required
                                defaultValue={filters.state_id || ''}
                                className={selectClass}
                            >
                                <option value="" disabled>
                                    Select state
                                </option>
                                {states.map((state) => (
                                    <option key={state.id} value={state.id}>
                                        {state.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="city_name" required>
                                City name
                            </Label>
                            <Input
                                id="city_name"
                                name="name"
                                required
                                maxLength={120}
                                placeholder="City name"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Button
                                type="submit"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Create city
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Cities"
                        columns={columns}
                        rows={rows}
                        rowKey={(row) => row.id}
                        emptyTitle="No cities match these filters"
                        emptyDescription="Adjust filters or create a city above."
                        actions={(city) => (
                            <Form
                                action={city.toggle_url}
                                method="post"
                                className="flex flex-wrap items-center justify-end gap-2"
                            >
                                <Input
                                    name="remark"
                                    required
                                    placeholder="Toggle remark"
                                    aria-label={`Remark for ${city.name}`}
                                    className="h-8 max-w-[10rem] text-xs"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant={
                                        city.is_active
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {city.is_active
                                        ? 'Deactivate'
                                        : 'Reactivate'}
                                </Button>
                            </Form>
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
                            {meta.total} cit{meta.total === 1 ? 'y' : 'ies'}
                        </p>
                    ) : null}
                </div>
            </PageShell>
        </>
    );
}
