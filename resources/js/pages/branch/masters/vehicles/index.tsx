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

type Row = {
    id: string;
    registration_number: string;
    vehicle_type: string | null;
    make: string | null;
    model: string | null;
    is_active: boolean;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_create: boolean };
    filters: { q: string; active: string };
    vehicles: {
        data: Row[];
        meta: { current_page: number; last_page: number; total: number };
    };
    dataVisibility?: { mode: string; label: string; message: string };
    links: { hub: string; dashboard: string; index: string; create: string };
};

export default function VehiclesIndex({
    company,
    branch,
    permissions,
    filters,
    vehicles,
    dataVisibility,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const showUrl = (id: string) => `/branch/vehicles/${id}`;

    const columns = useMemo<DataTableColumn<Row>[]>(
        () => [
            {
                id: 'registration',
                header: 'Registration',
                priority: 0,
                cell: (row) => (
                    <Link
                        href={showUrl(row.id)}
                        className="font-medium hover:underline"
                    >
                        {row.registration_number}
                    </Link>
                ),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (row.is_active ? 'Active' : 'Inactive'),
            },
            {
                id: 'type',
                header: 'Type',
                priority: 2,
                cell: (row) => row.vehicle_type || '—',
            },
            {
                id: 'make_model',
                header: 'Make / model',
                priority: 2,
                cell: (row) =>
                    [row.make, row.model].filter(Boolean).join(' ') || '—',
            },
        ],
        [company.id, branch.id],
    );

    return (
        <>
            <Head title={`Vehicles · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Vehicle Master"
                    title="Vehicles"
                    company={company}
                    branch={branch}
                    dataVisibility={
                        dataVisibility
                            ? {
                                  label: dataVisibility.label,
                                  message: dataVisibility.message,
                              }
                            : null
                    }
                    crumbs={[
                        { label: 'Branch', href: links.dashboard },
                        { label: 'Vehicles' },
                    ]}
                />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="flex w-full gap-3 md:max-w-xl"
                    >
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </form>
                    {permissions.can_create ? (
                        <Button asChild>
                            <Link href={links.create}>Create vehicle</Link>
                        </Button>
                    ) : null}
                </div>
                <ResponsiveDataTable
                    caption="Vehicles"
                    columns={columns}
                    rows={vehicles.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No vehicles"
                    emptyDescription="Create a vehicle to get started."
                />
            </div>
        </>
    );
}
