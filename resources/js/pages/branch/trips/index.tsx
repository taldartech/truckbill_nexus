import { Head, router } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type TripRow = {
    id: string;
    status: string;
    lr_number: string | null;
    vehicle_registration: string | null;
    driver_name: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    created_at: string | null;
    urls: { show: string };
};

type Props = {
    filters: { status: string };
    permissions: { can_view: boolean; can_create: boolean };
    statusOptions: Array<{ value: string; label: string }>;
    trips: {
        data: TripRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string };
};

export default function TripsIndex({
    filters,
    statusOptions,
    trips,
    urls,
}: Props) {
    return (
        <>
            <Head title="OwnFleet Trips" />
            <AppIndexPage>
                <AppIndexListCard title="OwnFleet Trips">
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const status = (
                                form.elements.namedItem(
                                    'status',
                                ) as HTMLSelectElement
                            ).value;
                            router.get(
                                urls.index,
                                { status: status || undefined },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status}
                                className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
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
                                    <th>Date</th>
                                    <th>LR No</th>
                                    <th>Route</th>
                                    <th>Truck</th>
                                    <th>Driver</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {trips.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <p className="app-index-empty">
                                                No trips yet. Create one from an
                                                OwnFleet Bilty.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    trips.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>{row.created_at ?? '—'}</td>
                                            <td className="font-medium">
                                                {row.lr_number ?? '—'}
                                            </td>
                                            <td className="text-slate-500">
                                                {row.from_station_name ?? '—'} →{' '}
                                                {row.to_station_name ?? '—'}
                                            </td>
                                            <td>
                                                {row.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td>{row.driver_name ?? '—'}</td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={row.status}
                                                />
                                            </td>
                                            <td>
                                                <div className="flex justify-center">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="Open trip"
                                                        href={row.urls.show}
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
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
