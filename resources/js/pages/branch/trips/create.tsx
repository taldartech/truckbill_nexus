import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { ArrowLeft } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { formatDisplayDate } from '@/lib/dates';
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
        vehicle_id: string | null;
        vehicle_registration: string | null;
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
    const vehicleId = lorryReceipt.vehicle_id ?? vehicles[0]?.id ?? '';
    const [useSubstitute, setUseSubstitute] = useState(false);
    const selectedVehicle = useMemo(
        () => vehicles.find((vehicle) => vehicle.id === vehicleId) ?? null,
        [vehicleId, vehicles],
    );

    return (
        <>
            <Head title={`Create Trip · ${lorryReceipt.lr_number}`} />
            <PageShell
                width="form"
                title="Create OwnFleet Trip"
                description={`From LR ${lorryReceipt.lr_number}`}
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to LR"
                        href={urls.lr_show}
                        variant="outline"
                    />
                }
            >
                <FormSection title="Source LR" columns={2}>
                    <p className="font-medium text-foreground md:col-span-2">
                        LR {lorryReceipt.lr_number} ·{' '}
                        {formatDisplayDate(lorryReceipt.lr_date)}
                    </p>
                    <p className="text-muted-foreground md:col-span-2">
                        {[
                            lorryReceipt.from_station_name,
                            lorryReceipt.to_station_name,
                        ]
                            .filter(Boolean)
                            .join(' → ') || 'Route not set'}
                    </p>
                    <p className="text-muted-foreground">
                        Freight ₹{lorryReceipt.freight_total}
                    </p>
                </FormSection>

                <Form
                    action={urls.store}
                    method="post"
                    className="grid w-full gap-4"
                >
                    {({ processing }) => (
                        <>
                            <FormSection title="Vehicle & drivers" columns={2}>
                                <div className="grid gap-2 md:col-span-2">
                                    <Label htmlFor="vehicle_display">
                                        Vehicle (from LR)
                                    </Label>
                                    <input
                                        type="hidden"
                                        name="vehicle_id"
                                        value={vehicleId}
                                    />
                                    <Input
                                        id="vehicle_display"
                                        readOnly
                                        value={
                                            lorryReceipt.vehicle_registration ??
                                            selectedVehicle?.registration_number ??
                                            'No Own vehicle on this LR'
                                        }
                                        className="bg-muted"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Trip must use the Own vehicle already
                                        assigned on the Lorry Receipt.
                                    </p>
                                    <InputError message={errors?.vehicle_id} />
                                </div>

                                {selectedVehicle?.primary_driver_name ? (
                                    <p className="text-sm text-muted-foreground">
                                        Primary driver:{' '}
                                        <span className="text-foreground">
                                            {
                                                selectedVehicle.primary_driver_name
                                            }
                                        </span>
                                    </p>
                                ) : (
                                    <p className="text-sm text-amber-700">
                                        This vehicle has no primary driver. Use
                                        a substitute driver for this Trip.
                                    </p>
                                )}

                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="use_substitute_driver"
                                        value="1"
                                        checked={useSubstitute}
                                        onChange={(event) =>
                                            setUseSubstitute(
                                                event.target.checked,
                                            )
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
                                    <InputError
                                        message={errors?.expected_end_at}
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2 md:col-span-2">
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={
                                            processing || vehicles.length === 0
                                        }
                                    >
                                        Create Trip
                                    </Button>
                                    <Button asChild variant="secondary">
                                        <Link href={urls.lr_show}>Cancel</Link>
                                    </Button>
                                </div>
                            </FormSection>
                        </>
                    )}
                </Form>
            </PageShell>
        </>
    );
}
