import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import {
    AppFormCard,
    AppFormPage,
    AppFormSection,
} from '@/components/app-form-page';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    ChangeReview,
    FieldReviewSection,
} from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import { PartyMasterFormFields } from '@/components/masters/party-master/party-master-form-fields';
import {
    buildPartyCreateReview,
    buildPartyEditReview,
    type PartyReviewInput,
} from '@/components/masters/party-master/party-master-review';
import {
    EMPTY_PARTY_BASIC,
    initialDriver,
    initialTransporter,
    roleIndexMap,
    type DriverAttrs,
    type PartyBasicValues,
    type RolePayload,
    type TransporterAttrs,
} from '@/components/masters/party-master/party-master-types';
import { derivePartyFieldModel } from '@/components/masters/party-master/party-role-field-model';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';

type Party = {
    id: string;
    name: string;
    short_name: string | null;
    contact_person: string | null;
    contact_number: string | null;
    email: string | null;
    gstin: string | null;
    pan: string | null;
    is_gst_applicable: boolean;
    bank_name: string | null;
    bank_account_holder_name: string | null;
    bank_account_number_masked: string | null;
    bank_ifsc: string | null;
    bank_short_name: string | null;
    bank_beneficiary_id: string | null;
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

const TDS_PREVIEW_URL = '/parties/tds-preview';

function initialBasic(party: Party | null): PartyBasicValues {
    if (party === null) {
        return EMPTY_PARTY_BASIC;
    }

    return {
        name: party.name,
        short_name: party.short_name ?? party.name,
        contact_person: party.contact_person ?? '',
        contact_number: party.contact_number ?? '',
        email: party.email ?? '',
        address: party.address ?? '',
        gstin: party.gstin ?? '',
        pan: party.pan ?? '',
        is_gst_applicable:
            party.is_gst_applicable === true
                ? '1'
                : party.is_gst_applicable === false
                  ? '0'
                  : '',
        bank_name: party.bank_name ?? '',
        bank_account_holder_name: party.bank_account_holder_name ?? '',
        bank_account_number: '',
        bank_ifsc: party.bank_ifsc ?? '',
        bank_short_name: party.bank_short_name ?? '',
        bank_beneficiary_id: party.bank_beneficiary_id ?? '',
    };
}

function initialRoles(mode: Props['mode'], party: Party | null): string[] {
    if (mode === 'edit' && party?.roles?.length) {
        return party.roles
            .filter((role) => role.is_active !== false)
            .map((role) => role.role);
    }

    return ['customer'];
}

export default function PartyForm({ mode, party, roleOptions, links }: Props) {
    const canQuickCreateGeography = readCanQuickCreateGeography(
        usePage().props,
    );
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewSections, setReviewSections] = useState<FieldReviewSection[]>(
        [],
    );
    const [reviewChanges, setReviewChanges] = useState<ChangeReview[]>([]);
    const [reviewRemark, setReviewRemark] = useState('');

    const [selectedRoles, setSelectedRoles] = useState<string[]>(() =>
        initialRoles(mode, party),
    );
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

    const roleLabel = (code: string): string =>
        roleOptions.find((option) => option.value === code)?.label ?? code;

    const reviewInput = (): PartyReviewInput => ({
        selectedRoles,
        roleLabel,
        basic,
        state,
        city,
        pan,
        transporter,
        driver,
        maskedAccountNumber: party?.bank_account_number_masked,
    });

    const originalReviewInput = (): PartyReviewInput => ({
        selectedRoles: initialRoles(mode, party),
        roleLabel,
        basic: initialBasic(party),
        state: party?.state ?? '',
        city: party?.city ?? '',
        pan: party?.pan ?? '',
        transporter: initialTransporter(party?.roles),
        driver: initialDriver(party?.roles),
        maskedAccountNumber: party?.bank_account_number_masked,
    });

    const openConfirmation = (form: HTMLFormElement) => {
        if (mode === 'create') {
            setReviewSections(buildPartyCreateReview(reviewInput()));
            setReviewChanges([]);
        } else {
            setReviewSections([]);
            setReviewChanges(
                buildPartyEditReview(originalReviewInput(), reviewInput()),
            );
            const remark = form.querySelector<HTMLInputElement>('#remark');
            setReviewRemark(remark?.value.trim() ?? '');
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
                                maskedAccountNumber={
                                    party?.bank_account_number_masked
                                }
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
                            : `Review the changes before saving. Remark: ${reviewRemark}`
                    }
                    sections={reviewSections}
                    changes={reviewChanges}
                    processing={false}
                    onConfirm={handleConfirm}
                />
            </AppFormPage>
        </>
    );
}
