import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { SearchablePartySelect } from '@/components/searchable-party-select';
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
        parties_options?: string;
        parties_create?: string;
        parties_store?: string;
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
            <LrfPage
                title="Create OwnFleet Trip"
                subtitle={`From Bilty ${lorryReceipt.lr_number}`}
                backHref={urls.lr_show}
                backLabel="Bilty"
            >
                <LrfSection
                    step={1}
                    title="Source Bilty"
                    description="Trip inherits the Own vehicle and route from this LR."
                    tone="receipt"
                >
                    <div className="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <p>
                            <span className="font-semibold text-slate-700">
                                LR:
                            </span>{' '}
                            {lorryReceipt.lr_number}
                        </p>
                        <p>
                            <span className="font-semibold text-slate-700">
                                Date:
                            </span>{' '}
                            {formatDisplayDate(lorryReceipt.lr_date)}
                        </p>
                        <p>
                            <span className="font-semibold text-slate-700">
                                Route:
                            </span>{' '}
                            {[
                                lorryReceipt.from_station_name,
                                lorryReceipt.to_station_name,
                            ]
                                .filter(Boolean)
                                .join(' → ') || '—'}
                        </p>
                        <p>
                            <span className="font-semibold text-slate-700">
                                Freight:
                            </span>{' '}
                            ₹{lorryReceipt.freight_total}
                        </p>
                    </div>
                </LrfSection>

                <Form
                    action={urls.store}
                    method="post"
                    className="grid w-full gap-3"
                >
                    {({ processing }) => (
                        <LrfSection
                            step={2}
                            title="Vehicle & drivers"
                            description="Confirm Own vehicle and optional substitute driver."
                            tone="route"
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2 sm:col-span-2">
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
                                    <p className="text-sm text-muted-foreground sm:col-span-2">
                                        Primary driver:{' '}
                                        <span className="font-medium text-foreground">
                                            {
                                                selectedVehicle.primary_driver_name
                                            }
                                        </span>
                                    </p>
                                ) : (
                                    <p className="text-sm text-amber-700 sm:col-span-2">
                                        This vehicle has no primary driver. Use
                                        a substitute driver for this Trip.
                                    </p>
                                )}

                                <label className="flex items-center gap-2 text-sm sm:col-span-2">
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
                                    <div className="sm:col-span-2">
                                        <SearchablePartySelect
                                            id="substitute_driver_party_id"
                                            name="substitute_driver_party_id"
                                            label="Substitute driver"
                                            options={drivers}
                                            searchUrl={urls.parties_options}
                                            role="driver"
                                            createHref={urls.parties_create}
                                            createLabel="Create Driver"
                                            required
                                            emptyMessage="No eligible drivers in this branch."
                                            error={
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

                                <div className="flex flex-wrap gap-2 sm:col-span-2">
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
                            </div>
                        </LrfSection>
                    )}
                </Form>
            </LrfPage>
        </>
    );
}
