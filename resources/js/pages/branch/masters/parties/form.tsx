import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import {
    AppFormCard,
    AppFormPage,
    AppFormSection,
} from '@/components/app-form-page';
import {
    diffScalarFields,
    type ScalarFieldDef,
} from '@/components/form-confirmation/diff';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    ChangeReview,
    FieldReview,
} from '@/components/form-confirmation/types';
import { PartyMasterFormFields } from '@/components/masters/party-master/party-master-form-fields';
import { derivePartyFieldModel } from '@/components/masters/party-master/party-role-field-model';
import type {
    DriverAttrs,
    PartyBasicValues,
    RolePayload,
    TransporterAttrs,
} from '@/components/masters/party-master/party-master-types';
import {
    initialDriver,
    initialTransporter,
    roleIndexMap,
} from '@/components/masters/party-master/party-master-types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';

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

const TDS_PREVIEW_URL = '/parties/tds-preview';

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

function initialBasic(party: Party | null): PartyBasicValues {
    return {
        name: party?.name ?? '',
        contact_person: party?.contact_person ?? '',
        contact_number: party?.contact_number ?? '',
        email: party?.email ?? '',
        address: party?.address ?? '',
        gstin: party?.gstin ?? '',
        pan: party?.pan ?? '',
        is_gst_applicable:
            party?.is_gst_applicable === true
                ? '1'
                : party?.is_gst_applicable === false
                  ? '0'
                  : '',
    };
}

export default function PartyForm({ mode, party, roleOptions, links }: Props) {
    const canQuickCreateGeography = readCanQuickCreateGeography(
        usePage().props,
    );
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
    const [basic, setBasic] = useState<PartyBasicValues>(() =>
        initialBasic(party),
    );
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

    const roleIndex = useMemo(
        () => roleIndexMap(selectedRoles),
        [selectedRoles],
    );

    const fieldModel = derivePartyFieldModel(selectedRoles);

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

    const buildDraft = (form: HTMLFormElement): PartyDraft => {
        const gstValue = readFormControlValue(form, 'is_gst_applicable');

        return {
            name: basic.name.trim(),
            contact_person: basic.contact_person.trim(),
            contact_number: basic.contact_number.trim(),
            email: basic.email.trim(),
            state: state.trim(),
            city: city.trim(),
            address: basic.address.trim(),
            gstin: basic.gstin.trim(),
            pan: pan.trim(),
            is_gst_applicable:
                gstValue === '1'
                    ? 'Yes'
                    : gstValue === '0'
                      ? 'No'
                      : gstValue === ''
                        ? 'Not specified'
                        : 'No',
            roles: roleLabels(selectedRoles, roleOptions),
            remark: readFormControlValue(form, 'remark'),
        };
    };

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
        const draft = buildDraft(form);
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

    const handleBeforeSubmit = (): boolean => {
        if (!submitAllowed.current) {
            const form = document.getElementById('party-master-form');
            if (form instanceof HTMLFormElement) {
                openConfirmation(form);
            }
            return false;
        }
        submitAllowed.current = false;
        return true;
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

    const title = mode === 'create' ? 'Create party' : `Edit ${party?.name}`;

    return (
        <>
            <Head title={title} />
            <AppFormPage
                title={title}
                description="Choose roles first, then enter contact details and only the information that applies to those roles."
                actions={
                    <Button asChild variant="outline">
                        <Link href={links.index}>Back to parties</Link>
                    </Button>
                }
            >
                <Form
                    id="party-master-form"
                    action={action}
                    method={method}
                    className="space-y-6"
                    onBefore={handleBeforeSubmit}
                >
                    {({ errors, processing }) => (
                        <>
                            <PartyMasterFormFields
                                errors={errors}
                                roleOptions={roleOptions}
                                selectedRoles={selectedRoles}
                                onToggleRole={toggleRole}
                                basic={basic}
                                onBasicChange={(patch) =>
                                    setBasic((current) => ({
                                        ...current,
                                        ...patch,
                                    }))
                                }
                                stateId={stateId}
                                cityId={cityId}
                                state={state}
                                city={city}
                                onGeographyChange={(next) => {
                                    setStateId(next.state_id);
                                    setCityId(next.city_id);
                                    setState(next.state);
                                    setCity(next.city);
                                }}
                                canQuickCreateGeography={
                                    canQuickCreateGeography
                                }
                                roleIndex={roleIndex}
                                pan={pan}
                                setPan={setPan}
                                transporter={transporter}
                                setTransporter={setTransporter}
                                driver={driver}
                                setDriver={setDriver}
                                tdsPreviewUrl={tdsPreviewUrl}
                                partyGstApplicable={party?.is_gst_applicable}
                            />

                            {!fieldModel.showGstBusinessTax &&
                            !fieldModel.needsTransporter &&
                            pan !== '' ? (
                                <input type="hidden" name="pan" value={pan} />
                            ) : null}

                            {mode === 'edit' ? (
                                <AppFormCard>
                                    <AppFormSection
                                        title="Remark"
                                        description="Audit remark required when editing an existing party."
                                    >
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
                                    </AppFormSection>
                                </AppFormCard>
                            ) : null}

                            <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-background/95 py-3 backdrop-blur supports-backdrop-filter:bg-background/80">
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
            </AppFormPage>
        </>
    );
}
