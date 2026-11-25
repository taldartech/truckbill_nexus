import { Form, Head, Link, useHttp, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    diffScalarFields,
    type ScalarFieldDef,
} from '@/components/form-confirmation/diff';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    ChangeReview,
    FieldReview,
} from '@/components/form-confirmation/types';
import { FormSection } from '@/components/form-section';
import { StateCityFields } from '@/components/geography/state-city-fields';
import InputError from '@/components/input-error';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { readCanManageGeography } from '@/lib/geography-permissions';

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
    state_id?: string | null;
    city_id?: string | null;
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
        tdsPreview?: string;
    };
};

type PartyDraft = {
    name: string;
    contact_person: string;
    contact_number: string;
    email: string;
    state: string;
    city: string;
    address: string;
    gstin: string;
    pan: string;
    is_gst_applicable: string;
    roles: string;
    remark: string;
};

const PARTY_FIELD_DEFS: ScalarFieldDef<PartyDraft>[] = [
    { key: 'name', label: 'Name' },
    { key: 'contact_person', label: 'Contact person' },
    { key: 'contact_number', label: 'Contact number' },
    { key: 'email', label: 'Email' },
    { key: 'state', label: 'State' },
    { key: 'city', label: 'City' },
    { key: 'address', label: 'Address' },
    { key: 'gstin', label: 'GSTIN' },
    { key: 'pan', label: 'PAN' },
    { key: 'is_gst_applicable', label: 'GST applicable' },
    { key: 'roles', label: 'Roles' },
    { key: 'remark', label: 'Remark' },
];

function readFormControlValue(form: HTMLFormElement, id: string): string {
    const element = form.querySelector(`#${id}`) as
        | HTMLInputElement
        | HTMLSelectElement
        | null;
    return element?.value?.trim() ?? '';
}

function roleLabels(
    roleCodes: string[],
    roleOptions: { value: string; label: string }[],
): string {
    return roleCodes
        .map(
            (code) =>
                roleOptions.find((option) => option.value === code)?.label ??
                code,
        )
        .join(', ');
}

const CUSTOMER_FAMILY = new Set(['customer', 'consignor', 'consignee']);
const VENDOR_ROLES = new Set(['general_vendor', 'pump_vendor']);
const TDS_PREVIEW_URL = '/parties/tds-preview';

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

type TdsPreviewRequest = {
    pan: string;
    pan_status: string;
    declaration: boolean;
    declaration_type: string;
    goods_vehicles_count: string;
};

type TdsPreviewPayload = {
    applicable_rate?: string | number;
    calculated_rate?: string | number;
    applicable_tds_percentage?: string | number;
    rate_percent?: string | number;
    explanation?: string;
    message?: string;
};

