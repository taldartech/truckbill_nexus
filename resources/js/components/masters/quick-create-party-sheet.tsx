import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { PartyMasterFormFields } from '@/components/masters/party-master/party-master-form-fields';
import { derivePartyFieldModel } from '@/components/masters/party-master/party-role-field-model';
import {
    buildPartyStorePayload,
    initialDriver,
    initialTransporter,
    roleIndexMap,
    type DriverAttrs,
    type PartyBasicValues,
    type TransporterAttrs,
} from '@/components/masters/party-master/party-master-types';
import type { PartyOption } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';
import { getCsrfToken } from '@/lib/csrf';
import { usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export type QuickCreatePartyRole =
    | 'customer'
    | 'consignor'
    | 'consignee'
    | 'transporter'
    | 'driver'
    | 'pump_vendor'
    | 'general_vendor';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    role: QuickCreatePartyRole;
    onCreated: (party: PartyOption) => void;
    tdsPreviewUrl?: string;
};

const ROLE_TITLES: Record<QuickCreatePartyRole, string> = {
    customer: 'Customer',
    consignor: 'Consignor',
    consignee: 'Consignee',
    transporter: 'Agency / Transporter (Truck owner)',
    driver: 'Driver',
    pump_vendor: 'Pump Vendor',
    general_vendor: 'General Vendor',
};

const EMPTY_BASIC: PartyBasicValues = {
    name: '',
    contact_person: '',
    contact_number: '',
    email: '',
    address: '',
    gstin: '',
    pan: '',
    is_gst_applicable: '',
};

function normalizeErrors(
    errors: Record<string, string[] | string> | undefined,
): Record<string, string> {
    if (!errors) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(errors).map(([key, value]) => [
            key,
            Array.isArray(value) ? (value[0] ?? '') : value,
        ]),
    );
}

/**
 * Inline Party master create for Bilty / Hire selectors (legacy quick-add modal equivalent).
 * Posts JSON so the parent operational form stays mounted.
 */
export function QuickCreatePartySheet({
    open,
    onOpenChange,
    storeUrl,
    role,
    onCreated,
    tdsPreviewUrl,
}: Props) {
    const canQuickCreateGeography = readCanQuickCreateGeography(
        usePage().props,
    );
    const title = ROLE_TITLES[role];
    const selectedRoles = useMemo(() => [role], [role]);
    const roleIndex = useMemo(
        () => roleIndexMap(selectedRoles),
        [selectedRoles],
    );
    const fieldModel = derivePartyFieldModel(selectedRoles);

    const [basic, setBasic] = useState<PartyBasicValues>(EMPTY_BASIC);
    const [transporter, setTransporter] = useState<TransporterAttrs>(() =>
        initialTransporter(undefined),
    );
    const [driver, setDriver] = useState<DriverAttrs>(() =>
        initialDriver(undefined),
    );
    const [pan, setPan] = useState('');
    const [stateId, setStateId] = useState('');
    const [cityId, setCityId] = useState('');
    const [state, setState] = useState('');
    const [city, setCity] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const roleOptions = useMemo(
        () => [{ value: role, label: title }],
        [role, title],
    );

    const reviewFields = useMemo<FieldReview[]>(() => {
        const fields: FieldReview[] = [
            { label: 'Name', value: basic.name },
            { label: 'Contact number', value: basic.contact_number },
            { label: 'State', value: state },
            { label: 'City', value: city },
            { label: 'Role', value: title },
        ];
        if (basic.contact_person) {
            fields.push({
                label: 'Contact person',
                value: basic.contact_person,
            });
        }
        if (basic.email) {
            fields.push({ label: 'Email', value: basic.email });
        }
        if (fieldModel.showGstBusinessTax && basic.gstin) {
            fields.push({ label: 'GSTIN', value: basic.gstin });
        }
        if (pan) {
            fields.push({ label: 'PAN', value: pan });
        }
        if (fieldModel.needsTransporter) {
            fields.push({
                label: 'Declaration',
                value: transporter.declaration ? 'Yes' : 'No',
            });
        }
        return fields;
    }, [
        basic,
        state,
        city,
        title,
        fieldModel.showGstBusinessTax,
        fieldModel.needsTransporter,
        pan,
        transporter.declaration,
    ]);

    const reset = () => {
        setBasic(EMPTY_BASIC);
        setTransporter(initialTransporter(undefined));
        setDriver(initialDriver(undefined));
        setPan('');
        setStateId('');
        setCityId('');
        setState('');
        setCity('');
        setErrors({});
        setFormError(null);
        setConfirmOpen(false);
        setProcessing(false);
    };

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            reset();
        }
        onOpenChange(next);
    };

    const openConfirm = (event: React.FormEvent) => {
        event.preventDefault();
        setFormError(null);
        setErrors({});
        setConfirmOpen(true);
    };

    const submit = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        const payload = buildPartyStorePayload(
            selectedRoles,
            basic,
            transporter,
            driver,
            pan,
            {
                state,
                city,
                state_id: stateId,
                city_id: cityId,
            },
            fieldModel,
        );

        try {
            const response = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            const json = (await response.json().catch(() => null)) as {
                data?: PartyOption;
                message?: string;
                errors?: Record<string, string[] | string>;
            } | null;

            if (!response.ok) {
                if (json?.errors) {
                    setErrors(normalizeErrors(json.errors));
                }
                setFormError(
                    json?.message ??
                        (response.status === 422
                            ? 'Please correct the highlighted fields.'
                            : 'Unable to create party.'),
                );
                setConfirmOpen(false);
                return;
            }

            const created = json?.data;
            if (!created?.id) {
                setFormError('Party created but response was incomplete.');
                setConfirmOpen(false);
                return;
            }

            onCreated({
                id: created.id,
                name: created.name,
                city: created.city ?? null,
                state: created.state ?? null,
                contact_number: created.contact_number ?? null,
            });
            handleOpenChange(false);
        } catch {
            setFormError('Unable to create party.');
            setConfirmOpen(false);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg"
                >
                    <SheetHeader className="border-b border-border p-4 text-left">
                        <SheetTitle>Add {title}</SheetTitle>
                        <SheetDescription>
                            Create a branch-visible {title.toLowerCase()} and
                            return to the current form with it selected.
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        className="flex flex-1 flex-col gap-4 p-4"
                        onSubmit={openConfirm}
                        noValidate
                    >
                        {formError ? (
                            <p
                                className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                                role="alert"
                            >
                                {formError}
                            </p>
                        ) : null}

                        <PartyMasterFormFields
                            idPrefix="quick-party-"
                            variant="sheet"
                            nativeForm={false}
                            errors={errors}
                            roleOptions={roleOptions}
                            selectedRoles={selectedRoles}
                            rolesLocked
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
                            canQuickCreateGeography={canQuickCreateGeography}
                            roleIndex={roleIndex}
                            pan={pan}
                            setPan={setPan}
                            transporter={transporter}
                            setTransporter={setTransporter}
                            driver={driver}
                            setDriver={setDriver}
                            tdsPreviewUrl={tdsPreviewUrl}
                        />

                        <SheetFooter className="mt-auto gap-2 border-t border-border pt-4 sm:flex-row sm:justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Review & create
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={`Confirm create ${title}`}
                description="Review the new master before saving. The Bilty form stays open."
                mode="create"
                fields={reviewFields}
                processing={processing}
                onConfirm={() => {
                    void submit();
                }}
            />
        </>
    );
}
