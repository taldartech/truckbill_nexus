import { Form, Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';

type Option = { id: string; name?: string | null; registration_number?: string | null; code?: string | null };
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
    stationOptions: Option[];
    vehicleOptions: Option[];
    driverOptions: Option[];
    urls: { store: string; index: string };
};

export default function PartloadChallanCreate({
    eligibleBilties,
    stationOptions,
    vehicleOptions,
    driverOptions,
    urls,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);

    const selectedRows = useMemo(
        () => eligibleBilties.filter((row) => selected.includes(row.id)),
        [eligibleBilties, selected],
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
                                        <Label htmlFor="challan_date">Date</Label>
                                        <Input id="challan_date" name="challan_date" type="date" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="from_station_id" required>
                                            From Station
                                        </Label>
                                        <select
                                            id="from_station_id"
                                            name="from_station_id"
                                            required
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="">Select</option>
                                            {stationOptions.map((station) => (
                                                <option key={station.id} value={station.id}>
                                                    {station.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.from_station_id ? (
                                            <p className="text-sm text-destructive">{errors.from_station_id}</p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="to_station_id" required>
                                            To Station
                                        </Label>
                                        <select
                                            id="to_station_id"
                                            name="to_station_id"
                                            required
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="">Select</option>
                                            {stationOptions.map((station) => (
                                                <option key={station.id} value={station.id}>
                                                    {station.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.to_station_id ? (
                                            <p className="text-sm text-destructive">{errors.to_station_id}</p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="vehicle_id" required>
                                            Vehicle
                                        </Label>
                                        <select
                                            id="vehicle_id"
                                            name="vehicle_id"
                                            required
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="">Select</option>
                                            {vehicleOptions.map((vehicle) => (
                                                <option key={vehicle.id} value={vehicle.id}>
                                                    {vehicle.registration_number ?? vehicle.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.vehicle_id ? (
                                            <p className="text-sm text-destructive">{errors.vehicle_id}</p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="driver_party_id">Driver</Label>
                                        <select
                                            id="driver_party_id"
                                            name="driver_party_id"
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            <option value="">Vehicle primary / select</option>
                                            {driverOptions.map((driver) => (
                                                <option key={driver.id} value={driver.id}>
                                                    {driver.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="truck_freight">Truck Freight</Label>
                                        <Input id="truck_freight" name="truck_freight" type="number" step="0.01" min="0" defaultValue="0" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="labour_charge">Labour</Label>
                                        <Input id="labour_charge" name="labour_charge" type="number" step="0.01" min="0" defaultValue="0" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="kat">Kat</Label>
                                        <Input id="kat" name="kat" type="number" step="0.01" min="0" defaultValue="0" />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label required>Eligible Bilties ({selectedRows.length} selected)</Label>
                                    {errors.lorry_receipt_ids ? (
                                        <p className="text-sm text-destructive">{errors.lorry_receipt_ids}</p>
                                    ) : null}
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full min-w-[720px] text-left text-sm">
                                            <thead className="border-b bg-muted/40 text-muted-foreground">
                                                <tr>
                                                    <th className="px-3 py-2" />
                                                    <th className="px-3 py-2 font-medium">Bilty</th>
                                                    <th className="px-3 py-2 font-medium">Customer</th>
                                                    <th className="px-3 py-2 font-medium">Route</th>
                                                    <th className="px-3 py-2 font-medium">Freight</th>
                                                    <th className="px-3 py-2 font-medium">Term</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {eligibleBilties.length === 0 ? (
                                                    <tr>
                                                        <td colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                                                            No eligible Pending Partload Bilties.
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    eligibleBilties.map((row) => (
                                                        <tr key={row.id} className="border-b last:border-0">
                                                            <td className="px-3 py-2">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={selected.includes(row.id)}
                                                                    onChange={() => toggle(row.id)}
                                                                    aria-label={`Select ${row.lr_number}`}
                                                                />
                                                                {selected.includes(row.id) ? (
                                                                    <input type="hidden" name="lorry_receipt_ids[]" value={row.id} />
                                                                ) : null}
                                                            </td>
                                                            <td className="px-3 py-2 font-medium">{row.lr_number}</td>
                                                            <td className="px-3 py-2">{row.billing_customer_name}</td>
                                                            <td className="px-3 py-2">
                                                                {row.from_station_name} → {row.to_station_name}
                                                            </td>
                                                            <td className="px-3 py-2">{row.freight_total}</td>
                                                            <td className="px-3 py-2">{row.freight_payment_term ?? '—'}</td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" disabled={processing || selected.length === 0}>
                                        Save Draft
                                    </Button>
                                    <Button
                                        type="submit"
                                        name="dispatch"
                                        value="1"
                                        variant="secondary"
                                        disabled={processing || selected.length === 0}
                                    >
                                        Save & Dispatch
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