/** Backend wraps the calculation in `{ data: … }`; also accept a flat payload. */
type TdsPreviewResponse = TdsPreviewPayload & {
    data?: TdsPreviewPayload;
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

function unwrapTdsPreview(response: TdsPreviewResponse): TdsPreviewPayload {
    return response.data ?? response;
}

function resolveApplicableTds(response: TdsPreviewResponse): string {
    const payload = unwrapTdsPreview(response);
    const value =
        payload.applicable_rate ??
        payload.applicable_tds_percentage ??
        payload.rate_percent ??
        payload.calculated_rate ??
        '';

    return value === '' || value === null || value === undefined
        ? ''
        : String(value);
}

function resolveTdsExplanation(response: TdsPreviewResponse): string | null {
    const payload = unwrapTdsPreview(response);

    return payload.explanation ?? payload.message ?? null;
}

function TransporterTdsPanel({
    roleIndex,
    pan,
    setPan,
    transporter,
    setTransporter,
    errors,
    previewUrl,
}: {
    roleIndex: number;
    pan: string;
    setPan: (value: string) => void;
    transporter: TransporterAttrs;
    setTransporter: (
        updater: (current: TransporterAttrs) => TransporterAttrs,
    ) => void;
    errors: Record<string, string>;
    previewUrl: string;
}) {
    const [previewPercent, setPreviewPercent] = useState<string | null>(null);
    const [previewExplanation, setPreviewExplanation] = useState<string | null>(
        null,
    );
    const [previewMessage, setPreviewMessage] = useState<string | null>(null);
    const {
        setData,
        post,
        processing: previewLoading,
        cancel,
    } = useHttp<TdsPreviewRequest, TdsPreviewResponse>({
        pan: '',
        pan_status: 'active',
        declaration: false,
        declaration_type: 'owner',
        goods_vehicles_count: '',
    });

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setData({
                pan,
                pan_status: transporter.pan_status,
                declaration: transporter.declaration,
                declaration_type: transporter.declaration_type,
                goods_vehicles_count: transporter.goods_vehicles_count,
            });
            void post(previewUrl, {
                onSuccess: (response) => {
                    setPreviewPercent(resolveApplicableTds(response));
                    setPreviewExplanation(resolveTdsExplanation(response));
                    setPreviewMessage(null);
                },
                onError: (validationErrors) => {
                    setPreviewPercent(null);
                    setPreviewExplanation(null);
                    const first = Object.values(validationErrors)[0];
                    setPreviewMessage(
                        typeof first === 'string'
                            ? first
                            : 'TDS preview validation failed. Check declaration fields.',
                    );
                },
                onHttpException: (response) => {
                    setPreviewPercent(null);
                    setPreviewExplanation(null);
                    if (response.status === 404) {
                        setPreviewMessage(
                            'TDS preview is not available yet. You can still save transporter details.',
                        );
                    } else {
                        setPreviewMessage(
                            'Unable to load TDS preview. Try again after updating declaration fields.',
                        );
                    }

                    return false;
                },
                onNetworkError: () => {
                    setPreviewPercent(null);
                    setPreviewExplanation(null);
                    setPreviewMessage(
                        'Unable to reach TDS preview. Check your connection and try again.',
                    );

                    return false;
                },
            });
        }, 400);

        return () => {
            window.clearTimeout(timer);
            cancel();
        };
    }, [
        pan,
        transporter.pan_status,
        transporter.declaration,
        transporter.declaration_type,
        transporter.goods_vehicles_count,
        previewUrl,
        setData,
        post,
        cancel,
    ]);

    return (
        <FormSection
            title="Transporter TDS"
            description="Declaration inputs drive the live applicable TDS preview."
            className="border-info/20"
            data-testid="transporter-tds-panel"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <input
                    type="hidden"
                    name={`roles[${roleIndex}][attributes][pan]`}
                    value={pan}
                />
                <div className="space-y-2">
                    <Label htmlFor="declaration_type">
                        Declaration type (Owner / Agency)
                    </Label>
                    <select
                        id="declaration_type"
                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        value={transporter.declaration_type}
                        onChange={(event) =>
                            setTransporter((current) => ({
                                ...current,
                                declaration_type: event.target.value,
                            }))
                        }
                    >
                        <option value="owner">Owner</option>
                        <option value="agency">Agency</option>
                    </select>
                    <input
                        type="hidden"
                        name={`roles[${roleIndex}][attributes][declaration_type]`}
                        value={transporter.declaration_type}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="transporter_pan">PAN *</Label>
                    <Input
                        id="transporter_pan"
                        required
                        value={pan}
                        onChange={(event) =>
                            setPan(event.target.value.toUpperCase())
                        }
                        autoComplete="off"
                    />
                    <InputError message={errors.pan} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="pan_status">PAN status *</Label>
                    <select
                        id="pan_status"
                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        value={transporter.pan_status}
                        onChange={(event) =>
                            setTransporter((current) => ({
                                ...current,
                                pan_status: event.target.value,
                            }))
                        }
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="invalid">Invalid</option>
                        <option value="valid">Valid</option>
                    </select>
                    <input
                        type="hidden"
                        name={`roles[${roleIndex}][attributes][pan_status]`}
                        value={transporter.pan_status}
                    />
                    <InputError
                        message={errors['roles.attributes.pan_status']}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="goods_vehicles_count">
                        Goods vehicles count
                    </Label>
                    <Input
                        id="goods_vehicles_count"
                        type="number"
                        min={0}
                        value={transporter.goods_vehicles_count}
                        onChange={(event) =>
                            setTransporter((current) => ({
                                ...current,
                                goods_vehicles_count: event.target.value,
                            }))
                        }
                    />
                    {transporter.goods_vehicles_count !== '' ? (
                        <input
                            type="hidden"
                            name={`roles[${roleIndex}][attributes][goods_vehicles_count]`}
                            value={transporter.goods_vehicles_count}
                        />
                    ) : null}
                </div>
                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={transporter.declaration}
                        onChange={(event) =>
                            setTransporter((current) => ({
                                ...current,
                                declaration: event.target.checked,
                            }))
                        }
                    />
                    Declaration affirmed *
                    <input
                        type="hidden"
                        name={`roles[${roleIndex}][attributes][declaration]`}
                        value={transporter.declaration ? '1' : '0'}
                    />
                </label>
                <InputError message={errors['roles.attributes.declaration']} />

                <div
                    className="space-y-2 rounded-md border border-dashed border-info/40 bg-info/5 p-3 sm:col-span-2"
                    data-testid="tds-preview-panel"
                    aria-live="polite"
                >
                    <p className="text-xs font-medium tracking-wide text-info uppercase">
                        Live TDS preview
                    </p>
                    {previewLoading ? (
                        <p className="text-sm text-muted-foreground">
                            Calculating applicable TDS…
                        </p>
                    ) : null}
                    {!previewLoading && previewPercent !== null ? (
                        <p className="text-sm font-semibold text-foreground">
                            Applicable TDS{' '}
                            <span className="text-primary">
                                {previewPercent}%
                            </span>
                        </p>
                    ) : null}
                    {!previewLoading && previewExplanation ? (
                        <p className="text-sm text-muted-foreground">
                            {previewExplanation}
                        </p>
                    ) : null}
                    {!previewLoading && previewMessage ? (
                        <p className="text-sm text-muted-foreground">
                            {previewMessage}
                        </p>
                    ) : null}
                    {!previewLoading &&
                    previewPercent === null &&
                    !previewMessage ? (
                        <p className="text-sm text-muted-foreground">
                            Update declaration fields to refresh the preview.
                        </p>
                    ) : null}
                </div>

                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={transporter.tds_override}
                        onChange={(event) =>
                            setTransporter((current) => ({
                                ...current,
                                tds_override: event.target.checked,
                            }))
                        }
                    />
                    Override applicable TDS
                    <input
                        type="hidden"
                        name={`roles[${roleIndex}][attributes][tds_override]`}
                        value={transporter.tds_override ? '1' : '0'}
                    />
                </label>
                {transporter.tds_override ? (
                    <>
                        <div className="space-y-2">
                            <Label htmlFor="tds_percentage">TDS % *</Label>
                            <Input
                                id="tds_percentage"
                                required
                                value={transporter.tds_percentage}
                                onChange={(event) =>
                                    setTransporter((current) => ({
                                        ...current,
                                        tds_percentage: event.target.value,
                                    }))
                                }
                            />
                            {transporter.tds_percentage !== '' ? (
                                <input
                                    type="hidden"
                                    name={`roles[${roleIndex}][attributes][tds_percentage]`}
                                    value={transporter.tds_percentage}
                                />
                            ) : null}
                            <InputError
                                message={
                                    errors['roles.attributes.tds_percentage']
                                }
                            />
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="tds_adjustment_remark">
                                TDS adjustment remark *
                            </Label>
                            <Input
                                id="tds_adjustment_remark"
                                required
                                value={transporter.tds_adjustment_remark}
                                onChange={(event) =>
                                    setTransporter((current) => ({
                                        ...current,
                                        tds_adjustment_remark:
                                            event.target.value,
                                    }))
                                }
                            />
                            <input
                                type="hidden"
                                name={`roles[${roleIndex}][attributes][tds_adjustment_remark]`}
                                value={transporter.tds_adjustment_remark}
                            />
                            <InputError
                                message={
                                    errors[
                                        'roles.attributes.tds_adjustment_remark'
                                    ]
                                }
                            />
                        </div>
                    </>
                ) : transporter.tds_percentage !== '' ? (
                    <input
                        type="hidden"
                        name={`roles[${roleIndex}][attributes][tds_percentage]`}
                        value={transporter.tds_percentage}
                    />
                ) : null}
            </div>
        </FormSection>
    );
}

