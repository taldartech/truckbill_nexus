import { Form, Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RolePayload = {
    id?: string;
    role: string;
    attributes: Record<string, unknown>;
    is_active?: boolean;
};

type Party = {
    id: string;
    name: string;
    contact_person: string | null;
    contact_number: string | null;
    email: string | null;
    gstin: string | null;
    pan: string | null;
    is_gst_applicable: boolean;
    state: string | null;
    city: string | null;
    address: string | null;
    scope: string;
    roles: RolePayload[];
};

type Props = {
    mode: 'create' | 'edit';
    party: Party | null;
    roleOptions: { value: string; label: string }[];
    links: {
        hub: string;
        dashboard: string;
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
};

const CUSTOMER_FAMILY = new Set(['customer', 'consignor', 'consignee']);
const VENDOR_ROLES = new Set(['general_vendor', 'pump_vendor']);

type TransporterAttrs = {
    pan_status: string;
    declaration: boolean;
    declaration_type: string;
    tds_percentage: string;
    tds_override: boolean;
    tds_adjustment_remark: string;
    bank_account: string;
    short_name: string;
    beneficiary_id: string;
    goods_vehicles_count: string;
};

type DriverAttrs = {
    driver_type: 'permanent' | 'market';
    license_number: string;
    license_expiry: string;
    joining_date: string;
    aadhaar: string;
    pan: string;
    bank_name: string;
    bank_account_number: string;
    ifsc: string;
    emergency_contact_name: string;
    emergency_contact_mobile: string;
    monthly_payment_base: string;
};

function attrString(
    attrs: Record<string, unknown> | undefined,
    key: string,
    fallback = '',
): string {
    const value = attrs?.[key];
    if (value === null || value === undefined) {
        return fallback;
    }
    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        return String(value);
    }

    return fallback;
}

function attrBool(
    attrs: Record<string, unknown> | undefined,
    key: string,
    fallback = false,
): boolean {
    const value = attrs?.[key];
    if (typeof value === 'boolean') {
        return value;
    }
    if (typeof value === 'string') {
        const lower = value.toLowerCase();
        if (['1', 'true', 'yes', 'on'].includes(lower)) {
            return true;
        }
        if (['0', 'false', 'no', 'off', ''].includes(lower)) {
            return false;
        }

        return value.trim() !== '';
    }

    return fallback;
}

function initialTransporter(
    roles: RolePayload[] | undefined,
): TransporterAttrs {
    const attrs = roles?.find(
        (role) => role.role === 'transporter',
    )?.attributes;

    return {
        pan_status: attrString(attrs, 'pan_status', 'active'),
        declaration: attrBool(attrs, 'declaration', false),
        declaration_type: attrString(attrs, 'declaration_type', 'owner'),
        tds_percentage: attrString(attrs, 'tds_percentage'),
        tds_override: attrBool(attrs, 'tds_override', false),
        tds_adjustment_remark: attrString(attrs, 'tds_adjustment_remark'),
        bank_account: attrString(attrs, 'bank_account'),
        short_name: attrString(attrs, 'short_name'),
        beneficiary_id: attrString(attrs, 'beneficiary_id'),
        goods_vehicles_count: attrString(attrs, 'goods_vehicles_count'),
    };
}

function initialDriver(roles: RolePayload[] | undefined): DriverAttrs {
    const attrs = roles?.find((role) => role.role === 'driver')?.attributes;
    const type = attrString(attrs, 'driver_type', 'permanent');

    return {
        driver_type: type === 'market' ? 'market' : 'permanent',
        license_number: attrString(attrs, 'license_number'),
        license_expiry: attrString(attrs, 'license_expiry'),
        joining_date: attrString(attrs, 'joining_date'),
        aadhaar: attrString(attrs, 'aadhaar'),
        pan: attrString(attrs, 'pan'),
        bank_name: attrString(attrs, 'bank_name'),
        bank_account_number: attrString(attrs, 'bank_account_number'),
        ifsc: attrString(attrs, 'ifsc'),
        emergency_contact_name: attrString(attrs, 'emergency_contact_name'),
        emergency_contact_mobile: attrString(attrs, 'emergency_contact_mobile'),
        monthly_payment_base: attrString(attrs, 'monthly_payment_base'),
    };
}

export default function PartyForm({ mode, party, roleOptions, links }: Props) {
    const [selectedRoles, setSelectedRoles] = useState<string[]>(() => {
        if (mode === 'edit' && party?.roles?.length) {
            return party.roles
                .filter((role) => role.is_active !== false)
                .map((role) => role.role);
        }

        return ['customer'];
    });
    const [transporter, setTransporter] = useState<TransporterAttrs>(() =>
        initialTransporter(party?.roles),
    );
    const [driver, setDriver] = useState<DriverAttrs>(() =>
        initialDriver(party?.roles),
    );
    const [pan, setPan] = useState(party?.pan ?? '');

    const roleIndex = useMemo(() => {
        const map: Record<string, number> = {};
        selectedRoles.forEach((role, index) => {
            map[role] = index;
        });

        return map;
    }, [selectedRoles]);

    const needsCustomerFamily = selectedRoles.some((role) =>
        CUSTOMER_FAMILY.has(role),
    );
    const needsTransporter = selectedRoles.includes('transporter');
    const needsDriver = selectedRoles.includes('driver');
    const needsVendor = selectedRoles.some((role) => VENDOR_ROLES.has(role));
    const contactNumberRequired =
        needsCustomerFamily || needsTransporter || needsDriver || needsVendor;
    const stateCityRequired = needsCustomerFamily || needsTransporter;
    const contactPersonRequired = needsVendor;

    const toggleRole = (value: string) => {
        setSelectedRoles((current) =>
            current.includes(value)
                ? current.filter((role) => role !== value)
                : [...current, value],
        );
    };

    const action = mode === 'create' ? links.store! : links.update!;
    const method = mode === 'create' ? 'post' : 'patch';

    return (
        <>
            <Head
                title={
                    mode === 'create' ? 'Create party' : `Edit ${party?.name}`
                }
            />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title={
                        mode === 'create'
                            ? 'Create party'
                            : `Edit ${party?.name}`
                    }
                />
                <Form
                    action={action}
                    method={method}
                    className="mx-auto grid w-full max-w-3xl gap-6 md:max-w-5xl"
                >
                    {({ errors, processing }) => (
                        <>
                            <section className="space-y-3">
                                <h2 className="text-sm font-medium">
                                    Common details
                                </h2>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="name">Name *</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            defaultValue={party?.name ?? ''}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="contact_person">
                                            Contact person
                                            {contactPersonRequired ? ' *' : ''}
                                        </Label>
                                        <Input
                                            id="contact_person"
                                            name="contact_person"
                                            required={contactPersonRequired}
                                            defaultValue={
                                                party?.contact_person ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.contact_person}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="contact_number">
                                            Contact number
                                            {contactNumberRequired ? ' *' : ''}
                                        </Label>
                                        <Input
                                            id="contact_number"
                                            name="contact_number"
                                            required={contactNumberRequired}
                                            defaultValue={
                                                party?.contact_number ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.contact_number}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            defaultValue={party?.email ?? ''}
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="gstin">GSTIN</Label>
                                        <Input
                                            id="gstin"
                                            name="gstin"
                                            defaultValue={party?.gstin ?? ''}
                                        />
                                        <InputError message={errors.gstin} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="pan">
                                            PAN
                                            {needsTransporter ? ' *' : ''}
                                        </Label>
                                        <Input
                                            id="pan"
                                            name="pan"
                                            required={needsTransporter}
                                            value={pan}
                                            onChange={(event) =>
                                                setPan(
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                        />
                                        <InputError message={errors.pan} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="is_gst_applicable">
                                            GST applicable
                                        </Label>
                                        <select
                                            id="is_gst_applicable"
                                            name="is_gst_applicable"
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            defaultValue={
                                                party?.is_gst_applicable ===
                                                false
                                                    ? '0'
                                                    : '1'
                                            }
                                        >
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="state">
                                            State
                                            {stateCityRequired ? ' *' : ''}
                                        </Label>
                                        <Input
                                            id="state"
                                            name="state"
                                            required={stateCityRequired}
                                            defaultValue={party?.state ?? ''}
                                        />
                                        <InputError message={errors.state} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="city">
                                            City
                                            {stateCityRequired ? ' *' : ''}
                                        </Label>
                                        <Input
                                            id="city"
                                            name="city"
                                            required={stateCityRequired}
                                            defaultValue={party?.city ?? ''}
                                        />
                                        <InputError message={errors.city} />
                                    </div>
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="address">Address</Label>
                                        <Input
                                            id="address"
                                            name="address"
                                            defaultValue={party?.address ?? ''}
                                        />
                                    </div>
                                </div>
                            </section>

                            <fieldset className="space-y-3 rounded-xl border border-border p-4">
                                <legend className="px-1 text-sm font-medium">
                                    Party roles *
                                </legend>
                                <InputError message={errors.roles} />
                                <InputError
                                    message={
                                        errors['roles.attributes'] as
                                            | string
                                            | undefined
                                    }
                                />
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {roleOptions.map((option) => {
                                        const checked = selectedRoles.includes(
                                            option.value,
                                        );

                                        return (
                                            <label
                                                key={option.value}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() =>
                                                        toggleRole(option.value)
                                                    }
                                                />
                                                {option.label}
                                                {checked ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex[option.value]}][role]`}
                                                        value={option.value}
                                                    />
                                                ) : null}
                                            </label>
                                        );
                                    })}
                                </div>
                            </fieldset>

                            {needsTransporter || needsDriver ? (
                                <section className="space-y-4">
                                    <h2 className="text-sm font-medium">
                                        Role details
                                    </h2>

                                    {needsTransporter ? (
                                        <div className="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
                                            <p className="text-sm font-medium sm:col-span-2">
                                                Transporter
                                            </p>
                                            <input
                                                type="hidden"
                                                name={`roles[${roleIndex.transporter}][attributes][pan]`}
                                                value={pan}
                                            />
                                            <div className="space-y-2">
                                                <Label htmlFor="pan_status">
                                                    PAN status *
                                                </Label>
                                                <select
                                                    id="pan_status"
                                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                    value={
                                                        transporter.pan_status
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                pan_status:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <option value="active">
                                                        Active
                                                    </option>
                                                    <option value="inactive">
                                                        Inactive
                                                    </option>
                                                    <option value="invalid">
                                                        Invalid
                                                    </option>
                                                    <option value="valid">
                                                        Valid
                                                    </option>
                                                </select>
                                                <input
                                                    type="hidden"
                                                    name={`roles[${roleIndex.transporter}][attributes][pan_status]`}
                                                    value={
                                                        transporter.pan_status
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            'roles.attributes.pan_status'
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="declaration_type">
                                                    Declaration type
                                                </Label>
                                                <select
                                                    id="declaration_type"
                                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                    value={
                                                        transporter.declaration_type
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                declaration_type:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <option value="owner">
                                                        Owner
                                                    </option>
                                                    <option value="agency">
                                                        Agency
                                                    </option>
                                                </select>
                                                <input
                                                    type="hidden"
                                                    name={`roles[${roleIndex.transporter}][attributes][declaration_type]`}
                                                    value={
                                                        transporter.declaration_type
                                                    }
                                                />
                                            </div>
                                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        transporter.declaration
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                declaration:
                                                                    event.target
                                                                        .checked,
                                                            }),
                                                        )
                                                    }
                                                />
                                                Declaration affirmed *
                                                <input
                                                    type="hidden"
                                                    name={`roles[${roleIndex.transporter}][attributes][declaration]`}
                                                    value={
                                                        transporter.declaration
                                                            ? '1'
                                                            : '0'
                                                    }
                                                />
                                            </label>
                                            <InputError
                                                message={
                                                    errors[
                                                        'roles.attributes.declaration'
                                                    ]
                                                }
                                            />
                                            <div className="space-y-2">
                                                <Label htmlFor="tds_percentage">
                                                    TDS %
                                                </Label>
                                                <Input
                                                    id="tds_percentage"
                                                    value={
                                                        transporter.tds_percentage
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                tds_percentage:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {transporter.tds_percentage !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.transporter}][attributes][tds_percentage]`}
                                                        value={
                                                            transporter.tds_percentage
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="goods_vehicles_count">
                                                    Goods vehicles count
                                                </Label>
                                                <Input
                                                    id="goods_vehicles_count"
                                                    type="number"
                                                    min={0}
                                                    value={
                                                        transporter.goods_vehicles_count
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                goods_vehicles_count:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {transporter.goods_vehicles_count !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.transporter}][attributes][goods_vehicles_count]`}
                                                        value={
                                                            transporter.goods_vehicles_count
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        transporter.tds_override
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                tds_override:
                                                                    event.target
                                                                        .checked,
                                                            }),
                                                        )
                                                    }
                                                />
                                                Manual TDS override
                                                <input
                                                    type="hidden"
                                                    name={`roles[${roleIndex.transporter}][attributes][tds_override]`}
                                                    value={
                                                        transporter.tds_override
                                                            ? '1'
                                                            : '0'
                                                    }
                                                />
                                            </label>
                                            {transporter.tds_override ? (
                                                <div className="space-y-2 sm:col-span-2">
                                                    <Label htmlFor="tds_adjustment_remark">
                                                        TDS adjustment remark *
                                                    </Label>
                                                    <Input
                                                        id="tds_adjustment_remark"
                                                        required
                                                        value={
                                                            transporter.tds_adjustment_remark
                                                        }
                                                        onChange={(event) =>
                                                            setTransporter(
                                                                (current) => ({
                                                                    ...current,
                                                                    tds_adjustment_remark:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.transporter}][attributes][tds_adjustment_remark]`}
                                                        value={
                                                            transporter.tds_adjustment_remark
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                'roles.attributes.tds_adjustment_remark'
                                                            ]
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                            <div className="space-y-2">
                                                <Label htmlFor="short_name">
                                                    Short name
                                                </Label>
                                                <Input
                                                    id="short_name"
                                                    value={
                                                        transporter.short_name
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                short_name:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {transporter.short_name !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.transporter}][attributes][short_name]`}
                                                        value={
                                                            transporter.short_name
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="beneficiary_id">
                                                    Beneficiary ID
                                                </Label>
                                                <Input
                                                    id="beneficiary_id"
                                                    value={
                                                        transporter.beneficiary_id
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                beneficiary_id:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {transporter.beneficiary_id !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.transporter}][attributes][beneficiary_id]`}
                                                        value={
                                                            transporter.beneficiary_id
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label htmlFor="bank_account">
                                                    Bank account
                                                </Label>
                                                <Input
                                                    id="bank_account"
                                                    value={
                                                        transporter.bank_account
                                                    }
                                                    onChange={(event) =>
                                                        setTransporter(
                                                            (current) => ({
                                                                ...current,
                                                                bank_account:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {transporter.bank_account !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.transporter}][attributes][bank_account]`}
                                                        value={
                                                            transporter.bank_account
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                        </div>
                                    ) : null}

                                    {needsDriver ? (
                                        <div className="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
                                            <p className="text-sm font-medium sm:col-span-2">
                                                Driver
                                            </p>
                                            <div className="space-y-2">
                                                <Label htmlFor="driver_type">
                                                    Driver type *
                                                </Label>
                                                <select
                                                    id="driver_type"
                                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                    value={driver.driver_type}
                                                    onChange={(event) =>
                                                        setDriver(
                                                            (current) => ({
                                                                ...current,
                                                                driver_type:
                                                                    event.target
                                                                        .value ===
                                                                    'market'
                                                                        ? 'market'
                                                                        : 'permanent',
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <option value="permanent">
                                                        Permanent
                                                    </option>
                                                    <option value="market">
                                                        Market
                                                    </option>
                                                </select>
                                                <input
                                                    type="hidden"
                                                    name={`roles[${roleIndex.driver}][attributes][driver_type]`}
                                                    value={driver.driver_type}
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            'roles.attributes.driver_type'
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="license_number">
                                                    License number
                                                    {driver.driver_type ===
                                                    'permanent'
                                                        ? ' *'
                                                        : ''}
                                                </Label>
                                                <Input
                                                    id="license_number"
                                                    required={
                                                        driver.driver_type ===
                                                        'permanent'
                                                    }
                                                    value={
                                                        driver.license_number
                                                    }
                                                    onChange={(event) =>
                                                        setDriver(
                                                            (current) => ({
                                                                ...current,
                                                                license_number:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {driver.license_number !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.driver}][attributes][license_number]`}
                                                        value={
                                                            driver.license_number
                                                        }
                                                    />
                                                ) : null}
                                                <InputError
                                                    message={
                                                        errors[
                                                            'roles.attributes.license_number'
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="license_expiry">
                                                    License expiry
                                                </Label>
                                                <Input
                                                    id="license_expiry"
                                                    type="date"
                                                    value={
                                                        driver.license_expiry
                                                    }
                                                    onChange={(event) =>
                                                        setDriver(
                                                            (current) => ({
                                                                ...current,
                                                                license_expiry:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                {driver.license_expiry !==
                                                '' ? (
                                                    <input
                                                        type="hidden"
                                                        name={`roles[${roleIndex.driver}][attributes][license_expiry]`}
                                                        value={
                                                            driver.license_expiry
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                            {driver.driver_type ===
                                            'permanent' ? (
                                                <>
                                                    {(
                                                        [
                                                            [
                                                                'joining_date',
                                                                'Joining date',
                                                                'date',
                                                            ],
                                                            [
                                                                'aadhaar',
                                                                'Aadhaar',
                                                                'text',
                                                            ],
                                                            [
                                                                'pan',
                                                                'Driver PAN',
                                                                'text',
                                                            ],
                                                            [
                                                                'bank_name',
                                                                'Bank name',
                                                                'text',
                                                            ],
                                                            [
                                                                'bank_account_number',
                                                                'Bank account number',
                                                                'text',
                                                            ],
                                                            [
                                                                'ifsc',
                                                                'IFSC',
                                                                'text',
                                                            ],
                                                            [
                                                                'emergency_contact_name',
                                                                'Emergency contact name',
                                                                'text',
                                                            ],
                                                            [
                                                                'emergency_contact_mobile',
                                                                'Emergency contact mobile',
                                                                'text',
                                                            ],
                                                            [
                                                                'monthly_payment_base',
                                                                'Monthly payment base',
                                                                'text',
                                                            ],
                                                        ] as const
                                                    ).map(
                                                        ([
                                                            key,
                                                            label,
                                                            type,
                                                        ]) => (
                                                            <div
                                                                key={key}
                                                                className="space-y-2"
                                                            >
                                                                <Label
                                                                    htmlFor={
                                                                        key
                                                                    }
                                                                >
                                                                    {label}
                                                                </Label>
                                                                <Input
                                                                    id={key}
                                                                    type={type}
                                                                    value={
                                                                        driver[
                                                                            key
                                                                        ]
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setDriver(
                                                                            (
                                                                                current,
                                                                            ) => ({
                                                                                ...current,
                                                                                [key]: event
                                                                                    .target
                                                                                    .value,
                                                                            }),
                                                                        )
                                                                    }
                                                                />
                                                                {driver[key] !==
                                                                '' ? (
                                                                    <input
                                                                        type="hidden"
                                                                        name={`roles[${roleIndex.driver}][attributes][${key}]`}
                                                                        value={
                                                                            driver[
                                                                                key
                                                                            ]
                                                                        }
                                                                    />
                                                                ) : null}
                                                            </div>
                                                        ),
                                                    )}
                                                </>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </section>
                            ) : null}

                            <div className="space-y-2">
                                <Label htmlFor="remark">
                                    Remark{mode === 'edit' ? ' *' : ''}
                                </Label>
                                <Input
                                    id="remark"
                                    name="remark"
                                    required={mode === 'edit'}
                                    defaultValue={
                                        mode === 'create' ? 'Create party' : ''
                                    }
                                />
                                <InputError message={errors.remark} />
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || selectedRoles.length === 0
                                    }
                                >
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
