import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type VehicleOption = {
    id: string;
    registration_number: string;
    primary_driver_party_id: string | null;
    primary_driver_name: string | null;
};

type DriverOption = {
    id: string;
    name: string;
};

type Props = {
    lorryReceipt: {
        id: string;
        lr_number: string;
        lr_date: string | null;
        from_station_name: string | null;
        to_station_name: string | null;
        consignor_name: string | null;
        consignee_name: string | null;
        freight_total: string;
    };
    vehicles: VehicleOption[];
    drivers: DriverOption[];
    urls: {
        store: string;
        lr_show: string;
        index: string;
    };
};

export default function TripCreate({
    lorryReceipt,
    vehicles,
    drivers,
    urls,
}: Props) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const [vehicleId, setVehicleId] = useState(vehicles[0]?.id ?? '');
    const [useSubstitute, setUseSubstitute] = useState(false);
    const selectedVehicle = useMemo(
        () => vehicles.find((vehicle) => vehicle.id === vehicleId) ?? null,
        [vehicleId, vehicles],
    );

    return (
        <>
            <Head title={`Create Trip · ${lorryReceipt.lr_number}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <Link
                        href={urls.lr_show}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← LR {lorryReceipt.lr_number}
                    </Link>
                    <MasterPageHeader title="Create OwnFleet Trip" />
                </div>

                <div className="rounded-lg border border-border bg-muted/20 p-4 text-sm">
                    <p className="font-medium text-foreground">
                        LR {lorryReceipt.lr_number}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        {[
                            lorryReceipt.from_station_name,
                            lorryReceipt.to_station_name,
                        ]
                            .filter(Boolean)
                            .join(' → ') || 'Route not set'}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Freight ₹{lorryReceipt.freight_total}
                    </p>
                </div>

                <Form
                    action={urls.store}
                    method="post"
                    className="mx-auto grid w-full max-w-2xl gap-4"
                >
                    {({ processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="vehicle_id">Vehicle</Label>
                                <select
                                    id="vehicle_id"
                                    name="vehicle_id"
                                    required
                                    value={vehicleId}
                                    onChange={(event) =>
                                        setVehicleId(event.target.value)
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="" disabled>
                                        Select vehicle
                                    </option>
                                    {vehicles.map((vehicle) => (
                                        <option
                                            key={vehicle.id}
                                            value={vehicle.id}
                                        >
                                            {vehicle.registration_number}
                                            {vehicle.primary_driver_name
                                                ? ` · ${vehicle.primary_driver_name}`
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors?.vehicle_id} />
                            </div>

                            {selectedVehicle?.primary_driver_name ? (
                                <p className="text-sm text-muted-foreground">
                                    Primary driver:{' '}
                                    <span className="text-foreground">
                                        {selectedVehicle.primary_driver_name}
                                    </span>
                                </p>
                            ) : (
                                <p className="text-sm text-amber-700">
                                    This vehicle has no primary driver. Use a
                                    substitute driver for this Trip.
                                </p>
                            )}

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="use_substitute_driver"
                                    value="1"
                                    checked={useSubstitute}
                                    onChange={(event) =>
                                        setUseSubstitute(event.target.checked)
                                    }
                                />
                                Use substitute driver for this Trip only
                            </label>

                            {useSubstitute ? (
                                <div className="grid gap-2">
                                    <Label htmlFor="substitute_driver_party_id">
                                        Substitute driver
                                    </Label>
                                    <select
                                        id="substitute_driver_party_id"
                                        name="substitute_driver_party_id"
                                        required
                                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Select driver
                                        </option>
                                        {drivers.map((driver) => (
                                            <option
                                                key={driver.id}
                                                value={driver.id}
                                            >
                                                {driver.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={
                                            errors?.substitute_driver_party_id
                                        }
                                    />
                                </div>
                            ) : null}

                            <div className="grid gap-2">
                                <Label htmlFor="expected_end_at">
                                    Expected end (optional)
                                </Label>
                                <Input
                                    id="expected_end_at"
                                    name="expected_end_at"
                                    type="datetime-local"
                                />
                                <InputError message={errors?.expected_end_at} />
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || vehicles.length === 0
                                    }
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    Create Trip
                                </Button>
                                <Button asChild variant="secondary">
                                    <Link href={urls.lr_show}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