export default function PartyForm({ mode, party, roleOptions, links }: Props) {
    const canQuickCreateGeography = readCanManageGeography(usePage().props);
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const [reviewChanges, setReviewChanges] = useState<ChangeReview[]>([]);

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
    const [stateId, setStateId] = useState(party?.state_id ?? '');
    const [cityId, setCityId] = useState(party?.city_id ?? '');
    const [state, setState] = useState(party?.state ?? '');
    const [city, setCity] = useState(party?.city ?? '');

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
    const tdsPreviewUrl = links.tdsPreview ?? TDS_PREVIEW_URL;

    const buildDraft = (
        form: HTMLFormElement,
        panValue: string,
        stateValue: string,
        cityValue: string,
    ): PartyDraft => ({
        name: readFormControlValue(form, 'name'),
        contact_person: readFormControlValue(form, 'contact_person'),
        contact_number: readFormControlValue(form, 'contact_number'),
        email: readFormControlValue(form, 'email'),
        state: stateValue,
        city: cityValue,
        address: readFormControlValue(form, 'address'),
        gstin: readFormControlValue(form, 'gstin'),
        pan: panValue.trim(),
        is_gst_applicable:
            readFormControlValue(form, 'is_gst_applicable') === '1'
                ? 'Yes'
                : 'No',
        roles: roleLabels(selectedRoles, roleOptions),
        remark: readFormControlValue(form, 'remark'),
    });

    const buildOriginal = (): PartyDraft => {
        const activeRoles =
            party?.roles
                ?.filter((role) => role.is_active !== false)
                .map((role) => role.role) ?? [];

        return {
            name: party?.name ?? '',
            contact_person: party?.contact_person ?? '',
            contact_number: party?.contact_number ?? '',
            email: party?.email ?? '',
            state: party?.state ?? '',
            city: party?.city ?? '',
            address: party?.address ?? '',
            gstin: party?.gstin ?? '',
            pan: party?.pan ?? '',
            is_gst_applicable:
                party?.is_gst_applicable === false ? 'No' : 'Yes',
            roles: roleLabels(activeRoles, roleOptions),
            remark: '',
        };
    };

    const openConfirmation = (form: HTMLFormElement) => {
        const draft = buildDraft(form, pan, state, city);
        if (mode === 'create') {
            setReviewFields(
                PARTY_FIELD_DEFS.filter((field) => draft[field.key] !== '').map(
                    (field) => ({
                        label: field.label,
                        value: draft[field.key],
                    }),
                ),
            );
            setReviewChanges([]);
        } else {
            setReviewFields([]);
            setReviewChanges(
                diffScalarFields(buildOriginal(), draft, PARTY_FIELD_DEFS),
            );
        }
        setConfirmOpen(true);
    };

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        if (!submitAllowed.current) {
            event.preventDefault();
            openConfirmation(event.currentTarget);
            return;
        }
        submitAllowed.current = false;
    };

    const handleConfirm = () => {
        submitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => {
            const form = document.getElementById(
                'party-master-form',
            ) as HTMLFormElement | null;
            form?.requestSubmit();
        }, 0);
    };

    return (
        <>
            <Head
                title={
                    mode === 'create' ? 'Create party' : `Edit ${party?.name}`
                }
            />
            <LrfPage
                title={
                    mode === 'create' ? 'Create party' : `Edit ${party?.name}`
                }
                subtitle="Sectioned party master — roles unlock transporter TDS and driver panels."
                backHref={links.index}
                backLabel="Parties"
            >
                <Form
                    id="party-master-form"
                    action={action}
                    method={method}
                    className="space-y-3"
                    onSubmit={handleSubmit}
                >
                    {({ errors, processing }) => (
                        <>
                            <LrfSection
                                step={1}
                                title="Basic"
                                description="Legal or trading name for this party."
                                tone="receipt"
                            >
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Name *</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            defaultValue={party?.name ?? ''}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                </div>
                            </LrfSection>

                            <LrfSection
                                step={2}
                                title="Contact"
                                description="Reachable contact for operations and billing."
                                tone="route"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
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
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            defaultValue={party?.email ?? ''}
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </div>
                            </LrfSection>

                            <LrfSection
                                step={3}
                                title="Address"
                                description="Location used on LR and invoice documents."
                                tone="goods"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="md:col-span-2">
                                        <StateCityFields
                                            canQuickCreate={
                                                canQuickCreateGeography
                                            }
                                            stateId={stateId}
                                            cityId={cityId}
                                            stateName={state}
                                            cityName={city}
                                            required={stateCityRequired}
                                            stateError={
                                                errors.state_id ?? errors.state
                                            }
                                            cityError={
                                                errors.city_id ?? errors.city
                                            }
                                            onChange={(next) => {
                                                setStateId(next.state_id);
                                                setCityId(next.city_id);
                                                setState(next.state);
                                                setCity(next.city);
                                            }}
                                        />
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="address">Address</Label>
                                        <Input
                                            id="address"
                                            name="address"
                                            defaultValue={party?.address ?? ''}
                                        />
                                    </div>
                                </div>
                            </LrfSection>

                            <LrfSection
                                step={4}
                                title="Roles"
                                description="Select one or more roles. Role-specific panels appear below."
                                tone="optional"
                            >
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    <InputError message={errors.roles} />
                                    <InputError
                                        message={
                                            errors['roles.attributes'] as
                                                | string
                                                | undefined
                                        }
                                    />
                                    <div
                                        className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                        role="group"
                                        aria-label="Party roles"
                                    >
                                        {roleOptions.map((option) => {
                                            const checked =
                                                selectedRoles.includes(
                                                    option.value,
                                                );

                                            return (
                                                <button
                                                    key={option.value}
                                                    type="button"
                                                    role="checkbox"
                                                    aria-checked={checked}
                                                    onClick={() =>
                                                        toggleRole(option.value)
                                                    }
                                                    className={cn(
                                                        'rounded-lg border px-3 py-2.5 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                        checked
                                                            ? 'border-primary bg-accent text-accent-foreground shadow-xs'
                                                            : 'border-border bg-background hover:border-primary/40',
                                                    )}
                                                >
                                                    <span className="font-medium">
                                                        {option.label}
                                                    </span>
                                                    {checked ? (
                                                        <input
                                                            type="hidden"
                                                            name={`roles[${roleIndex[option.value]}][role]`}
                                                            value={option.value}
                                                        />
                                                    ) : null}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </LrfSection>

                            {needsDriver ? (
                                <LrfSection
                                    step={5}
                                    title="Driver details"
                                    description="Shown when the driver role is selected."
                                    tone="route"
                                >
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="driver_type">
                                                Driver type *
                                            </Label>
                                            <select
                                                id="driver_type"
                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                value={driver.driver_type}
                                                onChange={(event) =>
                                                    setDriver((current) => ({
                                                        ...current,
                                                        driver_type:
                                                            event.target
                                                                .value ===
                                                            'market'
                                                                ? 'market'
                                                                : 'permanent',
                                                    }))
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
                                                value={driver.license_number}
                                                onChange={(event) =>
                                                    setDriver((current) => ({
                                                        ...current,
                                                        license_number:
                                                            event.target.value,
                                                    }))
                                                }
                                            />
                                            {driver.license_number !== '' ? (
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
                                                value={driver.license_expiry}
                                                onChange={(event) =>
                                                    setDriver((current) => ({
                                                        ...current,
                                                        license_expiry:
                                                            event.target.value,
                                                    }))
                                                }
                                            />
                                            {driver.license_expiry !== '' ? (
                                                <input
                                                    type="hidden"
                                                    name={`roles[${roleIndex.driver}][attributes][license_expiry]`}
                                                    value={
                                                        driver.license_expiry
                                                    }
                                                />
                                            ) : null}
                                        </div>
                                        {driver.driver_type === 'permanent'
                                            ? (
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
                                              ).map(([key, label, type]) => (
                                                  <div
                                                      key={key}
                                                      className="space-y-2"
                                                  >
                                                      <Label htmlFor={key}>
                                                          {label}
                                                      </Label>
                                                      <Input
                                                          id={key}
                                                          type={type}
                                                          value={driver[key]}
                                                          onChange={(event) =>
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
                                                      {driver[key] !== '' ? (
                                                          <input
                                                              type="hidden"
                                                              name={`roles[${roleIndex.driver}][attributes][${key}]`}
                                                              value={
                                                                  driver[key]
                                                              }
                                                          />
                                                      ) : null}
                                                  </div>
                                              ))
                                            : null}
                                    </div>
                                </LrfSection>
                            ) : null}

                            <LrfSection
                                step={needsDriver ? 6 : 5}
                                title="GST & PAN"
                                description="Tax identifiers shared across roles."
                                tone="goods"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
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
                                    <div className="space-y-2 md:col-span-2">
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
                                </div>
                            </LrfSection>

                            {needsTransporter ||
                            (needsDriver &&
                                driver.driver_type === 'permanent') ? (
                                <LrfSection
                                    step={needsDriver ? 7 : 6}
                                    title="Bank"
                                    description="Settlement accounts for transporter payouts and driver payroll."
                                    tone="optional"
                                >
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {needsTransporter ? (
                                            <>
                                                <p className="text-xs font-medium text-muted-foreground md:col-span-2">
                                                    Transporter payout
                                                </p>
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
                                                                        event
                                                                            .target
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
                                                                        event
                                                                            .target
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
                                                <div className="space-y-2 md:col-span-2">
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
                                                                        event
                                                                            .target
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
                                            </>
                                        ) : null}
                                        {needsDriver &&
                                        driver.driver_type === 'permanent' ? (
                                            <>
                                                <p className="text-xs font-medium text-muted-foreground md:col-span-2">
                                                    Driver payroll
                                                </p>
                                                {(
                                                    [
                                                        [
                                                            'bank_name',
                                                            'Bank name',
                                                        ],
                                                        [
                                                            'bank_account_number',
                                                            'Bank account number',
                                                        ],
                                                        ['ifsc', 'IFSC'],
                                                    ] as const
                                                ).map(([key, label]) => (
                                                    <div
                                                        key={key}
                                                        className="space-y-2"
                                                    >
                                                        <Label
                                                            htmlFor={`driver_${key}`}
                                                        >
                                                            {label}
                                                        </Label>
                                                        <Input
                                                            id={`driver_${key}`}
                                                            value={driver[key]}
                                                            onChange={(event) =>
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
                                                        {driver[key] !== '' ? (
                                                            <input
                                                                type="hidden"
                                                                name={`roles[${roleIndex.driver}][attributes][${key}]`}
                                                                value={
                                                                    driver[key]
                                                                }
                                                            />
                                                        ) : null}
                                                    </div>
                                                ))}
                                            </>
                                        ) : null}
                                    </div>
                                </LrfSection>
                            ) : null}

                            {needsTransporter ? (
                                <TransporterTdsPanel
                                    roleIndex={roleIndex.transporter}
                                    pan={pan}
                                    setPan={setPan}
                                    transporter={transporter}
                                    setTransporter={setTransporter}
                                    errors={errors}
                                    previewUrl={tdsPreviewUrl}
                                />
                            ) : null}

                            <LrfSection
                                step={8}
                                title="Remark"
                                description="Audit remark for this save."
                                tone="optional"
                            >
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="remark">
                                            Remark{mode === 'edit' ? ' *' : ''}
                                        </Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required={mode === 'edit'}
                                            defaultValue={
                                                mode === 'create'
                                                    ? 'Create party'
                                                    : ''
                                            }
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                </div>
                            </LrfSection>

                            <div className="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-background/95 px-1 py-3 backdrop-blur supports-backdrop-filter:bg-background/80">
                                <Button asChild variant="outline">
                                    <Link href={links.show ?? links.index}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={
                                        processing || selectedRoles.length === 0
                                    }
                                >
                                    {processing ? 'Saving…' : 'Save'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode={mode === 'create' ? 'create' : 'edit'}
                    title={
                        mode === 'create'
                            ? 'Create this party?'
                            : 'Save party changes?'
                    }
                    description={
                        mode === 'create'
                            ? 'Review the values below before creating the party.'
                            : 'Review field changes before saving.'
                    }
                    fields={reviewFields}
                    changes={reviewChanges}
                    processing={false}
                    onConfirm={handleConfirm}
                />
            </LrfPage>
        </>
    );
}
