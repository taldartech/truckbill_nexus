import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Eye, Filter, Plus } from 'lucide-react';
import { useMemo } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type EnquiryRow = {
    id: string;
    enquiry_number: string | null;
    status: string;
    source: string;
    pickup_location: string;
    delivery_location: string;
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
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={`${row.status} · ${row.source}`}
                    />
                ),
            },
            {
                id: 'route',
                header: 'Route',
                priority: 2,
                cell: (row) =>
                    `${row.pickup_location} → ${row.delivery_location}`,
            },
            {
                id: 'vehicles',
                header: 'Vehicles',
                priority: 2,
                cell: (row) => row.vehicle_quantity,
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Enquiries · ${branch.name}`} />
            <PageShell
                title="Enquiries"
                description={`Broker pipeline for ${branch.name}.`}
                actions={
                    can_create ? (
                        <Button asChild variant="primary" className="gap-2">
                            <Link href={urls.create}>
                                <Plus className="size-4" />
                                Create enquiry
                            </Link>
                        </Button>
                    ) : null
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                {can_include_unassigned ? (
                    <FormSection title="Queue filters" columns={2}>
                        <Form
                            action={urls.index}
                            method="get"
                            className="contents"
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
                            <FilterApplyButton />
                        </Form>
                    </FormSection>
                ) : null}

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
                        unassigned: filters.unassigned ? '1' : undefined,
                    }}
                />
                {enquiries.meta.includes_unassigned ? (
                    <p className="text-xs text-muted-foreground">
                        Includes unassigned queue
                    </p>
                ) : null}
            </PageShell>
        </>
    );
}

function FilterApplyButton() {
    return (
        <Button type="submit" variant="secondary" className="gap-2">
            <Filter className="size-4" />
            Apply
        </Button>
    );
}
