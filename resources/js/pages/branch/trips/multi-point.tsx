import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Route, Wallet } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    TripDetailCard,
    TripDetailPage,
    TripInfoGrid,
    TripInfoItem,
} from '@/components/trip-detail';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatMtLabel, kgToMt } from '@/lib/operational-weight';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';

type Trip = {
    id: string;
    status: string;
    lr_number: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    vehicle: { id: string; registration_number: string } | null;
    driver: { id: string; name: string } | null;
    pickup_count: number;
    drop_count: number;
    allocation_count: number;
};

type Movements = {
    pickups: Array<{
        id: string;
        position: number;
        station_name: string | null;
        consignor_name: string | null;
        goods_description: string | null;
        weight: string;
        pickup_date: string | null;
    }>;
    drops: Array<{
        id: string;
        position: number;
        station_name: string | null;
        consignee_name: string | null;
    }>;
    allocations: Array<{
        id: string;
        trip_pickup_id: string;
        trip_drop_id: string;
        allocation_weight: string;
        rate: string;
        freight: string;
    }>;
};

type Props = {
    trip: Trip;
    movements: Movements;
    reconciliation: {
        pickups: Array<{
            id: string;
            weight: string;
            allocated: string;
            remaining: string;
            reconciled: boolean;
        }>;
        total_freight: string;
        all_reconciled: boolean;
        freight_positive: boolean;
        completion_ready: boolean;
    };
    formOptions: {
        stations: Array<{ id: string; name: string }>;
        consignors: Array<{ id: string; name: string }>;
        consignees: Array<{ id: string; name: string }>;
    };
    permissions: {
        can_edit: boolean;
    };
    urls: {
        show: string;
        expenses: string;
        lr_show: string;
        add_pickup: string;
        remove_pickup?: string;
        add_drop: string;
        remove_drop?: string;
        upsert_allocation: string;
        remove_allocation: string;
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

export default function TripMultiPoint({
    trip,
    movements,
    reconciliation,
    formOptions,
    permissions,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null; info?: string | null };
        errors?: Record<string, string>;
    }>().props;
    const mutable =
        permissions.can_edit &&
        (trip.status === 'pending' || trip.status === 'in_progress');
    const routeLabel = [trip.from_station_name, trip.to_station_name]
        .filter(Boolean)
        .join(' → ');

    return (
        <>
            <Head title={`Multi-point route · ${trip.lr_number ?? 'Trip'}`} />
            <LrfPage
                title="Multi-point Trip route"
                subtitle={
                    trip.lr_number
                        ? `Bilty ${trip.lr_number} · Define pickups, drops and weight allocations`
                        : 'Define pickups, drops and weight allocations'
                }
                backHref={urls.show}
                backLabel="Trip workspace"
                actions={
                    <>
                        <AppIconAction
                            icon={Wallet}
                            label="Manage expenses"
                            href={urls.expenses}
                            variant="outline"
                        />
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to Bilty"
                            href={urls.lr_show}
                            variant="outline"
                        />
                    </>
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {flash?.info ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.info}
                    </p>
                ) : null}

                <LrfSection
                    step={1}
                    title="Trip context"
                    description="One customer, one Own vehicle and one driver. Add multiple pickup and drop stations, then allocate weight."
                    tone="route"
                >
                    <TripDetailPage>
                        <TripDetailCard
                            title="Operation"
                            badges={
                                <WorkspaceStatusBadge
                                    status={statusLabel(trip.status)}
                                />
                            }
                        >
                            <TripInfoGrid>
                                <TripInfoItem
                                    label="Bilty"
                                    value={trip.lr_number}
                                />
                                <TripInfoItem
                                    label="Vehicle"
                                    value={trip.vehicle?.registration_number}
                                />
                                <TripInfoItem
                                    label="Driver"
                                    value={trip.driver?.name}
                                />
                                <TripInfoItem
                                    label="Route"
                                    value={routeLabel || null}
                                />
                                <TripInfoItem
                                    label="Pickups"
                                    value={String(trip.pickup_count)}
                                />
                                <TripInfoItem
                                    label="Drops"
                                    value={String(trip.drop_count)}
                                />
                                <TripInfoItem
                                    label="Allocations"
                                    value={String(trip.allocation_count)}
                                />
                                <TripInfoItem
                                    label="Reconciled"
                                    value={
                                        reconciliation.completion_ready
                                            ? 'Ready'
                                            : 'Incomplete'
                                    }
                                />
                            </TripInfoGrid>
                        </TripDetailCard>
                    </TripDetailPage>
                </LrfSection>

                <LrfSection
                    step={2}
                    title="Pickups & drops"
                    description="Add every loading and unloading station for this multi-point trip."
                    tone="receipt"
                >
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-3">
                            <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <Route className="size-4" />
                                Pickups
                            </h3>
                            {movements.pickups.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No pickups yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {movements.pickups.map((pickup) => (
                                        <li
                                            key={pickup.id}
                                            className="rounded-md border border-border px-3 py-2"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="font-medium">
                                                        #{pickup.position}{' '}
                                                        {pickup.station_name}
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        {formatMtLabel(
                                                            kgToMt(
                                                                pickup.weight,
                                                            ),
                                                        )}{' '}
                                                        · {pickup.pickup_date}
                                                        {pickup.consignor_name
                                                            ? ` · ${pickup.consignor_name}`
                                                            : ''}
                                                    </p>
                                                    {pickup.goods_description ? (
                                                        <p className="text-muted-foreground">
                                                            {
                                                                pickup.goods_description
                                                            }
                                                        </p>
                                                    ) : null}
                                                </div>
                                                {mutable &&
                                                urls.remove_pickup ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() => {
                                                            if (
                                                                !window.confirm(
                                                                    'Remove this pickup and its allocations?',
                                                                )
                                                            ) {
                                                                return;
                                                            }
                                                            router.delete(
                                                                urls.remove_pickup!.replace(
                                                                    'PICKUP_ID',
                                                                    pickup.id,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Remove
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {mutable ? (
                                <Form
                                    action={urls.add_pickup}
                                    method="post"
                                    className="grid gap-2 rounded-md border border-dashed border-border p-3"
                                >
                                    {({ processing }) => (
                                        <>
                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor="pickup_station_id"
                                                    required
                                                >
                                                    Pickup station
                                                </Label>
                                                <select
                                                    id="pickup_station_id"
                                                    name="station_id"
                                                    required
                                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                    defaultValue=""
                                                >
                                                    <option value="" disabled>
                                                        Station
                                                    </option>
                                                    {formOptions.stations.map(
                                                        (station) => (
                                                            <option
                                                                key={station.id}
                                                                value={
                                                                    station.id
                                                                }
                                                            >
                                                                {station.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <select
                                                name="consignor_party_id"
                                                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue=""
                                            >
                                                <option value="">
                                                    Consignor (optional)
                                                </option>
                                                {formOptions.consignors.map(
                                                    (party) => (
                                                        <option
                                                            key={party.id}
                                                            value={party.id}
                                                        >
                                                            {party.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <Input
                                                name="goods_description"
                                                placeholder="Goods"
                                            />
                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor="pickup_weight"
                                                    required
                                                >
                                                    Weight (MT)
                                                </Label>
                                                <Input
                                                    id="pickup_weight"
                                                    name="weight"
                                                    type="number"
                                                    step="0.001"
                                                    min="0.001"
                                                    required
                                                    placeholder="Weight (MT)"
                                                    aria-label="Pickup weight in MT"
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor="pickup_date"
                                                    required
                                                >
                                                    Pickup date
                                                </Label>
                                                <Input
                                                    id="pickup_date"
                                                    name="pickup_date"
                                                    type="date"
                                                    required
                                                />
                                            </div>
                                            {errors?.weight ? (
                                                <p className="text-sm text-destructive">
                                                    {errors.weight}
                                                </p>
                                            ) : null}
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="bg-[#2D8CFF] text-white hover:bg-[#2476db]"
                                            >
                                                Add pickup
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ) : null}
                        </div>

                        <div className="space-y-3">
                            <h3 className="text-sm font-semibold text-slate-800">
                                Drops
                            </h3>
                            {movements.drops.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No drops yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {movements.drops.map((drop) => (
                                        <li
                                            key={drop.id}
                                            className="rounded-md border border-border px-3 py-2"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="font-medium">
                                                        #{drop.position}{' '}
                                                        {drop.station_name}
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        {drop.consignee_name ??
                                                            'Consignee optional'}
                                                    </p>
                                                </div>
                                                {mutable && urls.remove_drop ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() => {
                                                            if (
                                                                !window.confirm(
                                                                    'Remove this drop and its allocations?',
                                                                )
                                                            ) {
                                                                return;
                                                            }
                                                            router.delete(
                                                                urls.remove_drop!.replace(
                                                                    'DROP_ID',
                                                                    drop.id,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Remove
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {mutable ? (
                                <Form
                                    action={urls.add_drop}
                                    method="post"
                                    className="grid gap-2 rounded-md border border-dashed border-border p-3"
                                >
                                    {({ processing }) => (
                                        <>
                                            <Label
                                                htmlFor="drop_station_id"
                                                required
                                            >
                                                Drop station
                                            </Label>
                                            <select
                                                id="drop_station_id"
                                                name="station_id"
                                                required
                                                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    Station
                                                </option>
                                                {formOptions.stations.map(
                                                    (station) => (
                                                        <option
                                                            key={station.id}
                                                            value={station.id}
                                                        >
                                                            {station.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <select
                                                name="consignee_party_id"
                                                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue=""
                                            >
                                                <option value="">
                                                    Consignee (optional)
                                                </option>
                                                {formOptions.consignees.map(
                                                    (party) => (
                                                        <option
                                                            key={party.id}
                                                            value={party.id}
                                                        >
                                                            {party.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="bg-[#2D8CFF] text-white hover:bg-[#2476db]"
                                            >
                                                Add drop
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ) : null}
                        </div>
                    </div>
                </LrfSection>

                <LrfSection
                    step={3}
                    title="Weight & freight allocation"
                    description="Total pickup weight must equal allocated drop weight. Individual allocation freight may be zero; trip total freight must satisfy approved validation."
                    tone="optional"
                >
                    <div className="space-y-3">
                        <dl className="grid gap-2 text-sm md:grid-cols-2">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Total freight
                                </dt>
                                <dd>₹{reconciliation.total_freight}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Fully reconciled
                                </dt>
                                <dd>
                                    {reconciliation.all_reconciled
                                        ? 'Yes'
                                        : 'No'}
                                </dd>
                            </div>
                        </dl>
                        <ul className="space-y-1 text-xs text-muted-foreground">
                            {reconciliation.pickups.map((row) => (
                                <li key={row.id}>
                                    Pickup remaining{' '}
                                    {formatMtLabel(kgToMt(row.remaining))} /{' '}
                                    {formatMtLabel(kgToMt(row.weight))}
                                    {row.reconciled ? ' · OK' : ''}
                                </li>
                            ))}
                        </ul>

                        {movements.allocations.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No allocations yet.
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-md border border-border">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted/40 text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2">
                                                Pickup
                                            </th>
                                            <th className="px-3 py-2">Drop</th>
                                            <th className="px-3 py-2">
                                                Weight (MT)
                                            </th>
                                            <th className="px-3 py-2">
                                                Freight
                                            </th>
                                            {mutable ? (
                                                <th className="px-3 py-2" />
                                            ) : null}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {movements.allocations.map(
                                            (allocation) => {
                                                const pickup =
                                                    movements.pickups.find(
                                                        (row) =>
                                                            row.id ===
                                                            allocation.trip_pickup_id,
                                                    );
                                                const drop =
                                                    movements.drops.find(
                                                        (row) =>
                                                            row.id ===
                                                            allocation.trip_drop_id,
                                                    );
                                                const removeUrl =
                                                    urls.remove_allocation.replace(
                                                        'ALLOCATION_ID',
                                                        allocation.id,
                                                    );
                                                return (
                                                    <tr
                                                        key={allocation.id}
                                                        className="border-t border-border"
                                                    >
                                                        <td className="px-3 py-2">
                                                            {pickup?.station_name ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {drop?.station_name ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {formatMtLabel(
                                                                kgToMt(
                                                                    allocation.allocation_weight,
                                                                ),
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            ₹
                                                            {allocation.freight}
                                                        </td>
                                                        {mutable ? (
                                                            <td className="px-3 py-2 text-right">
                                                                <Form
                                                                    action={
                                                                        removeUrl
                                                                    }
                                                                    method="delete"
                                                                >
                                                                    {({
                                                                        processing,
                                                                    }) => (
                                                                        <Button
                                                                            type="submit"
                                                                            variant="destructive"
                                                                            size="sm"
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            Remove
                                                                        </Button>
                                                                    )}
                                                                </Form>
                                                            </td>
                                                        ) : null}
                                                    </tr>
                                                );
                                            },
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {mutable &&
                        movements.pickups.length > 0 &&
                        movements.drops.length > 0 ? (
                            <Form
                                action={urls.upsert_allocation}
                                method="post"
                                className="grid gap-2 rounded-md border border-dashed border-border p-3 md:grid-cols-2"
                            >
                                {({ processing }) => (
                                    <>
                                        <div className="grid gap-1 md:col-span-2">
                                            <Label>
                                                Add / update allocation
                                            </Label>
                                        </div>
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor="mp_trip_pickup_id"
                                                required
                                            >
                                                Pickup
                                            </Label>
                                            <select
                                                id="mp_trip_pickup_id"
                                                name="trip_pickup_id"
                                                required
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    Pickup
                                                </option>
                                                {movements.pickups.map(
                                                    (pickup) => (
                                                        <option
                                                            key={pickup.id}
                                                            value={pickup.id}
                                                        >
                                                            #{pickup.position}{' '}
                                                            {
                                                                pickup.station_name
                                                            }{' '}
                                                            (
                                                            {formatMtLabel(
                                                                kgToMt(
                                                                    pickup.weight,
                                                                ),
                                                            )}
                                                            )
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor="mp_trip_drop_id"
                                                required
                                            >
                                                Drop
                                            </Label>
                                            <select
                                                id="mp_trip_drop_id"
                                                name="trip_drop_id"
                                                required
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    Drop
                                                </option>
                                                {movements.drops.map((drop) => (
                                                    <option
                                                        key={drop.id}
                                                        value={drop.id}
                                                    >
                                                        #{drop.position}{' '}
                                                        {drop.station_name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor="mp_allocation_weight"
                                                required
                                            >
                                                Allocation weight (MT)
                                            </Label>
                                            <Input
                                                id="mp_allocation_weight"
                                                name="allocation_weight"
                                                type="number"
                                                step="0.001"
                                                min="0.001"
                                                required
                                                placeholder="Allocation weight (MT)"
                                                aria-label="Allocation weight in MT"
                                            />
                                        </div>
                                        <Input
                                            name="rate"
                                            type="number"
                                            step="0.0001"
                                            min="0"
                                            placeholder="Rate (may be 0)"
                                        />
                                        <Input
                                            name="freight"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="Freight (may be 0)"
                                        />
                                        <Input
                                            name="distance"
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            placeholder="Distance (optional)"
                                        />
                                        {errors?.allocation_weight ? (
                                            <p className="text-sm text-destructive md:col-span-2">
                                                {errors.allocation_weight}
                                            </p>
                                        ) : null}
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="bg-[#2FB36F] text-white hover:bg-[#279960] md:col-span-2"
                                        >
                                            Save allocation
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : null}

                        <div className="flex flex-wrap gap-2 pt-2">
                            <Button asChild variant="primary">
                                <Link href={urls.expenses}>
                                    Continue to expenses
                                </Link>
                            </Button>
                            <Button asChild variant="secondary">
                                <Link href={urls.show}>
                                    Open Trip Operations
                                </Link>
                            </Button>
                        </div>
                    </div>
                </LrfSection>
            </LrfPage>
        </>
    );
}
