import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import OwnerSettingsController from '@/actions/App/Http/Controllers/Owner/OwnerSettingsController';
import {
    AppFormCard,
    AppFormGrid,
    AppFormSection,
} from '@/components/app-form-page';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MobileNumberInput } from '@/components/ui/mobile-number-input';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';

type DeferredSection = {
    key: string;
    title: string;
    message: string;
};

type Option = { value: string; label: string };
type GstRateOption = {
    id: string;
    label?: string;
    rate_percent: string;
};

type OwnerSettingsProps = {
    company: {
        id: string;
        name: string;
        status: string;
        pan: string | null;
        registered_address: string | null;
        state: string | null;
        city: string | null;
        state_id?: string | null;
        city_id?: string | null;
        pin: string | null;
        contact_person: string | null;
        mobile: string | null;
        email: string | null;
        gst_registration_type: string | null;
        tax_liability: string | null;
        gstin: string | null;
        gst_rate_id: string | null;
        data_visibility: string | null;
        timezone: string;
        owner: { id: number; name: string; email: string } | null;
        updated_at: string | null;
    };
    gstRates: GstRateOption[];
    options: {
        gst_registration_types: Option[];
        tax_liabilities: Option[];
        data_visibilities: Option[];
    };
    ownerPanelScope: string;
    canEditProfile: boolean;
    deferredSections: DeferredSection[];
    links: {
        dashboard: string;
        update: string;
    };
};

type FlashPageProps = {
    flash?: { success?: string | null };
};

