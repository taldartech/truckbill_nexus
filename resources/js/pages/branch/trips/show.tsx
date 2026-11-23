import { Head, Link, usePage } from '@inertiajs/react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';

type Trip = {
    id: string;
    status: string;
    lr_number: string | null;
    lr_freight_total: string | null;
    lr_additional_charges_total: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    vehicle: { id: string; registration_number: string } | null;
    driver: { id: string; name: string } | null;
    primary_driver: { id: string; name: string } | null;
    substitute_driver: { id: string; name: string } | null;
    starting_odometer: string | null;
    ending_odometer: string | null;
    trip_distance: string | null;
    departure_at: string | null;
    actual_arrival_at: string | null;
    expected_end_at: string | null;
    timing_performance: string | null;
    cancelled_at: string | null;
    cancellation_remark: string | null;
    pickup_count: number;
    drop_count: number;
    allocation_count: number;
    created_at: string | null;
};

type Props = {
    trip: Trip;
    permissions: {
        can_edit: boolean;
        can_dispatch: boolean;
        can_complete: boolean;
        can_cancel: boolean;
        can_delete: boolean;
    };
    urls: {
        index: string;
        lr_show: string;
    };
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

export default function TripShow({ trip, urls }: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null; info?: string | null };
    }>().props;

    return (
        <>
            <Head title={`Trip · ${trip.lr_number ?? trip.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← OwnFleet Trips
                    </Link>
                    <MasterPageHeader
                        title={`Trip · LR ${trip.lr_number ?? '—'}`}
                    />
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="secondary">
                        <Link href={urls.lr_show}>Open LR</Link>
                    </Button>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-lg border border-border p-4">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Overview
                        </h2>
                        <dl className="mt-3 grid gap-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd>{statusLabel(trip.status)}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Route</dt>
                                <dd className="text-right">
                                    {[
                                        trip.from_station_name,
                                        trip.to_station_name,
                                    ]
                                        .filter(Boolean)
                                        .join(' → ') || '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Vehicle
                                </dt>
                                <dd>
                                    {trip.vehicle?.registration_number ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Operating driver
                                </dt>
                                <dd>{trip.driver?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Primary driver
                                </dt>
                                <dd>{trip.primary_driver?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Substitute
                                </dt>
                                <dd>
                                    {trip.substitute_driver?.name ?? 'None'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Created
                                </dt>
                                <dd>{trip.created_at ?? '—'}</dd>
                            </div>
                        </dl>
                    </section>

                    <section className="rounded-lg border border-border p-4">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Financial (LR)
                        </h2>
                        <dl className="mt-3 grid gap-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Agreed freight
                                </dt>
                                <dd>₹{trip.lr_freight_total ?? '0.00'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Additional charges
                                </dt>
                                <dd>
                                    ₹
                                    {trip.lr_additional_charges_total ??
                                        '0.00'}
                                </dd>
                            </div>
                        </dl>
                        <p className="mt-4 text-xs text-muted-foreground">
                            Dispatch, multi-point allocations, expenses and
                            completion land in subsequent OwnFleet batches on
                            this Trip detail.
                        </p>
                        <dl className="mt-3 grid gap-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Pickups
                                </dt>
                                <dd>{trip.pickup_count}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Drops</dt>
                                <dd>{trip.drop_count}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Allocations
                                </dt>
                                <dd>{trip.allocation_count}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </>
    );
}
