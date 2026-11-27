import { Form, Head, Link } from '@inertiajs/react';
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
    challan_number: string;
    challan_date: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    vehicle_registration: string | null;
    total_packages: string;
    total_weight_kg: string;
    to_pay_amount: string;
};

type Props = {
    eligibleChallans: Eligible[];
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
        stations_store?: string;
        vehicles_store?: string;
        vehicles_options?: string;
        parties_store?: string;
        parties_options?: string;
    };
};

export default function PartloadMainChallanCreate({
    eligibleChallans,
    stationOptions: initialStations,
    vehicleOptions,
    driverOptions,
    permissions,
    urls,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const [stations, setStations] = useState(initialStations);
    const [fromStationId, setFromStationId] = useState('');
    const [toStationId, setToStationId] = useState('');
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
        () => eligibleChallans.filter((row) => selected.includes(row.id)),
        [eligibleChallans, selected],
    );

    const toggle = (id: string) => {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    return (
        <>
            <Head title="Create Main Challan" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Create Main Challan"
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
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                    <div className="space-y-1">
                                        <Label htmlFor="main_challan_date">
                                            Date
                                        </Label>
                                        <Input
                                            id="main_challan_date"
                                            name="main_challan_date"
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
                                            onChange={(event) =>
                                                setFromStationId(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Select</option>
                                            {stations.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
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
                                            onChange={(event) =>
                                                setToStationId(
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Select</option>
                                            {stations.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
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
                                            required
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
                                        />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label required>Eligible Challans</Label>
                                    {errors.partload_challan_ids ? (
                                        <p className="text-sm text-destructive">
                                            {errors.partload_challan_ids}
                                        </p>
                                    ) : null}
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full min-w-[720px] text-left text-sm">
                                            <thead className="border-b bg-muted/40 text-muted-foreground">
                                                <tr>
                                                    <th className="px-3 py-2" />
                                                    <th className="px-3 py-2 font-medium">
                                                        Challan
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Route
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Vehicle
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Pkgs
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        To Pay
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {eligibleChallans.map((row) => (
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
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2 font-medium">
                                                            {row.challan_number}
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
                                                                row.vehicle_registration
                                                            }
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {row.total_packages}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {row.to_pay_amount}
                                                        </td>
                                                    </tr>
                                                ))}
                                                {eligibleChallans.length ===
                                                0 ? (
                                                    <tr>
                                                        <td
                                                            className="px-3 py-6 text-muted-foreground"
                                                            colSpan={6}
                                                        >
                                                            No Draft Challans
                                                            available for
                                                            consolidation.
                                                        </td>
                                                    </tr>
                                                ) : null}
                                            </tbody>
                                        </table>
                                    </div>
                                    {selected.map((id) => (
                                        <input
                                            key={id}
                                            type="hidden"
                                            name="partload_challan_ids[]"
                                            value={id}
                                        />
                                    ))}
                                    <p className="text-xs text-muted-foreground">
                                        Selected {selectedRows.length}{' '}
                                        challan(s).
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || selected.length === 0
                                        }
                                    >
                                        Create Main Challan
                                    </Button>
                                    <Button
                                        type="submit"
                                        name="dispatch"
                                        value="1"
                                        variant="outline"
                                        disabled={
                                            processing || selected.length === 0
                                        }
                                    >
                                        Create & Dispatch
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
