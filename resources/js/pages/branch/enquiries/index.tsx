import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';

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
            includes_unassigned: boolean;
        };
    };
    urls: { index: string; create: string; masters: string };
};

export default function BranchEnquiriesIndex({
    company,
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
                cell: (row) => `${row.status} · ${row.source}`,
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
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Branch · {company.name} · {branch.name}
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Enquiries
                        </h1>
                    </div>
                    {can_create ? (
                        <Button asChild>
                            <Link href={urls.create}>Create enquiry</Link>
                        </Button>
                    ) : null}
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                {can_include_unassigned ? (
                    <Form
                        action={urls.index}
                        method="get"
                        className="flex items-center gap-3 rounded-xl border border-border p-4 text-sm"
                    >
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                name="unassigned"
                                value="1"
                                defaultChecked={filters.unassigned}
                            />
                            Include company unassigned queue
                        </label>
                        <Button type="submit" size="sm">
                            Apply
                        </Button>
                    </Form>
                ) : null}

                <ResponsiveDataTable
                    caption="Enquiries"
                    columns={columns}
                    rows={enquiries.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No enquiries found"
                    emptyDescription="Create an enquiry or adjust filters."
                    actions={(enquiry) => (
                        <Link
                            href={`${urls.index}/${enquiry.id}`}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            Open
                        </Link>
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Page {enquiries.meta.current_page} of{' '}
                    {enquiries.meta.last_page} ({enquiries.meta.total} total)
                    {enquiries.meta.includes_unassigned
                        ? ' · includes unassigned'
                        : ''}
                </p>
            </div>
        </>
    );
}
