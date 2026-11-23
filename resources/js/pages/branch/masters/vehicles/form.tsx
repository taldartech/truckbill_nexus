import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Vehicle = {
    id: string;
    registration_number: string;
    vehicle_type: string | null;
    make: string | null;
    model: string | null;
    manufacture_year: number | null;
    chassis_number: string | null;
    engine_number: string | null;
    capacity_mt: string | number | null;
    is_active: boolean;
    ownership: string | null;
    truck_type_id: string | null;
    tyre_layout_id: string | null;
    owner_party_id: string | null;
    primary_driver_party_id: string | null;
};

type Option = { id: string; name: string };
type OwnershipOption = { value: string; label: string };

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    mode: 'create' | 'edit';
    vehicle: Vehicle | null;
    formOptions: {
        ownershipOptions: OwnershipOption[];
        truckTypes: Option[];
        tyreLayouts: Option[];
        transporters: Option[];
        drivers: Option[];
    };
    links: {
        hub: string;
        dashboard: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

export default function VehicleForm({
    mode,
    vehicle,
    formOptions,
    links,
}: Props) {
    const action = mode === 'create' ? links.store! : links.update!;
    const [ownership, setOwnership] = useState(
        vehicle?.ownership ?? formOptions.ownershipOptions[0]?.value ?? 'own',
    );

    return (
        <>
            <Head
                title={
                    mode === 'create'
                        ? 'Create vehicle'
                        : `Edit ${vehicle?.registration_number}`
                }
            />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title={
                        mode === 'create'
                            ? 'Create vehicle'
                            : `Edit ${vehicle?.registration_number}`
                    }
                />
                <Form
                    action={action}
                    method={mode === 'create' ? 'post' : 'patch'}
                    className="mx-auto grid w-full max-w-3xl gap-4 md:max-w-5xl"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="registration_number">
                                        Registration number
                                    </Label>
                                    <Input
                                        id="registration_number"
                                        name="registration_number"
                                        defaultValue={
                                            vehicle?.registration_number ?? ''
                                        }
                                        required
                                    />
                                    <InputError
                                        message={errors.registration_number}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="ownership">Ownership</Label>
                                    <select
                                        id="ownership"
                                        name="ownership"
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        value={ownership}
                                        onChange={(e) =>
                                            setOwnership(e.target.value)
                                        }
                                        required
                                    >
                                        {formOptions.ownershipOptions.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <InputError message={errors.ownership} />
                                </div>
                                {ownership === 'own' ? (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="truck_type_id">
                                                Truck Type
                                            </Label>
                                            <select
                                                id="truck_type_id"
                                                name="truck_type_id"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue={
                                                    vehicle?.truck_type_id ?? ''
                                                }
                                                required
                                            >
                                                <option value="">
                                                    Select…
                                                </option>
                                                {formOptions.truckTypes.map(
                                                    (item) => (
                                                        <option
                                                            key={item.id}
                                                            value={item.id}
                                                        >
                                                            {item.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={errors.truck_type_id}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="tyre_layout_id">
                                                Tyre Layout
                                            </Label>
                                            <select
                                                id="tyre_layout_id"
                                                name="tyre_layout_id"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                defaultValue={
                                                    vehicle?.tyre_layout_id ??
                                                    ''
                                                }
                                                required
                                            >
                                                <option value="">
                                                    Select…
                                                </option>
                                                {formOptions.tyreLayouts.map(
                                                    (item) => (
                                                        <option
                                                            key={item.id}
                                                            value={item.id}
                                                        >
                                                            {item.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={errors.tyre_layout_id}
                                            />
                                        </div>
                                    </>
                                ) : (
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="owner_party_id">
                                            Vehicle Owner (Transporter)
                                        </Label>
                                        <select
                                            id="owner_party_id"
                                            name="owner_party_id"
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            defaultValue={
                                                vehicle?.owner_party_id ?? ''
                                            }
                                            required
                                        >
                                            <option value="">Select…</option>
                                            {formOptions.transporters.map(
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
                                        <InputError
                                            message={errors.owner_party_id}
                                        />
                                    </div>
                                )}
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="primary_driver_party_id">
                                        Primary Driver
                                    </Label>
                                    <select
                                        id="primary_driver_party_id"
                                        name="primary_driver_party_id"
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        defaultValue={
                                            vehicle?.primary_driver_party_id ??
                                            ''
                                        }
                                    >
                                        <option value="">None</option>
                                        {formOptions.drivers.map((party) => (
                                            <option
                                                key={party.id}
                                                value={party.id}
                                            >
                                                {party.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.primary_driver_party_id}
                                    />
                                </div>
                                {[
                                    [
                                        'vehicle_type',
                                        'Vehicle type',
                                        vehicle?.vehicle_type ?? '',
                                    ],
                                    ['make', 'Make', vehicle?.make ?? ''],
                                    ['model', 'Model', vehicle?.model ?? ''],
                                    [
                                        'manufacture_year',
                                        'Manufacture year',
                                        vehicle?.manufacture_year?.toString() ??
                                            '',
                                    ],
                                    [
                                        'chassis_number',
                                        'Chassis number',
                                        vehicle?.chassis_number ?? '',
                                    ],
                                    [
                                        'engine_number',
                                        'Engine number',
                                        vehicle?.engine_number ?? '',
                                    ],
                                    [
                                        'capacity_mt',
                                        'Capacity (MT)',
                                        vehicle?.capacity_mt?.toString() ?? '',
                                    ],
                                ].map(([name, label, value]) => (
                                    <div key={name} className="space-y-2">
                                        <Label htmlFor={name}>{label}</Label>
                                        <Input
                                            id={name}
                                            name={name}
                                            defaultValue={value}
                                        />
                                        <InputError message={errors[name]} />
                                    </div>
                                ))}
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    defaultChecked={vehicle?.is_active ?? true}
                                />
                                Active
                            </label>
                            <div className="space-y-2">
                                <Label htmlFor="remark">Remark</Label>
                                <Input
                                    id="remark"
                                    name="remark"
                                    required={mode === 'edit'}
                                    defaultValue={
                                        mode === 'create'
                                            ? 'Create vehicle'
                                            : ''
                                    }
                                />
                                <InputError message={errors.remark} />
                            </div>
                            <div className="flex gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : 'Save'}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={links.show ?? links.index}>
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
