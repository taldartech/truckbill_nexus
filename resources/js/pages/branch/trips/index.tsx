import { Head, router } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
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

function statusLabel(status: string): string {
    switch (status) {
        case 'in_progress':
            return 'In Progress';
        case 'pending':
            return 'Pending';
        case 'completed':
            return 'Completed';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

export default function TripsIndex({
    filters,
    statusOptions,
    trips,
    urls,
}: Props) {
    return (
        <>
            <Head title="OwnFleet Trips" />
            <PageShell title={'OwnFleet Trips'}>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    Trips are created from OwnFleet Lorry Receipts. Open an
                    OwnFleet LR and use Create Trip.
                </p>

                <form
                    className="flex flex-wrap items-end gap-3"
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
                    <div className="grid gap-1">
                        <Label htmlFor="status">Status</Label>
                        <select
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All</option>
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-hidden rounded-lg border border-border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/40 text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">LR</th>
                                <th className="hidden px-3 py-2 font-medium md:table-cell">
                                    Route
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Vehicle
                                </th>
                                <th className="hidden px-3 py-2 font-medium lg:table-cell">
                                    Driver
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="hidden px-3 py-2 font-medium xl:table-cell">
                                    Created
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {trips.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        No OwnFleet Trips yet.
                                    </td>
                                </tr>
                            ) : (
                                trips.data.map((trip) => (
                                    <tr
                                        key={trip.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {trip.lr_number ?? 'Trip'}
                                        </td>
                                        <td className="hidden px-3 py-2 md:table-cell">
                                            {[
                                                trip.from_station_name,
                                                trip.to_station_name,
                                            ]
                                                .filter(Boolean)
                                                .join(' → ') || '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {trip.vehicle_registration ?? '—'}
                                        </td>
                                        <td className="hidden px-3 py-2 lg:table-cell">
                                            {trip.driver_name ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {statusLabel(trip.status)}
                                        </td>
                                        <td className="hidden px-3 py-2 xl:table-cell">
                                            {trip.created_at ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <AppIconAction
                                                icon={Eye}
                                                label="Open trip"
                                                href={trip.urls.show}
                                                variant="info"
                                            />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {trips.meta.last_page > 1 ? (
                    <p className="text-sm text-muted-foreground">
                        Page {trips.meta.current_page} of {trips.meta.last_page}{' '}
                        ({trips.meta.total} trips)
                    </p>
                ) : null}
            </PageShell>
        </>
    );
}
