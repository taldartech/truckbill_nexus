import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { QuickCreateCatalogueSheet } from '@/components/masters/quick-create-catalogue-sheet';
import { QuickCreateTyreLayoutSheet } from '@/components/masters/quick-create-tyre-layout-sheet';
import {
    SearchablePartySelect,
    type PartyOption,
} from '@/components/searchable-party-select';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Vehicle = {
    id: string;
    registration_number: string;
    registration_date: string | null;
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

/** Technical specifications captured only for Own (OwnFleet) vehicles. */
const OWN_FLEET_FIELDS = [
    ['vehicle_type', 'Vehicle type'],
    ['make', 'Make'],
    ['model', 'Model'],
    ['manufacture_year', 'Manufacture year'],
    ['chassis_number', 'Chassis number'],
    ['engine_number', 'Engine number'],
    ['capacity_mt', 'Capacity (MT)'],
] as const satisfies ReadonlyArray<readonly [keyof Vehicle, string]>;
type OwnershipOption = { value: string; label: string };

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    mode: 'create' | 'edit';
    vehicle: Vehicle | null;
    driversEnabled?: boolean;
    formOptions: {
        ownershipOptions: OwnershipOption[];
        truckTypesAvailable: boolean;
        truckTypes: Option[];
        tyreLayouts: Option[];
        transporters: PartyOption[];
        drivers: PartyOption[];
    };
    links: {
        hub: string;
        dashboard: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
        truckTypesStoreUrl?: string | null;
        tyreLayoutsStoreUrl?: string | null;
        partiesOptionsUrl?: string;
        partiesStoreUrl?: string;
    };
};

