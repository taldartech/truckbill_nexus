import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Filter, Plus } from 'lucide-react';
import { useMemo } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type EnquiryRow = {
    id: string;
    enquiry_number: string | null;
    status: string;
    source: string;
    party_label?: string | null;
    route_label?: string | null;
    pickup_date?: string | null;
    goods_type_name?: string | null;
    weight_mt?: string | null;
    vehicle_type_name?: string | null;
    vehicle_quantity: number;
};

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    filters: { unassigned: boolean };
    can_create: boolean;
    can_include_unassigned: boolean;
    enquiries: {
        data: EnquiryRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page?: number;
            includes_unassigned: boolean;
        };
    };
    urls: { index: string; create: string; masters: string };
};

export default function BranchEnquiriesIndex({
    branch,
    filters,
    can_create,
    can_include_unassigned,
    enquiries,
    urls,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<EnquiryRow>[]>(
        () => [
            {
                id: 'number',
                header: 'Number',
                priority: 0,
                className: 'font-mono text-xs',
                cell: (row) => row.enquiry_number ?? row.id.slice(0, 8),
            },
            {
                id: 'party',
                header: 'Party',
                priority: 1,
                cell: (row) => row.party_label ?? '—',
            },
            {
                id: 'route',
                header: 'Route',
                priority: 2,
                cell: (row) => row.route_label ?? '—',
            },
            {
                id: 'pickup',
                header: 'Pickup',
                priority: 2,
                className: 'tabular-nums text-xs',
                cell: (row) => row.pickup_date ?? '—',
            },
            {
                id: 'goods',
                header: 'Goods / Wt',
                priority: 2,
                cell: (row) =>
                    [
                        row.goods_type_name,
                        row.weight_mt ? `${row.weight_mt} MT` : null,
                    ]
                        .filter(Boolean)
                        .join(' · ') || '—',
            },
            {
                id: 'vehicle',
                header: 'Vehicle',
                priority: 3,
                cell: (row) =>
                    row.vehicle_type_name
                        ? `${row.vehicle_type_name} × ${row.vehicle_quantity}`
                        : String(row.vehicle_quantity),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={`${row.status} · ${row.source}`}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Enquiries · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title="Enquiries"
                    toolbar={
                        can_create ? (
                            <Link
                                href={urls.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create enquiry
                            </Link>
                        ) : null
                    }
                >
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}

                    {can_include_unassigned ? (
                        <AppIndexFilters
                            onSubmit={(event) => {
                                event.preventDefault();
                                const form = event.currentTarget;
                                const unassigned = (
                                    form.elements.namedItem(
                                        'unassigned',
                                    ) as HTMLInputElement
                                ).checked;
                                router.get(
                                    urls.index,
                                    {
                                        unassigned: unassigned
                                            ? '1'
                                            : undefined,
                                    },
                                    { preserveState: true, replace: true },
                                );
                            }}
                        >
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="unassigned"
                                    value="1"
                                    defaultChecked={filters.unassigned}
                                />
                                Include company unassigned queue
                            </label>
                            <Button
                                type="submit"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                <Filter className="mr-1.5 size-3.5" />
                                Apply
                            </Button>
                        </AppIndexFilters>
                    ) : null}

                    <div className="p-4">
                        <ResponsiveDataTable
                            caption="Enquiries"
                            columns={columns}
                            rows={enquiries.data}
                            rowKey={(row) => row.id}
                            emptyTitle="No enquiries found"
                            emptyDescription="Create an enquiry or adjust filters."
                            actions={(enquiry) => (
                                <AppIconAction
                                    icon={Eye}
                                    label="Open enquiry"
                                    href={`${urls.index}/${enquiry.id}`}
                                    variant="info"
                                />
                            )}
                        />

                        <TablePagination
                            baseUrl={urls.index}
                            meta={enquiries.meta}
                            query={{
                                unassigned: filters.unassigned
                                    ? '1'
                                    : undefined,
                            }}
                        />
                        {enquiries.meta.includes_unassigned ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Includes unassigned queue
                            </p>
                        ) : null}
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
