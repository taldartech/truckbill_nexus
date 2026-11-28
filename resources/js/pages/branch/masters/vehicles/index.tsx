import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: string;
    registration_number: string;
    ownership: string | null;
    primary_driver_name: string | null;
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
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page?: number;
        };
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
    const showUrl = (id: string) => `/vehicles/${id}`;

    return (
        <>
            <Head title={`Vehicles · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title="Vehicles"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={links.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create vehicle
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

                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const q = (
                                form.elements.namedItem('q') as HTMLInputElement
                            ).value.trim();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Registration, make, model…"
                                className="min-w-[12rem]"
                            />
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Registration</th>
                                    <th>Ownership</th>
                                    <th>Primary Driver</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Make / model</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {vehicles.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <p className="app-index-empty">
                                                No vehicles. Create a vehicle to
                                                get started.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    vehicles.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.registration_number}
                                            </td>
                                            <td>
                                                {row.ownership === 'own'
                                                    ? 'Own Vehicle'
                                                    : row.ownership === 'market'
                                                      ? 'Market Vehicle'
                                                      : '—'}
                                            </td>
                                            <td>
                                                {row.primary_driver_name ??
                                                    (row.ownership === 'own'
                                                        ? 'Unassigned'
                                                        : '—')}
                                            </td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={
                                                        row.is_active
                                                            ? 'Active'
                                                            : 'Inactive'
                                                    }
                                                />
                                            </td>
                                            <td>{row.vehicle_type || '—'}</td>
                                            <td>
                                                {[row.make, row.model]
                                                    .filter(Boolean)
                                                    .join(' ') || '—'}
                                            </td>
                                            <td>
                                                <div className="flex justify-center">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="View vehicle"
                                                        href={showUrl(row.id)}
                                                        variant="info"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={links.index}
                            meta={vehicles.meta}
                            query={{
                                q: filters.q || undefined,
                                active: filters.active || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