export default function VehicleForm({
    mode,
    vehicle,
    driversEnabled = true,
    formOptions,
    links,
}: Props) {
    const action = mode === 'create' ? links.store! : links.update!;
    const singleOwnership =
        formOptions.ownershipOptions.length === 1
            ? formOptions.ownershipOptions[0]
            : null;
    const [ownership, setOwnership] = useState(
        singleOwnership?.value ??
            vehicle?.ownership ??
            formOptions.ownershipOptions[0]?.value ??
            'own',
    );
    const [truckTypes, setTruckTypes] = useState(formOptions.truckTypes);
    const [tyreLayouts, setTyreLayouts] = useState(formOptions.tyreLayouts);
    const [truckTypeId, setTruckTypeId] = useState(
        vehicle?.truck_type_id ?? '',
    );
    const [tyreLayoutId, setTyreLayoutId] = useState(
        vehicle?.tyre_layout_id ?? '',
    );
    const [ownerPartyId, setOwnerPartyId] = useState(
        vehicle?.owner_party_id ?? '',
    );
    const [primaryDriverPartyId, setPrimaryDriverPartyId] = useState(
        vehicle?.primary_driver_party_id ?? '',
    );
    const [quickCreate, setQuickCreate] = useState<'truck' | 'tyre' | null>(
        null,
    );
    const isOwn = ownership === 'own';
    const isMarket = ownership === 'market';

    return (
        <>
            <Head
                title={
                    mode === 'create'
                        ? 'Create vehicle'
                        : `Edit ${vehicle?.registration_number}`
                }
            />
            <LrfPage
                title={
                    mode === 'create'
                        ? 'Create vehicle'
                        : `Edit ${vehicle?.registration_number}`
                }
                subtitle="Market vehicles need only the Truck Number; Own vehicles also carry fleet specifications."
                backHref={links.index}
                backLabel="Vehicles"
            >
                <Form
                    action={action}
                    method={mode === 'create' ? 'post' : 'patch'}
                    className="space-y-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <LrfSection
                                step={1}
                                title="Registration & ownership"
                                description="Fleet identity, ownership, and specifications."
                                tone="receipt"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label
                                            htmlFor="registration_number"
                                            required
                                        >
                                            Truck Number
                                        </Label>
                                        <Input
                                            id="registration_number"
                                            name="registration_number"
                                            defaultValue={
                                                vehicle?.registration_number ??
                                                ''
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.registration_number}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="ownership" required>
                                            Ownership
                                        </Label>
                                        {singleOwnership ? (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="ownership"
                                                    value={
                                                        singleOwnership.value
                                                    }
                                                />
                                                <div
                                                    id="ownership"
                                                    className="flex h-9 w-full items-center rounded-md border border-input bg-muted/40 px-3 text-sm font-medium text-foreground"
                                                >
                                                    {singleOwnership.label}
                                                </div>
                                            </>
                                        ) : (
                                            <select
                                                id="ownership"
                                                name="ownership"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={ownership}
                                                onChange={(e) => {
                                                    setOwnership(
                                                        e.target.value,
                                                    );
                                                    setOwnerPartyId('');
                                                    setTruckTypeId('');
                                                    setTyreLayoutId('');
                                                    setPrimaryDriverPartyId('');
                                                }}
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
                                        )}
                                        <InputError
                                            message={errors.ownership}
                                        />
                                    </div>
                                    {isMarket ? (
                                        <div className="space-y-2 md:col-span-2">
                                            <SearchablePartySelect
                                                id="owner_party_id"
                                                name="owner_party_id"
                                                label="Truck Owner"
                                                options={
                                                    formOptions.transporters
                                                }
                                                searchUrl={
                                                    links.partiesOptionsUrl
                                                }
                                                role="transporter"
                                                createStoreUrl={
                                                    links.partiesStoreUrl
                                                }
                                                createLabel="Add"
                                                createRole="transporter"
                                                value={ownerPartyId}
                                                onValueChange={setOwnerPartyId}
                                                emptyMessage="Search Truck Owners (Transporters)…"
                                                error={errors.owner_party_id}
                                            />
                                        </div>
                                    ) : null}
                                    {isOwn ||
                                    formOptions.truckTypesAvailable ? (
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between gap-2">
                                                <Label
                                                    htmlFor="truck_type_id"
                                                    required={isOwn}
                                                >
                                                    Truck Type
                                                </Label>
                                                {links.truckTypesStoreUrl ? (
                                                    <button
                                                        type="button"
                                                        className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                        onClick={() =>
                                                            setQuickCreate(
                                                                'truck',
                                                            )
                                                        }
                                                    >
                                                        + Add
                                                    </button>
                                                ) : null}
                                            </div>
                                            <select
                                                id="truck_type_id"
                                                name="truck_type_id"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={truckTypeId}
                                                onChange={(event) =>
                                                    setTruckTypeId(
                                                        event.target.value,
                                                    )
                                                }
                                                required={isOwn}
                                            >
                                                <option value="">
                                                    Select…
                                                </option>
                                                {truckTypes.map((item) => (
                                                    <option
                                                        key={item.id}
                                                        value={item.id}
                                                    >
                                                        {item.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.truck_type_id}
                                            />
                                        </div>
                                    ) : null}
                                    {isOwn ? (
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between gap-2">
                                                <Label
                                                    htmlFor="tyre_layout_id"
                                                    required
                                                >
                                                    Tyre Layout
                                                </Label>
                                                {links.tyreLayoutsStoreUrl ? (
                                                    <button
                                                        type="button"
                                                        className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                        onClick={() =>
                                                            setQuickCreate(
                                                                'tyre',
                                                            )
                                                        }
                                                    >
                                                        + Add
                                                    </button>
                                                ) : null}
                                            </div>
                                            <select
                                                id="tyre_layout_id"
                                                name="tyre_layout_id"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={tyreLayoutId}
                                                onChange={(event) =>
                                                    setTyreLayoutId(
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                            >
                                                <option value="">
                                                    Select…
                                                </option>
                                                {tyreLayouts.map((item) => (
                                                    <option
                                                        key={item.id}
                                                        value={item.id}
                                                    >
                                                        {item.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.tyre_layout_id}
                                            />
                                        </div>
                                    ) : null}
                                    {isMarket || driversEnabled ? (
                                        <div className="space-y-2 md:col-span-2">
                                            <SearchablePartySelect
                                                id="primary_driver_party_id"
                                                name="primary_driver_party_id"
                                                label={
                                                    isMarket
                                                        ? 'Driver'
                                                        : 'Primary Driver'
                                                }
                                                options={formOptions.drivers}
                                                searchUrl={
                                                    links.partiesOptionsUrl
                                                }
                                                role="driver"
                                                createStoreUrl={
                                                    links.partiesStoreUrl
                                                }
                                                createLabel="Add"
                                                createRole="driver"
                                                value={primaryDriverPartyId}
                                                onValueChange={
                                                    setPrimaryDriverPartyId
                                                }
                                                emptyMessage="Search eligible Driver parties…"
                                                error={
                                                    errors.primary_driver_party_id
                                                }
                                            />
                                        </div>
                                    ) : null}
                                    <div className="space-y-2">
                                        <Label htmlFor="registration_date">
                                            Registration Date
                                        </Label>
                                        <Input
                                            id="registration_date"
                                            name="registration_date"
                                            type="date"
                                            defaultValue={
                                                vehicle?.registration_date ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.registration_date}
                                        />
                                    </div>
                                    {isOwn
                                        ? OWN_FLEET_FIELDS.map(
                                              ([name, label]) => (
                                                  <div
                                                      key={name}
                                                      className="space-y-2"
                                                  >
                                                      <Label htmlFor={name}>
                                                          {label}
                                                      </Label>
                                                      <Input
                                                          id={name}
                                                          name={name}
                                                          defaultValue={
                                                              vehicle?.[
                                                                  name
                                                              ]?.toString() ??
                                                              ''
                                                          }
                                                      />
                                                      <InputError
                                                          message={errors[name]}
                                                      />
                                                  </div>
                                              ),
                                          )
                                        : null}
                                </div>
                            </LrfSection>
                            <LrfSection
                                step={2}
                                title="Status"
                                description={
                                    mode === 'edit'
                                        ? 'Active flag and audit remark for this change.'
                                        : 'Set whether this vehicle is active.'
                                }
                                tone="optional"
                            >
                                <div className="space-y-3">
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            value="1"
                                            defaultChecked={
                                                vehicle?.is_active ?? true
                                            }
                                        />
                                        Active
                                    </label>
                                    {mode === 'edit' ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="remark" required>
                                                Remark
                                            </Label>
                                            <Input
                                                id="remark"
                                                name="remark"
                                                required
                                            />
                                            <InputError
                                                message={errors.remark}
                                            />
                                        </div>
                                    ) : null}
                                    <div className="flex gap-3">
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {processing ? 'Saving…' : 'Save'}
                                        </Button>
                                        <Button asChild variant="outline">
                                            <Link
                                                href={links.show ?? links.index}
                                            >
                                                Cancel
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </LrfSection>
                        </>
                    )}
                </Form>
            </LrfPage>

            {quickCreate === 'truck' && links.truckTypesStoreUrl ? (
                <QuickCreateCatalogueSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setQuickCreate(null);
                        }
                    }}
                    title="Truck Type"
                    storeUrl={links.truckTypesStoreUrl}
                    onCreated={(item) => {
                        setTruckTypes((current) =>
                            current.some((entry) => entry.id === item.id)
                                ? current
                                : [...current, item],
                        );
                        setTruckTypeId(item.id);
                        setQuickCreate(null);
                    }}
                />
            ) : null}

            {quickCreate === 'tyre' && links.tyreLayoutsStoreUrl ? (
                <QuickCreateTyreLayoutSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setQuickCreate(null);
                        }
                    }}
                    storeUrl={links.tyreLayoutsStoreUrl}
                    onCreated={(item) => {
                        setTyreLayouts((current) =>
                            current.some((entry) => entry.id === item.id)
                                ? current
                                : [...current, item],
                        );
                        setTyreLayoutId(item.id);
                        setQuickCreate(null);
                    }}
                />
            ) : null}
        </>
    );
}
