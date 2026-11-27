import { Form, Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import {
    QuickCreateStationSheet,
    type QuickCreatedStation,
} from '@/components/masters/quick-create-station-sheet';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import {
    SearchableVehicleSelect,
    type VehicleSelectOption,
} from '@/components/searchable-vehicle-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type StationOption = {
    id: string;
    name?: string | null;
    city?: string | null;
    state?: string | null;
};

type PartyOption = {
    id: string;
    name: string;
    city?: string | null;
    state?: string | null;
    contact_number?: string | null;
};

type Eligible = {
    id: string;
    lr_number: string | null;
    lr_date: string | null;
    billing_customer_name: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    freight_total: string;
    freight_payment_term: string | null;
};

type Props = {
    eligibleBilties: Eligible[];
    stationOptions: StationOption[];
    vehicleOptions: VehicleSelectOption[];
    driverOptions: PartyOption[];
    permissions?: {
        can_create_masters?: boolean;
        can_quick_create_stations?: boolean;
        allowed_vehicle_ownerships?: string[];
    };
    urls: {
        store: string;
        index: string;
        create?: string;
        stations_store?: string;
        vehicles_store?: string;
        vehicles_options?: string;
        parties_store?: string;
        parties_options?: string;
    };
};

export default function PartloadChallanCreate({
    eligibleBilties,
    stationOptions: initialStations,
    vehicleOptions,
    driverOptions,
    permissions,
    urls,
    filters,
}: Props & {
    filters?: {
        from_station_id?: string | null;
        to_station_id?: string | null;
    };
}) {
    const [selected, setSelected] = useState<string[]>([]);
    const [stations, setStations] = useState(initialStations);
    const [fromStationId, setFromStationId] = useState(
        filters?.from_station_id ?? '',
    );
    const [toStationId, setToStationId] = useState(
        filters?.to_station_id ?? '',
    );
    const [vehicleId, setVehicleId] = useState('');
    const [driverPartyId, setDriverPartyId] = useState('');
    const [stationQuickCreateTarget, setStationQuickCreateTarget] = useState<
        'from' | 'to' | null
    >(null);

    const canQuickCreateStations =
        permissions?.can_quick_create_stations === true &&
        Boolean(urls.stations_store);
    const canCreateMasters = permissions?.can_create_masters === true;

    const selectedRows = useMemo(
        () => eligibleBilties.filter((row) => selected.includes(row.id)),
        [eligibleBilties, selected],
    );

    const reloadEligible = (fromId: string, toId: string) => {
        setSelected([]);
        router.get(
            urls.create ?? '/partload/challans/create',
            {
                from_station_id: fromId || undefined,
                to_station_id: toId || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['eligibleBilties'],
            },
        );
    };

    const toggle = (id: string) => {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    return (
        <>
            <Head title="Create Partload Challan" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Create Challan"
                    toolbar={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.index}>Back</Link>
                        </Button>
                    }
                >
                    <Form
                        action={urls.store}
                        method="post"
                        className="space-y-4 p-4"
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                    <div className="space-y-1">
                                        <Label htmlFor="challan_date">
                                            Date
                                        </Label>
                                        <Input
                                            id="challan_date"
                                            name="challan_date"
                                            type="date"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <div className="flex items-center justify-between gap-2">
                                            <Label
                                                htmlFor="from_station_id"
                                                required
                                            >
                                                From Station
                                            </Label>
                                            {canQuickCreateStations ? (
                                                <button
                                                    type="button"
                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                    onClick={() =>
                                                        setStationQuickCreateTarget(
                                                            'from',
                                                        )
                                                    }
                                                >
                                                    + Add
                                                </button>
                                            ) : null}
                                        </div>
                                        <select
                                            id="from_station_id"
                                            name="from_station_id"
                                            required
                                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                            value={fromStationId}
                                            onChange={(event) => {
                                                const next = event.target.value;
                                                setFromStationId(next);
                                                reloadEligible(
                                                    next,
                                                    toStationId,
                                                );
                                            }}
                                        >
                                            <option value="">Select</option>
                                            {stations.map((station) => (
                                                <option
                                                    key={station.id}
                                                    value={station.id}
                                                >
                                                    {station.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.from_station_id ? (
                                            <p className="text-sm text-destructive">
                                                {errors.from_station_id}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <div className="flex items-center justify-between gap-2">
                                            <Label
                                                htmlFor="to_station_id"
                                                required
                                            >
                                                To Station
                                            </Label>
                                            {canQuickCreateStations ? (
                                                <button
                                                    type="button"
                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                    onClick={() =>
                                                        setStationQuickCreateTarget(
                                                            'to',
                                                        )
                                                    }
                                                >
                                                    + Add
                                                </button>
                                            ) : null}
                                        </div>
                                        <select
                                            id="to_station_id"
                                            name="to_station_id"
                                            required
                                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                            value={toStationId}
                                            onChange={(event) => {
                                                const next = event.target.value;
                                                setToStationId(next);
                                                reloadEligible(
                                                    fromStationId,
                                                    next,
                                                );
                                            }}
                                        >
                                            <option value="">Select</option>
                                            {stations.map((station) => (
                                                <option
                                                    key={station.id}
                                                    value={station.id}
                                                >
                                                    {station.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.to_station_id ? (
                                            <p className="text-sm text-destructive">
                                                {errors.to_station_id}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1 lg:col-span-2">
                                        <SearchableVehicleSelect
                                            id="vehicle_id"
                                            name="vehicle_id"
                                            label="Vehicle"
                                            options={vehicleOptions}
                                            searchUrl={urls.vehicles_options}
                                            value={vehicleId}
                                            onValueChange={(next, option) => {
                                                setVehicleId(next);
                                                if (
                                                    option?.primary_driver_party_id &&
                                                    driverPartyId === ''
                                                ) {
                                                    setDriverPartyId(
                                                        option.primary_driver_party_id,
                                                    );
                                                }
                                            }}
                                            required
                                            createStoreUrl={
                                                canCreateMasters
                                                    ? urls.vehicles_store
                                                    : undefined
                                            }
                                            partiesOptionsUrl={
                                                urls.parties_options
                                            }
                                            partiesStoreUrl={
                                                canCreateMasters
                                                    ? urls.parties_store
                                                    : undefined
                                            }
                                            allowOwnQuickCreate={
                                                canCreateMasters &&
                                                (permissions?.allowed_vehicle_ownerships?.includes(
                                                    'own',
                                                ) ??
                                                    false)
                                            }
                                            allowMarketQuickCreate={
                                                canCreateMasters &&
                                                (permissions?.allowed_vehicle_ownerships?.includes(
                                                    'market',
                                                ) ??
                                                    true)
                                            }
                                            emptyMessage="No vehicles in this branch."
                                            error={errors.vehicle_id}
                                        />
                                    </div>
                                    <div className="space-y-1 lg:col-span-2">
                                        <SearchablePartySelect
                                            id="driver_party_id"
                                            name="driver_party_id"
                                            label="Driver"
                                            options={driverOptions}
                                            searchUrl={urls.parties_options}
                                            role="driver"
                                            value={driverPartyId}
                                            onValueChange={setDriverPartyId}
                                            createStoreUrl={
                                                canCreateMasters
                                                    ? urls.parties_store
                                                    : undefined
                                            }
                                            createRole="driver"
                                            emptyMessage="No drivers found."
                                            error={errors.driver_party_id}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="truck_freight">
                                            Truck Freight
                                        </Label>
                                        <Input
                                            id="truck_freight"
                                            name="truck_freight"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue="0"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="labour_charge">
                                            Labour
                                        </Label>
                                        <Input
                                            id="labour_charge"
                                            name="labour_charge"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue="0"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="kat">Kat</Label>
                                        <Input
                                            id="kat"
                                            name="kat"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue="0"
                                        />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label required>
                                        Eligible Bilties ({selectedRows.length}{' '}
                                        selected)
                                    </Label>
                                    {errors.lorry_receipt_ids ? (
                                        <p className="text-sm text-destructive">
                                            {errors.lorry_receipt_ids}
                                        </p>
                                    ) : null}
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full min-w-[720px] text-left text-sm">
                                            <thead className="border-b bg-muted/40 text-muted-foreground">
                                                <tr>
                                                    <th className="px-3 py-2" />
                                                    <th className="px-3 py-2 font-medium">
                                                        Bilty
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Customer
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Route
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Freight
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Term
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {eligibleBilties.length ===
                                                0 ? (
                                                    <tr>
                                                        <td
                                                            colSpan={6}
                                                            className="px-3 py-6 text-center text-muted-foreground"
                                                        >
                                                            No eligible Pending
                                                            Partload Bilties.
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    eligibleBilties.map(
                                                        (row) => (
                                                            <tr
                                                                key={row.id}
                                                                className="border-b last:border-0"
                                                            >
                                                                <td className="px-3 py-2">
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={selected.includes(
                                                                            row.id,
                                                                        )}
                                                                        onChange={() =>
                                                                            toggle(
                                                                                row.id,
                                                                            )
                                                                        }
                                                                        aria-label={`Select ${row.lr_number}`}
                                                                    />
                                                                    {selected.includes(
                                                                        row.id,
                                                                    ) ? (
                                                                        <input
                                                                            type="hidden"
                                                                            name="lorry_receipt_ids[]"
                                                                            value={
                                                                                row.id
                                                                            }
                                                                        />
                                                                    ) : null}
                                                                </td>
                                                                <td className="px-3 py-2 font-medium">
                                                                    {
                                                                        row.lr_number
                                                                    }
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {
                                                                        row.billing_customer_name
                                                                    }
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {
                                                                        row.from_station_name
                                                                    }{' '}
                                                                    →{' '}
                                                                    {
                                                                        row.to_station_name
                                                                    }
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {
                                                                        row.freight_total
                                                                    }
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {row.freight_payment_term ??
                                                                        '—'}
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || selected.length === 0
                                        }
                                    >
                                        Save Draft
                                    </Button>
                                    <Button
                                        type="submit"
                                        name="dispatch"
                                        value="1"
                                        variant="secondary"
                                        disabled={
                                            processing || selected.length === 0
                                        }
                                    >
                                        Save & Dispatch
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>

            {stationQuickCreateTarget && urls.stations_store ? (
                <QuickCreateStationSheet
                    open={stationQuickCreateTarget !== null}
                    onOpenChange={(next) => {
                        if (!next) {
                            setStationQuickCreateTarget(null);
                        }
                    }}
                    storeUrl={urls.stations_store}
                    onCreated={(station: QuickCreatedStation) => {
                        setStations((current) =>
                            current.some((row) => row.id === station.id)
                                ? current
                                : [
                                      ...current,
                                      {
                                          id: station.id,
                                          name: station.name,
                                      },
                                  ],
                        );
                        if (stationQuickCreateTarget === 'from') {
                            setFromStationId(station.id);
                        } else {
                            setToStationId(station.id);
                        }
                        setStationQuickCreateTarget(null);
                    }}
                />
            ) : null}
        </>
    );
}
