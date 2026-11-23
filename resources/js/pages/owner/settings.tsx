import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import OwnerSettingsController from '@/actions/App/Http/Controllers/Owner/OwnerSettingsController';
import { CompanySwitcher } from '@/components/company-switcher';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type DeferredSection = {
    key: string;
    title: string;
    message: string;
};

type Option = { value: string; label: string };
type GstRateOption = {
    id: string;
    code: string;
    name: string;
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
    const { flash } = usePage<FlashPageProps>().props;
    const [gstType, setGstType] = useState(
        company.gst_registration_type ?? 'regular',
    );
    const needsGstin = gstType !== 'unregistered';

    return (
        <>
            <Head title={`Settings · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Company settings
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <WorkspaceStatusBadge status={company.status} />
                            <span>Scope: {ownerPanelScope}</span>
                        </div>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <section className="max-w-3xl space-y-4 rounded-xl border border-border p-4">
                    <div>
                        <h2 className="font-medium">Company profile</h2>
                        <p className="text-sm text-muted-foreground">
                            PRD §7 legal, contact, GST and visibility settings.
                        </p>
                    </div>

                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <div className="flex justify-between gap-4 sm:col-span-2">
                            <dt className="text-muted-foreground">Owner</dt>
                            <dd className="text-right">
                                {company.owner
                                    ? `${company.owner.name} (${company.owner.email})`
                                    : '—'}
                            </dd>
                        </div>
                    </dl>

                    {canEditProfile ? (
                        <Form
                            {...OwnerSettingsController.update.form(company.id)}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
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
                                    <div className="space-y-2">
                                        <Label htmlFor="registered_address">
                                            Registered address
                                        </Label>
                                        <textarea
                                            id="registered_address"
                                            name="registered_address"
                                            required
                                            rows={3}
                                            defaultValue={
                                                company.registered_address ?? ''
                                            }
                                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                        />
                                        <InputError
                                            message={errors.registered_address}
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Field
                                            id="state"
                                            label="State"
                                            name="state"
                                            defaultValue={company.state ?? ''}
                                            error={errors.state}
                                            required
                                        />
                                        <Field
                                            id="city"
                                            label="City"
                                            name="city"
                                            defaultValue={company.city ?? ''}
                                            error={errors.city}
                                            required
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
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id="mobile"
                                            label="Mobile"
                                            name="mobile"
                                            defaultValue={company.mobile ?? ''}
                                            error={errors.mobile}
                                            required
                                        />
                                        <Field
                                            id="email"
                                            label="Email"
                                            name="email"
                                            type="email"
                                            defaultValue={company.email ?? ''}
                                            error={errors.email}
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <SelectField
                                            id="gst_registration_type"
                                            label="GST registration type"
                                            name="gst_registration_type"
                                            value={gstType}
                                            options={
                                                options.gst_registration_types
                                            }
                                            onChange={setGstType}
                                            error={errors.gst_registration_type}
                                        />
                                        <SelectField
                                            id="tax_liability"
                                            label="Tax liability"
                                            name="tax_liability"
                                            defaultValue={
                                                company.tax_liability ?? 'fcm'
                                            }
                                            options={options.tax_liabilities}
                                            error={errors.tax_liability}
                                        />
                                    </div>
                                    {needsGstin ? (
                                        <Field
                                            id="gstin"
                                            label="GSTIN"
                                            name="gstin"
                                            defaultValue={company.gstin ?? ''}
                                            error={errors.gstin}
                                            required
                                            maxLength={15}
                                        />
                                    ) : null}
                                    {needsGstin ? (
                                        <div className="space-y-2">
                                            <Label htmlFor="gst_rate_id">
                                                GST rate
                                            </Label>
                                            <select
                                                id="gst_rate_id"
                                                name="gst_rate_id"
                                                required
                                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                                defaultValue={
                                                    company.gst_rate_id ??
                                                    gstRates[0]?.id ??
                                                    ''
                                                }
                                            >
                                                {gstRates.map((rate) => (
                                                    <option
                                                        key={rate.id}
                                                        value={rate.id}
                                                    >
                                                        {rate.code} —{' '}
                                                        {rate.name} (
                                                        {rate.rate_percent}%)
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.gst_rate_id}
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
                                        options={options.data_visibilities}
                                        error={errors.data_visibility}
                                    />
                                    <input
                                        type="hidden"
                                        name="timezone"
                                        value={company.timezone}
                                    />
                                    <div className="space-y-2">
                                        <Label htmlFor="remark">
                                            Remark (required)
                                        </Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required
                                            maxLength={1000}
                                            placeholder="Reason for this change"
                                        />
                                        <InputError message={errors.remark} />
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
                            Profile editing is read-only while the company is
                            deactivated.
                        </p>
                    )}
                </section>

                <section className="grid gap-4 md:grid-cols-2">
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

                <div>
                    <Button asChild variant="secondary">
                        <Link href={links.dashboard}>Back to Owner Panel</Link>
                    </Button>
                </div>
            </div>
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
            <Label htmlFor={id}>{label}</Label>
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
}: {
    id: string;
    label: string;
    name: string;
    options: Option[];
    error?: string;
    defaultValue?: string;
    value?: string;
    onChange?: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={name}
                required
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