export default function OwnerSettings({
    company,
    gstRates,
    options,
    ownerPanelScope,
    canEditProfile,
    deferredSections,
    links,
}: OwnerSettingsProps) {
    const page = usePage<FlashPageProps>();
    const { flash } = page.props;
    const canQuickCreateGeography = readCanQuickCreateGeography(page.props);
    const [gstType, setGstType] = useState(
        company.gst_registration_type ?? 'regular',
    );
    const [stateId, setStateId] = useState(company.state_id ?? '');
    const [cityId, setCityId] = useState(company.city_id ?? '');
    const [stateName, setStateName] = useState(company.state ?? '');
    const [cityName, setCityName] = useState(company.city ?? '');
    const needsGstin = gstType !== 'unregistered';

    return (
        <>
            <Head title={`Settings · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Company settings"
                width="full"
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <div className="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm">
                    <span className="text-muted-foreground">Owner</span>
                    <span className="font-medium">
                        {company.owner
                            ? `${company.owner.name} (${company.owner.email})`
                            : '—'}
                    </span>
                </div>

                <AppFormCard>
                    <AppFormSection
                        title="Company profile"
                        description="Legal identity, contact details, GST and data visibility for this company."
                    >
                        {canEditProfile ? (
                            <Form
                                {...OwnerSettingsController.update.form()}
                                options={{ preserveScroll: true }}
                                className="space-y-6"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <AppFormGrid cols={3}>
                                            <Field
                                                id="name"
                                                label="Company name"
                                                name="name"
                                                defaultValue={company.name}
                                                error={errors.name}
                                                required
                                            />
                                            <Field
                                                id="pan"
                                                label="PAN"
                                                name="pan"
                                                defaultValue={company.pan ?? ''}
                                                error={errors.pan}
                                                required
                                                maxLength={10}
                                            />
                                            <Field
                                                id="pin"
                                                label="PIN"
                                                name="pin"
                                                defaultValue={company.pin ?? ''}
                                                error={errors.pin}
                                                required
                                                maxLength={6}
                                            />
                                            <div className="space-y-2 md:col-span-3">
                                                <Label
                                                    htmlFor="registered_address"
                                                    required
                                                >
                                                    Registered address
                                                </Label>
                                                <textarea
                                                    id="registered_address"
                                                    name="registered_address"
                                                    required
                                                    rows={3}
                                                    defaultValue={
                                                        company.registered_address ??
                                                        ''
                                                    }
                                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                                />
                                                <InputError
                                                    message={
                                                        errors.registered_address
                                                    }
                                                />
                                            </div>
                                            <div className="md:col-span-2">
                                                <StateCityFields
                                                    required
                                                    canQuickCreate={
                                                        canQuickCreateGeography
                                                    }
                                                    stateId={stateId}
                                                    cityId={cityId}
                                                    stateName={stateName}
                                                    cityName={cityName}
                                                    stateError={
                                                        errors.state_id ??
                                                        errors.state
                                                    }
                                                    cityError={
                                                        errors.city_id ??
                                                        errors.city
                                                    }
                                                    onChange={(next) => {
                                                        setStateId(
                                                            next.state_id,
                                                        );
                                                        setCityId(next.city_id);
                                                        setStateName(
                                                            next.state,
                                                        );
                                                        setCityName(next.city);
                                                    }}
                                                />
                                            </div>
                                            <Field
                                                id="contact_person"
                                                label="Contact person"
                                                name="contact_person"
                                                defaultValue={
                                                    company.contact_person ?? ''
                                                }
                                                error={errors.contact_person}
                                            />
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor="mobile"
                                                    required
                                                >
                                                    Mobile
                                                </Label>
                                                <MobileNumberInput
                                                    id="mobile"
                                                    name="mobile"
                                                    required
                                                    defaultValue={
                                                        company.mobile ?? ''
                                                    }
                                                    aria-invalid={
                                                        Boolean(
                                                            errors.mobile,
                                                        ) || undefined
                                                    }
                                                />
                                                <InputError
                                                    message={errors.mobile}
                                                />
                                            </div>
                                            <Field
                                                id="email"
                                                label="Email"
                                                name="email"
                                                type="email"
                                                defaultValue={
                                                    company.email ?? ''
                                                }
                                                error={errors.email}
                                            />
                                        </AppFormGrid>

                                        <AppFormSection title="GST & visibility">
                                            <AppFormGrid cols={4}>
                                                <SelectField
                                                    id="gst_registration_type"
                                                    label="GST registration type"
                                                    name="gst_registration_type"
                                                    value={gstType}
                                                    options={
                                                        options.gst_registration_types
                                                    }
                                                    onChange={setGstType}
                                                    error={
                                                        errors.gst_registration_type
                                                    }
                                                    required
                                                />
                                                <SelectField
                                                    id="tax_liability"
                                                    label="Tax liability"
                                                    name="tax_liability"
                                                    defaultValue={
                                                        company.tax_liability ??
                                                        'fcm'
                                                    }
                                                    options={
                                                        options.tax_liabilities
                                                    }
                                                    error={errors.tax_liability}
                                                    required
                                                />
                                                {needsGstin ? (
                                                    <Field
                                                        id="gstin"
                                                        label="GSTIN"
                                                        name="gstin"
                                                        defaultValue={
                                                            company.gstin ?? ''
                                                        }
                                                        error={errors.gstin}
                                                        required
                                                        maxLength={15}
                                                    />
                                                ) : null}
                                                {needsGstin ? (
                                                    <div className="space-y-2">
                                                        <Label
                                                            htmlFor="gst_rate_id"
                                                            required
                                                        >
                                                            GST rate
                                                        </Label>
                                                        <select
                                                            id="gst_rate_id"
                                                            name="gst_rate_id"
                                                            required
                                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                                            defaultValue={
                                                                company.gst_rate_id ??
                                                                gstRates[0]
                                                                    ?.id ??
                                                                ''
                                                            }
                                                        >
                                                            {gstRates.map(
                                                                (rate) => (
                                                                    <option
                                                                        key={
                                                                            rate.id
                                                                        }
                                                                        value={
                                                                            rate.id
                                                                        }
                                                                    >
                                                                        {rate.label ??
                                                                            `${rate.rate_percent}%`}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <InputError
                                                            message={
                                                                errors.gst_rate_id
                                                            }
                                                        />
                                                    </div>
                                                ) : null}
                                                <SelectField
                                                    id="data_visibility"
                                                    label="Data visibility"
                                                    name="data_visibility"
                                                    defaultValue={
                                                        company.data_visibility ??
                                                        'shared_data'
                                                    }
                                                    options={
                                                        options.data_visibilities
                                                    }
                                                    error={
                                                        errors.data_visibility
                                                    }
                                                    required
                                                />
                                            </AppFormGrid>
                                        </AppFormSection>

                                        <input
                                            type="hidden"
                                            name="timezone"
                                            value={company.timezone}
                                        />
                                        <div className="max-w-xl space-y-2">
                                            <Label htmlFor="remark" required>
                                                Remark
                                            </Label>
                                            <Input
                                                id="remark"
                                                name="remark"
                                                required
                                                maxLength={1000}
                                                placeholder="Reason for this change"
                                            />
                                            <InputError
                                                message={errors.remark}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            data-test="update-company-profile"
                                        >
                                            Save profile
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Profile editing is read-only while the company
                                is deactivated.
                            </p>
                        )}
                    </AppFormSection>
                </AppFormCard>

                {deferredSections.length > 0 ? (
                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {deferredSections.map((section) => (
                            <div
                                key={section.key}
                                className="rounded-xl border border-dashed border-border p-4"
                            >
                                <h2 className="font-medium">{section.title}</h2>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {section.message}
                                </p>
                            </div>
                        ))}
                    </section>
                ) : null}

                <div>
                    <Button asChild variant="secondary">
                        <Link href={links.dashboard}>Back to Owner Panel</Link>
                    </Button>
                </div>
            </CompanyOwnerPageShell>
        </>
    );
}

function Field({
    id,
    label,
    name,
    error,
    type = 'text',
    required = false,
    defaultValue,
    maxLength,
}: {
    id: string;
    label: string;
    name: string;
    error?: string;
    type?: string;
    required?: boolean;
    defaultValue?: string;
    maxLength?: number;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id} required={required}>
                {label}
            </Label>
            <Input
                id={id}
                name={name}
                type={type}
                required={required}
                defaultValue={defaultValue}
                maxLength={maxLength}
            />
            <InputError message={error} />
        </div>
    );
}

function SelectField({
    id,
    label,
    name,
    options,
    error,
    defaultValue,
    value,
    onChange,
    required = false,
}: {
    id: string;
    label: string;
    name: string;
    options: Option[];
    error?: string;
    defaultValue?: string;
    value?: string;
    onChange?: (value: string) => void;
    required?: boolean;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id} required={required}>
                {label}
            </Label>
            <select
                id={id}
                name={name}
                required={required}
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                defaultValue={value === undefined ? defaultValue : undefined}
                value={value}
                onChange={
                    onChange
                        ? (event) => onChange(event.target.value)
                        : undefined
                }
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}
