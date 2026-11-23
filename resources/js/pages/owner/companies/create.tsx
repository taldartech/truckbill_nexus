import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import OwnerCompaniesController from '@/actions/App/Http/Controllers/Owner/OwnerCompaniesController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };
type GstRateOption = {
    id: string;
    code: string;
    name: string;
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
    const [gstType, setGstType] = useState(defaults.gst_registration_type);
    const [createBranch, setCreateBranch] = useState(
        defaults.create_initial_branch,
    );
    const needsGstin = gstType !== 'unregistered';

    return (
        <>
            <Head title="Create company" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Owner · Create company
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        New company
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Complete PRD §7 company details. First eligible owned
                        company receives the applicable trial.
                    </p>
                </div>

                <Form
                    {...OwnerCompaniesController.store.form()}
                    className="space-y-6 rounded-xl border border-border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <section className="space-y-4">
                                <h2 className="font-medium">
                                    Company identity
                                </h2>
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
                                <div className="space-y-2">
                                    <Label htmlFor="registered_address">
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
                                        message={errors.registered_address}
                                    />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field
                                        id="state"
                                        label="State"
                                        name="state"
                                        required
                                        error={errors.state}
                                    />
                                    <Field
                                        id="city"
                                        label="City"
                                        name="city"
                                        required
                                        error={errors.city}
                                    />
                                    <Field
                                        id="pin"
                                        label="PIN"
                                        name="pin"
                                        required
                                        maxLength={6}
                                        error={errors.pin}
                                    />
                                </div>
                            </section>

                            <section className="space-y-4">
                                <h2 className="font-medium">Contact</h2>
                                <Field
                                    id="contact_person"
                                    label="Contact person"
                                    name="contact_person"
                                    defaultValue={defaults.contact_person}
                                    error={errors.contact_person}
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        id="mobile"
                                        label="Mobile"
                                        name="mobile"
                                        required
                                        defaultValue={defaults.mobile}
                                        error={errors.mobile}
                                    />
                                    <Field
                                        id="email"
                                        label="Email"
                                        name="email"
                                        type="email"
                                        defaultValue={defaults.email}
                                        error={errors.email}
                                    />
                                </div>
                            </section>

                            <section className="space-y-4">
                                <h2 className="font-medium">GST</h2>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <SelectField
                                        id="gst_registration_type"
                                        label="Registration type"
                                        name="gst_registration_type"
                                        value={gstType}
                                        options={options.gst_registration_types}
                                        onChange={setGstType}
                                        error={errors.gst_registration_type}
                                    />
                                    <SelectField
                                        id="tax_liability"
                                        label="Tax liability"
                                        name="tax_liability"
                                        defaultValue={defaults.tax_liability}
                                        options={options.tax_liabilities}
                                        error={errors.tax_liability}
                                    />
                                </div>
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
                                        <Label htmlFor="gst_rate_id">
                                            GST rate
                                        </Label>
                                        <select
                                            id="gst_rate_id"
                                            name="gst_rate_id"
                                            required
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                            defaultValue={gstRates[0]?.id ?? ''}
                                        >
                                            {gstRates.map((rate) => (
                                                <option
                                                    key={rate.id}
                                                    value={rate.id}
                                                >
                                                    {rate.code} — {rate.name} (
                                                    {rate.rate_percent}%)
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.gst_rate_id}
                                        />
                                    </div>
                                ) : null}
                            </section>

                            <section className="space-y-4">
                                <h2 className="font-medium">Configuration</h2>
                                <SelectField
                                    id="data_visibility"
                                    label="Data visibility"
                                    name="data_visibility"
                                    defaultValue={defaults.data_visibility}
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
                                    Create an initial branch now (optional)
                                </label>
                                {createBranch ? (
                                    <Field
                                        id="initial_branch_name"
                                        label="Initial branch name"
                                        name="initial_branch_name"
                                        defaultValue={
                                            defaults.initial_branch_name
                                        }
                                        error={errors.initial_branch_name}
                                    />
                                ) : null}
                            </section>

                            <section className="space-y-2 rounded-lg border border-dashed border-border p-3 text-sm text-muted-foreground">
                                <h2 className="font-medium text-foreground">
                                    Logo &amp; signature
                                </h2>
                                <p>
                                    Private logo and digital signature upload is
                                    approved (PRD §7). Upload controls attach
                                    after the company exists via Owner Settings
                                    document-asset controls (PrivateFile tenant
                                    context).
                                </p>
                            </section>

                            <section className="space-y-4">
                                <h2 className="font-medium">
                                    Optional opening bank account
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Provide all three fields to create the first
                                    Bank Account. Branch-wise Restricted
                                    companies need an initial branch first.
                                </p>
                                <Field
                                    id="bank_name"
                                    label="Bank name"
                                    name="bank_name"
                                    error={errors.bank_name}
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        id="account_number"
                                        label="Account number"
                                        name="account_number"
                                        error={errors.account_number}
                                    />
                                    <Field
                                        id="ifsc"
                                        label="IFSC"
                                        name="ifsc"
                                        error={errors.ifsc}
                                    />
                                </div>
                            </section>

                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={processing}>
                                    Create company
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={links.workspace}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
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
