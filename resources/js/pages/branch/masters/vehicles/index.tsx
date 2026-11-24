import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
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
    links: { hub: string; dashboard: string; index: string; create: string };
};

export default function VehiclesIndex({
    branch,
    permissions,
    filters,
    vehicles,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const showUrl = (id: string) => `/vehicles/${id}`;

    const columns = useMemo<DataTableColumn<Row>[]>(
        () => [
            {
                id: 'registration',
                header: 'Registration',
                priority: 0,
                cell: (row) => (
                    <span className="font-medium">
                        {row.registration_number}
                    </span>
                ),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'Active' : 'Inactive'}
                    />
                ),
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
        [],
    );

    return (
        <>
            <Head title={`Vehicles · ${branch.name}`} />
            <PageShell
                title="Vehicles"
                description="Fleet registry for OwnFleet and operational assignments."
                actions={
                    permissions.can_create ? (
                        <Button asChild variant="primary" className="gap-2">
                            <Link href={links.create}>
                                <Plus className="size-4" />
                                Create vehicle
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

                <FormSection title="Filters" columns={2}>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="contents"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Registration, make, model…"
                            />
                        </div>
                        <div className="flex items-end">
                            <Button
                                type="submit"
                                variant="secondary"
                                className="gap-2"
                            >
                                <Filter className="size-4" />
                                Apply
                            </Button>
                        </div>
                    </form>
                </FormSection>

                <ResponsiveDataTable
                    caption="Vehicles"
                    columns={columns}
                    rows={vehicles.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No vehicles"
                    emptyDescription="Create a vehicle to get started."
                    actions={(row) => (
                        <AppIconAction
                            icon={Eye}
                            label="View vehicle"
                            href={showUrl(row.id)}
                            variant="info"
                        />
                    )}
                />
            </PageShell>
        </>
    );
}
