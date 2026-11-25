import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, FileText, Wallet } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import {
    TripDetailCard,
    TripDetailLayout,
    TripDetailPage,
    TripDieselCard,
    TripExpensesTable,
    TripFinancialSummary,
    TripInfoGrid,
    TripInfoItem,
    TripKmBar,
} from '@/components/trip-detail';
import {
    WorkflowProgress,
    type WorkflowStep,
} from '@/components/workflow-progress';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateTime } from '@/lib/dates';
import { formatMtLabel, kgToMt } from '@/lib/operational-weight';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
    pickup_count: number;
    drop_count: number;
    allocation_count: number;
    starting_odometer: string | null;
    ending_odometer: string | null;
    trip_distance: string | null;
    departure_at: string | null;
    actual_arrival_at: string | null;
    expected_end_at: string | null;
    timing_performance: string | null;
    cancelled_at: string | null;
    cancellation_remark: string | null;
    created_at: string | null;
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

type ExpenseRow = {
    id: string;
    expense_date: string | null;
    category_name: string | null;
    amount: string;
    is_diesel: boolean;
    diesel_litres: string | null;
    diesel_rate_per_litre?: string | null;
    diesel_odometer?: string | null;
    remarks: string | null;
    payment_mode?: string | null;
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
        expense_categories: Array<{ id: string; name: string }>;
        payment_modes: Array<{ id: string; name: string }>;
        bank_cash_accounts: Array<{ id: string; name: string }>;
    };
    permissions: {
        can_edit: boolean;
        can_dispatch: boolean;
        can_complete: boolean;
        can_cancel?: boolean;
        can_delete?: boolean;
    };
    urls: {
        index: string;
        lr_show: string;
        add_pickup: string;
        remove_pickup?: string;
        add_drop: string;
        remove_drop?: string;
        upsert_allocation: string;
        remove_allocation: string;
        dispatch: string;
        complete: string;
        add_expense: string;
        remove_expense: string;
        cancel?: string;
        destroy?: string;
        documents?: string;
        lr_documents?: string;
    };
    documentDrawer?: Omit<DocumentDrawerProps, 'open' | 'onOpenChange'> | null;
    expenses: ExpenseRow[];
    profit: {
        agreed_freight: string;
        additional_charges: string;
        diesel_cost: string;
        other_expenses: string;
        total_expenses: string;
        trip_profit: string;
        trip_mileage_label: string;
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

function expenseTableHeaders(mutable: boolean): string[] {
    const headers = [
        'Date',
        'Type',
        'Meter Reading(KM)',
        'Liters',
        'Rate',
        'Amount',
        'Notes',
    ];
    if (mutable) {
        headers.push('');
    }
    return headers;
}

export default function TripShow({
    trip,
    movements,
    reconciliation,
    formOptions,
    permissions,
    urls,
    documentDrawer = null,
    expenses,
    profit,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null; info?: string | null };
        errors?: Record<string, string>;
    }>().props;
    const mutable =
        permissions.can_edit &&
        (trip.status === 'pending' || trip.status === 'in_progress');
    const [docsOpen, setDocsOpen] = useState(false);
    const expensesSectionRef = useRef<HTMLDivElement>(null);

    const scrollToExpenses = () => {
        expensesSectionRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    };

    const dieselLitresTotal = useMemo(() => {
        return expenses.reduce((sum, expense) => {
            if (!expense.is_diesel || !expense.diesel_litres) {
                return sum;
            }
            return sum + Number(expense.diesel_litres);
        }, 0);
    }, [expenses]);

    const workflowSteps = useMemo<WorkflowStep[]>(() => {
        const status = trip.status;
        const order = ['pending', 'in_progress', 'completed'] as const;
        const idx =
            status === 'cancelled'
                ? -1
                : Math.max(0, order.indexOf(status as (typeof order)[number]));

        const step = (
            id: string,
            label: string,
            position: number,
        ): WorkflowStep => {
            if (status === 'cancelled') {
                return {
                    id,
                    label,
                    status: position === 0 ? 'blocked' : 'upcoming',
                };
            }
            if (position < idx) {
                return { id, label, status: 'complete' };
            }
            if (position === idx) {
                return { id, label, status: 'current' };
            }
            return { id, label, status: 'upcoming' };
        };

        return [
            step('pending', 'Pending', 0),
            step('dispatch', 'Dispatched', 1),
            step('complete', 'Completed', 2),
            {
                id: 'settle',
                label: 'Settlement',
                status:
                    status === 'completed'
                        ? 'current'
                        : status === 'cancelled'
                          ? 'blocked'
                          : 'upcoming',
            },
        ];
    }, [trip.status]);

    const tripTitle = trip.lr_number ?? 'Bilty';

    return (
        <>
            <Head title={`Trip · ${trip.lr_number ?? trip.id}`} />
            <TripDetailPage>
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <TripDetailLayout
                    main={
                        <>
                            <TripDetailCard
                                title={`Trip Information (${tripTitle})`}
                                badges={
                                    <WorkspaceStatusBadge
                                        status={statusLabel(trip.status)}
                                    />
                                }
                                actions={
                                    <>
                                        <AppIconAction
                                            icon={ArrowLeft}
                                            label="Back to Bilty"
                                            href={urls.lr_show}
                                            variant="outline"
                                        />
                                        <AppIconAction
                                            icon={Wallet}
                                            label="Manage expenses"
                                            variant="outline"
                                            onClick={scrollToExpenses}
                                        />
                                        {documentDrawer ? (
                                            <AppIconAction
                                                icon={FileText}
                                                label="Trip documents"
                                                variant="outline"
                                                onClick={() =>
                                                    setDocsOpen(true)
                                                }
                                            />
                                        ) : null}
                                    </>
                                }
                            >
                                <TripInfoGrid>
                                    <TripInfoItem
                                        label="LR / Bilty"
                                        value={trip.lr_number}
                                    />
                                    <TripInfoItem
                                        label="Status"
                                        value={statusLabel(trip.status)}
                                    />
                                    <TripInfoItem
                                        label="From"
                                        value={trip.from_station_name}
                                    />
                                    <TripInfoItem
                                        label="To"
                                        value={trip.to_station_name}
                                    />
                                    <TripInfoItem
                                        label="Vehicle"
                                        value={
                                            trip.vehicle?.registration_number
                                        }
                                    />
                                    <TripInfoItem
                                        label="Operating driver"
                                        value={trip.driver?.name}
                                    />
                                    <TripInfoItem
                                        label="Primary driver"
                                        value={trip.primary_driver?.name}
                                    />
                                    <TripInfoItem
                                        label="Substitute driver"
                                        value={trip.substitute_driver?.name}
                                    />
                                    <TripInfoItem
                                        label="LR freight"
                                        value={
                                            trip.lr_freight_total
                                                ? `₹${trip.lr_freight_total}`
                                                : null
                                        }
                                    />
                                    <TripInfoItem
                                        label="LR additional"
                                        value={
                                            trip.lr_additional_charges_total
                                                ? `₹${trip.lr_additional_charges_total}`
                                                : null
                                        }
                                    />
                                    <TripInfoItem
                                        label="Starting odometer"
                                        value={trip.starting_odometer}
                                    />
                                    <TripInfoItem
                                        label="Ending odometer"
                                        value={trip.ending_odometer}
                                    />
                                    <TripInfoItem
                                        label="Trip distance"
                                        value={
                                            trip.trip_distance
                                                ? `${trip.trip_distance} km`
                                                : null
                                        }
                                    />
                                    <TripInfoItem
                                        label="Departure"
                                        value={formatDisplayDateTime(
                                            trip.departure_at,
                                        )}
                                    />
                                    <TripInfoItem
                                        label="Expected end"
                                        value={formatDisplayDateTime(
                                            trip.expected_end_at,
                                        )}
                                    />
                                    <TripInfoItem
                                        label="Actual arrival"
                                        value={formatDisplayDateTime(
                                            trip.actual_arrival_at,
                                        )}
                                    />
                                    <TripInfoItem
                                        label="Timing"
                                        value={trip.timing_performance}
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
                                    {trip.cancelled_at ? (
                                        <TripInfoItem
                                            label="Cancelled at"
                                            value={trip.cancelled_at}
                                            span2
                                        />
                                    ) : null}
                                    {trip.cancellation_remark ? (
                                        <TripInfoItem
                                            label="Cancellation reason"
                                            value={trip.cancellation_remark}
                                            span2
                                        />
                                    ) : null}
                                </TripInfoGrid>
                            </TripDetailCard>

                            <WorkflowProgress steps={workflowSteps} />

                            <TripKmBar
                                pickupCount={trip.pickup_count}
                                dropCount={trip.drop_count}
                                tripDistance={trip.trip_distance}
                                totalFreight={reconciliation.total_freight}
                                lrFreight={trip.lr_freight_total}
                                additionalCharges={
                                    trip.lr_additional_charges_total
                                }
                                totalExpenses={profit.total_expenses}
                                mileageLabel={profit.trip_mileage_label}
                                allocationReady={
                                    reconciliation.completion_ready
                                }
                            />

                            <TripDetailCard title="Route · Pickups & drops">
                                <div className="grid gap-6 p-4 lg:grid-cols-2">
                                    <div className="space-y-3">
                                        <h3 className="text-sm font-semibold text-slate-800">
                                            Pickups
                                        </h3>
                                        {movements.pickups.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No pickups yet.
                                            </p>
                                        ) : (
                                            <ul className="space-y-2 text-sm">
                                                {movements.pickups.map(
                                                    (pickup) => (
                                                        <li
                                                            key={pickup.id}
                                                            className="rounded-md border border-border px-3 py-2"
                                                        >
                                                            <div className="flex items-start justify-between gap-2">
                                                                <div>
                                                                    <p className="font-medium">
                                                                        #
                                                                        {
                                                                            pickup.position
                                                                        }{' '}
                                                                        {
                                                                            pickup.station_name
                                                                        }
                                                                    </p>
                                                                    <p className="text-muted-foreground">
                                                                        {formatMtLabel(
                                                                            kgToMt(
                                                                                pickup.weight,
                                                                            ),
                                                                        )}{' '}
                                                                        ·{' '}
                                                                        {
                                                                            pickup.pickup_date
                                                                        }
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
                                                                            const removePickupUrl =
                                                                                urls.remove_pickup;
                                                                            if (
                                                                                !removePickupUrl ||
                                                                                !window.confirm(
                                                                                    'Remove this pickup and its allocations?',
                                                                                )
                                                                            ) {
                                                                                return;
                                                                            }
                                                                            router.delete(
                                                                                removePickupUrl.replace(
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
                                                    ),
                                                )}
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
                                                        <Label htmlFor="pickup_station_id">
                                                            Add pickup
                                                        </Label>
                                                        <select
                                                            id="pickup_station_id"
                                                            name="station_id"
                                                            required
                                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Station
                                                            </option>
                                                            {formOptions.stations.map(
                                                                (station) => (
                                                                    <option
                                                                        key={
                                                                            station.id
                                                                        }
                                                                        value={
                                                                            station.id
                                                                        }
                                                                    >
                                                                        {
                                                                            station.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <select
                                                            name="consignor_party_id"
                                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option value="">
                                                                Consignor
                                                                (optional)
                                                            </option>
                                                            {formOptions.consignors.map(
                                                                (party) => (
                                                                    <option
                                                                        key={
                                                                            party.id
                                                                        }
                                                                        value={
                                                                            party.id
                                                                        }
                                                                    >
                                                                        {
                                                                            party.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <Input
                                                            name="goods_description"
                                                            placeholder="Goods"
                                                        />
                                                        <Input
                                                            name="weight"
                                                            type="number"
                                                            step="0.001"
                                                            min="0.001"
                                                            required
                                                            placeholder="Weight (MT)"
                                                            aria-label="Pickup weight in MT"
                                                        />
                                                        <Input
                                                            name="pickup_date"
                                                            type="date"
                                                            required
                                                        />
                                                        {errors?.weight ? (
                                                            <p className="text-sm text-destructive">
                                                                {errors.weight}
                                                            </p>
                                                        ) : null}
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
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
                                                                    #
                                                                    {
                                                                        drop.position
                                                                    }{' '}
                                                                    {
                                                                        drop.station_name
                                                                    }
                                                                </p>
                                                                <p className="text-muted-foreground">
                                                                    {drop.consignee_name ??
                                                                        'Consignee optional'}
                                                                </p>
                                                            </div>
                                                            {mutable &&
                                                            urls.remove_drop ? (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="destructive"
                                                                    onClick={() => {
                                                                        const removeDropUrl =
                                                                            urls.remove_drop;
                                                                        if (
                                                                            !removeDropUrl ||
                                                                            !window.confirm(
                                                                                'Remove this drop and its allocations?',
                                                                            )
                                                                        ) {
                                                                            return;
                                                                        }
                                                                        router.delete(
                                                                            removeDropUrl.replace(
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
                                                        <Label htmlFor="drop_station_id">
                                                            Add drop
                                                        </Label>
                                                        <select
                                                            id="drop_station_id"
                                                            name="station_id"
                                                            required
                                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Station
                                                            </option>
                                                            {formOptions.stations.map(
                                                                (station) => (
                                                                    <option
                                                                        key={
                                                                            station.id
                                                                        }
                                                                        value={
                                                                            station.id
                                                                        }
                                                                    >
                                                                        {
                                                                            station.name
                                                                        }
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
                                                                Consignee
                                                                (optional)
                                                            </option>
                                                            {formOptions.consignees.map(
                                                                (party) => (
                                                                    <option
                                                                        key={
                                                                            party.id
                                                                        }
                                                                        value={
                                                                            party.id
                                                                        }
                                                                    >
                                                                        {
                                                                            party.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
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
                            </TripDetailCard>

                            <TripDetailCard title="Allocations">
                                <div className="space-y-3 p-4">
                                    <dl className="grid gap-2 text-sm md:grid-cols-2">
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">
                                                Total freight
                                            </dt>
                                            <dd>
                                                ₹{reconciliation.total_freight}
                                            </dd>
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
                                                {formatMtLabel(
                                                    kgToMt(row.remaining),
                                                )}{' '}
                                                /{' '}
                                                {formatMtLabel(
                                                    kgToMt(row.weight),
                                                )}
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
                                                        <th className="px-3 py-2">
                                                            Drop
                                                        </th>
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
                                                                    key={
                                                                        allocation.id
                                                                    }
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
                                                                        {
                                                                            allocation.freight
                                                                        }
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
                                                            Add / update
                                                            allocation
                                                        </Label>
                                                    </div>
                                                    <select
                                                        name="trip_pickup_id"
                                                        required
                                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                        defaultValue=""
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            Pickup
                                                        </option>
                                                        {movements.pickups.map(
                                                            (pickup) => (
                                                                <option
                                                                    key={
                                                                        pickup.id
                                                                    }
                                                                    value={
                                                                        pickup.id
                                                                    }
                                                                >
                                                                    #
                                                                    {
                                                                        pickup.position
                                                                    }{' '}
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
                                                    <select
                                                        name="trip_drop_id"
                                                        required
                                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                        defaultValue=""
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            Drop
                                                        </option>
                                                        {movements.drops.map(
                                                            (drop) => (
                                                                <option
                                                                    key={
                                                                        drop.id
                                                                    }
                                                                    value={
                                                                        drop.id
                                                                    }
                                                                >
                                                                    #
                                                                    {
                                                                        drop.position
                                                                    }{' '}
                                                                    {
                                                                        drop.station_name
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <Input
                                                        name="allocation_weight"
                                                        type="number"
                                                        step="0.001"
                                                        min="0.001"
                                                        required
                                                        placeholder="Allocation weight (MT)"
                                                        aria-label="Allocation weight in MT"
                                                    />
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
                                                            {
                                                                errors.allocation_weight
                                                            }
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
                                </div>
                            </TripDetailCard>

                            <div
                                ref={expensesSectionRef}
                                id="manage-expenses"
                                className="trip-workspace-section"
                            >
                                <TripDetailCard title="Manage Expenses">
                                    <div className="space-y-4 p-4">
                                        <TripExpensesTable
                                            headers={expenseTableHeaders(
                                                mutable,
                                            )}
                                            empty="No expenses yet."
                                        >
                                            {expenses.map((expense) => (
                                                <tr key={expense.id}>
                                                    <td>
                                                        {expense.expense_date ??
                                                            '—'}
                                                    </td>
                                                    <td>
                                                        {expense.category_name ??
                                                            '—'}
                                                        {expense.payment_mode
                                                            ? ` · ${expense.payment_mode}`
                                                            : ''}
                                                    </td>
                                                    <td>
                                                        {expense.diesel_odometer ??
                                                            '—'}
                                                    </td>
                                                    <td>
                                                        {expense.is_diesel &&
                                                        expense.diesel_litres
                                                            ? expense.diesel_litres
                                                            : '—'}
                                                    </td>
                                                    <td>
                                                        {expense.diesel_rate_per_litre ??
                                                            '—'}
                                                    </td>
                                                    <td>₹{expense.amount}</td>
                                                    <td className="text-muted-foreground">
                                                        {expense.remarks ?? '—'}
                                                    </td>
                                                    {mutable ? (
                                                        <td className="text-right">
                                                            <Form
                                                                action={urls.remove_expense.replace(
                                                                    'EXPENSE_ID',
                                                                    expense.id,
                                                                )}
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
                                            ))}
                                        </TripExpensesTable>

                                        {mutable ? (
                                            <Form
                                                action={urls.add_expense}
                                                method="post"
                                                className="grid gap-2 rounded-md border border-dashed border-border p-3 md:grid-cols-2"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <select
                                                            name="expense_category_id"
                                                            required
                                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Category
                                                            </option>
                                                            {formOptions.expense_categories.map(
                                                                (category) => (
                                                                    <option
                                                                        key={
                                                                            category.id
                                                                        }
                                                                        value={
                                                                            category.id
                                                                        }
                                                                    >
                                                                        {
                                                                            category.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <Input
                                                            name="expense_date"
                                                            type="date"
                                                            required
                                                        />
                                                        <select
                                                            name="payment_mode_id"
                                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option value="">
                                                                Payment mode
                                                                (optional)
                                                            </option>
                                                            {formOptions.payment_modes.map(
                                                                (mode) => (
                                                                    <option
                                                                        key={
                                                                            mode.id
                                                                        }
                                                                        value={
                                                                            mode.id
                                                                        }
                                                                    >
                                                                        {
                                                                            mode.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <select
                                                            name="paid_from_bank_cash_account_id"
                                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option value="">
                                                                Bank / cash
                                                                account
                                                                (optional)
                                                            </option>
                                                            {formOptions.bank_cash_accounts.map(
                                                                (account) => (
                                                                    <option
                                                                        key={
                                                                            account.id
                                                                        }
                                                                        value={
                                                                            account.id
                                                                        }
                                                                    >
                                                                        {
                                                                            account.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <Input
                                                            name="amount"
                                                            type="number"
                                                            step="0.01"
                                                            min="0.01"
                                                            placeholder="Amount (non-diesel)"
                                                        />
                                                        <label className="flex items-center gap-2 text-sm md:col-span-2">
                                                            <input
                                                                type="checkbox"
                                                                name="is_diesel"
                                                                value="1"
                                                            />
                                                            Diesel expense
                                                            (amount = litres ×
                                                            rate)
                                                        </label>
                                                        <Input
                                                            name="diesel_litres"
                                                            type="number"
                                                            step="0.001"
                                                            min="0"
                                                            placeholder="Litres"
                                                        />
                                                        <Input
                                                            name="diesel_rate_per_litre"
                                                            type="number"
                                                            step="0.0001"
                                                            min="0"
                                                            placeholder="Rate / litre"
                                                        />
                                                        <Input
                                                            name="diesel_odometer"
                                                            type="number"
                                                            step="0.1"
                                                            min="0"
                                                            placeholder="Meter reading (KM)"
                                                        />
                                                        <Input
                                                            name="remarks"
                                                            placeholder="Notes"
                                                            className="md:col-span-2"
                                                        />
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                            className="bg-[#2FB36F] text-white hover:bg-[#279960] md:col-span-2"
                                                        >
                                                            Add expense
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        ) : null}
                                    </div>
                                </TripDetailCard>
                            </div>

                            <TripDetailCard title="Dispatch & completion">
                                <div className="space-y-3 p-4">
                                    {permissions.can_dispatch &&
                                    trip.status === 'pending' ? (
                                        <Form
                                            action={urls.dispatch}
                                            method="post"
                                            className="grid max-w-xl gap-2 rounded-md border border-dashed border-border p-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <Label htmlFor="starting_odometer">
                                                        Dispatch — starting
                                                        odometer
                                                    </Label>
                                                    <Input
                                                        id="starting_odometer"
                                                        name="starting_odometer"
                                                        type="number"
                                                        step="0.1"
                                                        min="0"
                                                        required
                                                    />
                                                    <Label htmlFor="departure_at">
                                                        Departure (optional)
                                                    </Label>
                                                    <Input
                                                        id="departure_at"
                                                        name="departure_at"
                                                        type="datetime-local"
                                                    />
                                                    {errors?.starting_odometer ? (
                                                        <p className="text-sm text-destructive">
                                                            {
                                                                errors.starting_odometer
                                                            }
                                                        </p>
                                                    ) : null}
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        className="bg-[#2D8CFF] text-white hover:bg-[#2476db]"
                                                    >
                                                        Dispatch trip
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : null}

                                    {permissions.can_complete &&
                                    trip.status === 'in_progress' ? (
                                        <Form
                                            action={urls.complete}
                                            method="post"
                                            className="grid max-w-xl gap-2 rounded-md border border-dashed border-border p-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <Label htmlFor="ending_odometer">
                                                        Complete — ending
                                                        odometer
                                                    </Label>
                                                    <Input
                                                        id="ending_odometer"
                                                        name="ending_odometer"
                                                        type="number"
                                                        step="0.1"
                                                        min="0"
                                                        required
                                                    />
                                                    <Label htmlFor="actual_arrival_at">
                                                        Actual arrival
                                                        (optional; defaults now)
                                                    </Label>
                                                    <Input
                                                        id="actual_arrival_at"
                                                        name="actual_arrival_at"
                                                        type="datetime-local"
                                                    />
                                                    {!reconciliation.completion_ready ? (
                                                        <p className="text-sm text-amber-700">
                                                            Complete allocations
                                                            before completion
                                                            (full weight +
                                                            positive freight).
                                                        </p>
                                                    ) : null}
                                                    {errors?.ending_odometer ? (
                                                        <p className="text-sm text-destructive">
                                                            {
                                                                errors.ending_odometer
                                                            }
                                                        </p>
                                                    ) : null}
                                                    {errors?.allocations ? (
                                                        <p className="text-sm text-destructive">
                                                            {errors.allocations}
                                                        </p>
                                                    ) : null}
                                                    <Button
                                                        type="submit"
                                                        disabled={
                                                            processing ||
                                                            !reconciliation.completion_ready
                                                        }
                                                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                                    >
                                                        Complete trip
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : null}

                                    {permissions.can_cancel &&
                                    (trip.status === 'pending' ||
                                        trip.status === 'in_progress') &&
                                    urls.cancel ? (
                                        <Form
                                            action={urls.cancel}
                                            method="post"
                                            className="grid max-w-xl gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <Label htmlFor="cancellation_remark">
                                                        Cancel trip (reason
                                                        required)
                                                    </Label>
                                                    <Input
                                                        id="cancellation_remark"
                                                        name="cancellation_remark"
                                                        required
                                                        maxLength={2000}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        Cancel trip
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : null}

                                    {permissions.can_delete &&
                                    trip.status === 'pending' &&
                                    urls.destroy ? (
                                        <Form
                                            action={urls.destroy}
                                            method="delete"
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    disabled={processing}
                                                >
                                                    Soft-delete pending trip
                                                </Button>
                                            )}
                                        </Form>
                                    ) : null}
                                </div>
                            </TripDetailCard>

                            <TripDetailCard title="Documents">
                                <div className="space-y-3 p-4">
                                    <p className="text-sm text-muted-foreground">
                                        Upload supporting trip files and
                                        optional POD from this screen. POD is
                                        not required to complete the trip.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild variant="secondary">
                                            <Link href={urls.lr_show}>
                                                Open LR
                                            </Link>
                                        </Button>
                                        {documentDrawer ? (
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                onClick={() =>
                                                    setDocsOpen(true)
                                                }
                                            >
                                                Manage trip documents
                                            </Button>
                                        ) : urls.documents ? (
                                            <Button asChild variant="secondary">
                                                <Link href={urls.documents}>
                                                    Trip documents
                                                </Link>
                                            </Button>
                                        ) : null}
                                        {urls.lr_documents ? (
                                            <Button asChild variant="secondary">
                                                <Link href={urls.lr_documents}>
                                                    LR / POD documents
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </div>
                                    {documentDrawer ? (
                                        <ul className="divide-y divide-border rounded-lg border border-border text-sm">
                                            {documentDrawer.attachments
                                                .length === 0 ? (
                                                <li className="px-3 py-2 text-muted-foreground">
                                                    No trip documents yet.
                                                </li>
                                            ) : (
                                                documentDrawer.attachments.map(
                                                    (attachment) => (
                                                        <li
                                                            key={attachment.id}
                                                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                                        >
                                                            <span>
                                                                {attachment.label ||
                                                                    attachment
                                                                        .file
                                                                        ?.original_name ||
                                                                    attachment.document_type ||
                                                                    'Attachment'}
                                                                {attachment.is_current
                                                                    ? ''
                                                                    : ' (history)'}
                                                            </span>
                                                            {attachment.download_url ? (
                                                                <a
                                                                    className="text-sm text-[#2A6FDB] underline"
                                                                    href={
                                                                        attachment.download_url
                                                                    }
                                                                >
                                                                    Download
                                                                </a>
                                                            ) : (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {attachment
                                                                        .file
                                                                        ?.scan_status ??
                                                                        'Unavailable'}
                                                                </span>
                                                            )}
                                                        </li>
                                                    ),
                                                )
                                            )}
                                        </ul>
                                    ) : null}
                                </div>
                            </TripDetailCard>
                        </>
                    }
                    side={
                        <>
                            <TripFinancialSummary
                                profit={profit}
                                distanceKm={trip.trip_distance}
                            />
                            <TripDieselCard
                                profit={profit}
                                dieselLitres={String(dieselLitresTotal)}
                                distanceKm={trip.trip_distance}
                            />
                            <TripDetailCard title="Record Info">
                                <TripInfoGrid>
                                    <TripInfoItem
                                        label="Created"
                                        value={trip.created_at}
                                    />
                                    <TripInfoItem
                                        label="Trip ID"
                                        value={trip.id}
                                        span2
                                    />
                                    <TripInfoItem
                                        label="Trips index"
                                        value={
                                            <Link
                                                href={urls.index}
                                                className="text-[#2A6FDB] underline"
                                            >
                                                Back to trips list
                                            </Link>
                                        }
                                        span2
                                    />
                                </TripInfoGrid>
                            </TripDetailCard>
                        </>
                    }
                />
            </TripDetailPage>
            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    title="Trip documents"
                    {...documentDrawer}
                />
            ) : null}
        </>
    );
}
