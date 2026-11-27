import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { FormSection } from '@/components/form-section';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { PageShell } from '@/components/page-shell';
import { useState } from 'react';
import OwnerCompaniesController from '@/actions/App/Http/Controllers/Owner/OwnerCompaniesController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MobileNumberInput } from '@/components/ui/mobile-number-input';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';

type Option = { value: string; label: string };
type GstRateOption = {
    id: string;
    label: string;
    rate_percent: string;
};

type CreateCompanyProps = {
    defaults: {
        contact_person: string;
        email: string;
        mobile: string;
        timezone: string;
        gst_registration_type: string;
        tax_liability: string;
        data_visibility: string;
        create_initial_branch: boolean;
        initial_branch_name: string;
    };
    gstRates: GstRateOption[];
    options: {
        gst_registration_types: Option[];
        tax_liabilities: Option[];
        data_visibilities: Option[];
    };
    links: { store: string; workspace: string };
};

export default function OwnerCompanyCreate({
    defaults,
    gstRates,
    options,
    links,
}: CreateCompanyProps) {
    const canQuickCreateGeography = readCanQuickCreateGeography(
        usePage().props,
    );
    const [gstType, setGstType] = useState(defaults.gst_registration_type);
    const [createBranch, setCreateBranch] = useState(
        defaults.create_initial_branch,
    );
    const [stateId, setStateId] = useState('');
    const [cityId, setCityId] = useState('');
    const [stateName, setStateName] = useState('');
    const [cityName, setCityName] = useState('');
    const [bankName, setBankName] = useState('');
    const [accountNumber, setAccountNumber] = useState('');
    const [ifsc, setIfsc] = useState('');
    const needsGstin = gstType !== 'unregistered';
    const needsOpeningBank =
        bankName.trim() !== '' ||
        accountNumber.trim() !== '' ||
        ifsc.trim() !== '';

    return (
        <>
            <Head title="Create company" />
            <PageShell
                width="full"
                title="New company"
                description="Enter company legal and contact details. The first eligible company receives the applicable trial."
            >
                <Form
                    {...OwnerCompaniesController.store.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-6 lg:grid-cols-12">
                                <div className="space-y-5 lg:col-span-8">
                                    <FormSection
                                        title="Basic details"
                                        description="Legal identity and registered address."
                                        columns={2}
                                    >
                                        <Field
                                            id="name"
                                            label="Company name"
                                            name="name"
                                            required
                                            error={errors.name}
                                        />
                                        <Field
                                            id="pan"
                                            label="PAN"
                                            name="pan"
                                            required
                                            maxLength={10}
                                            error={errors.pan}
                                        />
                                        <div className="space-y-2 md:col-span-2">
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
                                                stateError={errors.state_id}
                                                cityError={errors.city_id}
                                                onChange={(next) => {
                                                    setStateId(next.state_id);
                                                    setCityId(next.city_id);
                                                    setStateName(next.state);
                                                    setCityName(next.city);
                                                }}
                                            />
                                            <InputError
                                                message={errors.state}
                                            />
                                            <InputError message={errors.city} />
                                        </div>
                                        <Field
                                            id="pin"
                                            label="PIN"
                                            name="pin"
                                            required
                                            maxLength={6}
                                            error={errors.pin}
                                        />
                                    </FormSection>

                                    <FormSection
                                        title="Tax registration"
                                        description="GST posture and default rate when GSTIN applies."
                                        columns={2}
                                    >
                                        <>
                                            <SelectField
                                                id="gst_registration_type"
                                                label="Registration type"
                                                name="gst_registration_type"
                                                value={gstType}
                                                options={
                                                    options.gst_registration_types
                                                }
                                                onChange={setGstType}
                                                error={
                                                    errors.gst_registration_type
                                                }
                                            />
                                            <SelectField
                                                id="tax_liability"
                                                label="Tax liability"
                                                name="tax_liability"
                                                defaultValue={
                                                    defaults.tax_liability
                                                }
                                                options={
                                                    options.tax_liabilities
                                                }
                                                error={errors.tax_liability}
                                            />
                                        </>
                                        {needsGstin ? (
                                            <Field
                                                id="gstin"
                                                label="GSTIN"
                                                name="gstin"
                                                required
                                                maxLength={15}
                                                error={errors.gstin}
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
                                                        gstRates[0]?.id ?? ''
                                                    }
                                                >
                                                    {gstRates.map((rate) => (
                                                        <option
                                                            key={rate.id}
                                                            value={rate.id}
                                                        >
                                                            {rate.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError
                                                    message={errors.gst_rate_id}
                                                />
                                            </div>
                                        ) : null}
                                    </FormSection>

                                    <FormSection
                                        title="Contact"
                                        description="Primary operator reachability for onboarding."
                                        columns={2}
                                    >
                                        <Field
                                            id="contact_person"
                                            label="Contact person"
                                            name="contact_person"
                                            defaultValue={
                                                defaults.contact_person
                                            }
                                            error={errors.contact_person}
                                        />
                                        <>
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
                                                        defaults.mobile
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
                                                defaultValue={defaults.email}
                                                error={errors.email}
                                            />
                                        </>
                                    </FormSection>

                                    <FormSection
                                        title="Optional opening bank account"
                                        description="Provide all three fields to create the first bank account. Branch-wise restricted companies need an initial branch first."
                                        columns={2}
                                    >
                                        <Field
                                            id="bank_name"
                                            label="Bank name"
                                            name="bank_name"
                                            value={bankName}
                                            onChange={setBankName}
                                            required={needsOpeningBank}
                                            error={errors.bank_name}
                                        />
                                        <>
                                            <Field
                                                id="account_number"
                                                label="Account number"
                                                name="account_number"
                                                value={accountNumber}
                                                onChange={setAccountNumber}
                                                required={needsOpeningBank}
                                                error={errors.account_number}
                                            />
                                            <Field
                                                id="ifsc"
                                                label="IFSC"
                                                name="ifsc"
                                                value={ifsc}
                                                onChange={setIfsc}
                                                required={needsOpeningBank}
                                                error={errors.ifsc}
                                            />
                                        </>
                                    </FormSection>
                                </div>

                                <aside className="space-y-5 lg:col-span-4">
                                    <FormSection
                                        title="Visibility & branch"
                                        description="Tenant data scope and optional first branch."
                                        columns={1}
                                    >
                                        <SelectField
                                            id="data_visibility"
                                            label="Data visibility"
                                            name="data_visibility"
                                            defaultValue={
                                                defaults.data_visibility
                                            }
                                            options={options.data_visibilities}
                                            error={errors.data_visibility}
                                        />
                                        <input
                                            type="hidden"
                                            name="timezone"
                                            value={defaults.timezone}
                                        />
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="create_initial_branch"
                                                value="1"
                                                checked={createBranch}
                                                onChange={(event) =>
                                                    setCreateBranch(
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                            Create an initial branch now
                                            (optional)
                                        </label>
                                        {createBranch ? (
                                            <Field
                                                id="initial_branch_name"
                                                label="Initial branch name"
                                                name="initial_branch_name"
                                                defaultValue={
                                                    defaults.initial_branch_name
                                                }
                                                error={
                                                    errors.initial_branch_name
                                                }
                                            />
                                        ) : null}
                                    </FormSection>

                                    <section className="space-y-2 rounded-xl border border-dashed border-border bg-muted/20 p-4 text-sm text-muted-foreground">
                                        <h2 className="font-medium text-foreground">
                                            Logo &amp; signature
                                        </h2>
                                        <p>
                                            Private logo and digital signature
                                            upload is approved. Upload controls
                                            attach after the company exists via
                                            Owner Settings document-asset
                                            controls (PrivateFile tenant
                                            context).
                                        </p>
                                    </section>
                                </aside>
                            </div>

                            <div className="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-between gap-3 border-t border-border bg-background/95 px-1 py-4 backdrop-blur supports-backdrop-filter:bg-background/80">
                                <p className="text-xs text-muted-foreground">
                                    Creates company workspace · trial applies
                                    when eligible
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        variant="default"
                                    >
                                        <Save className="size-4" />
                                        {processing
                                            ? 'Creating…'
                                            : 'Create company'}
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link href={links.workspace}>
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </Form>
            </PageShell>
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
    value,
    onChange,
    maxLength,
}: {
    id: string;
    label: string;
    name: string;
    error?: string;
    type?: string;
    required?: boolean;
    defaultValue?: string;
    value?: string;
    onChange?: (value: string) => void;
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
                defaultValue={value === undefined ? defaultValue : undefined}
                value={value}
                onChange={
                    onChange
                        ? (event) => onChange(event.target.value)
                        : undefined
                }
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
    required = true,
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
