import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import OwnerBranchesController from '@/actions/App/Http/Controllers/Owner/OwnerBranchesController';
import InputError from '@/components/input-error';
import { FormSection } from '@/components/form-section';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateSmart } from '@/lib/dates';
import { branch as enterBranchContext } from '@/routes/workspace/context';

type GstRateOption = {
    id: string;
    code: string;
    name: string;
    rate_percent: string;
};

type OwnerBranchesShowProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    canEnterBranch: boolean;
    canDeactivate: boolean;
    branch: {
        id: string;
        name: string;
        contact_number: string | null;
        is_head_office: boolean;
        address: string | null;
        state: string | null;
        state_id: string | null;
        city: string | null;
        city_id: string | null;
        pin: string | null;
        gst_mode: string;
        gst_registration_type: string | null;
        tax_liability: string | null;
        gstin: string | null;
        gst_rate_id: string | null;
        effective_gstin: string | null;
        status: string;
        created_at: string | null;
        updated_at: string | null;
        deactivated_at: string | null;
    };
    effective_gst: {
        mode: string;
        inherits_company: boolean;
        gst_registration_type: string | null;
        tax_liability: string | null;
        gstin: string | null;
        gst_rate_snapshot: {
            code?: string;
            name?: string;
            rate_percent?: string;
        } | null;
        supplier_state: string | null;
    };
    company_gst: {
        gst_registration_type: string | null;
        tax_liability: string | null;
        gstin: string | null;
        gst_rate_id: string | null;
    };
    gst_options: {
        registration_types: Array<{ value: string; label: string }>;
        tax_liabilities: Array<{ value: string; label: string }>;
        gst_rates: GstRateOption[];
    };
    existing_head_office: { id: string; name: string } | null;
    assignments: Array<{
        id: string;
        user: {
            id: number | null;
            name: string | null;
            email: string | null;
        };
    }>;
    roleAssignments: Array<{
        id: string;
        role: { id: string | null; name: string | null; slug: string | null };
        user: {
            id: number | null;
            name: string | null;
            email: string | null;
        };
    }>;
    audit: Array<{
        id: number;
        action: string;
        remark: string | null;
        created_at: string | null;
        actor_id: string | null;
    }>;
    links: {
        index: string;
        update: string;
        deactivate: string;
        enter: string;
        users: string;
    };
};

type FlashPageProps = {
    flash?: { success?: string | null };
    errors?: Record<string, string>;
};

function formatTimestamp(value: string | null): string {
    return value ? formatDisplayDateSmart(value) : '—';
}

export default function OwnerBranchesShow({
    company,
    ownerPanelScope,
    canManage,
    canEnterBranch,
    canDeactivate,
    branch,
    effective_gst,
    company_gst,
    gst_options,
    existing_head_office,
    assignments,
    roleAssignments,
    audit,
    links,
}: OwnerBranchesShowProps) {
    const { flash, errors } = usePage<FlashPageProps>().props;
    const [confirmDeactivate, setConfirmDeactivate] = useState(false);

    const [branchName, setBranchName] = useState(branch.name);
    const [contactNumber, setContactNumber] = useState(
        branch.contact_number ?? '',
    );
    const [isHeadOffice, setIsHeadOffice] = useState(
        branch.is_head_office ? '1' : '0',
    );
    const [address, setAddress] = useState(branch.address ?? '');
    const [stateId, setStateId] = useState(branch.state_id ?? '');
    const [cityId, setCityId] = useState(branch.city_id ?? '');
    const [state, setState] = useState(branch.state ?? '');
    const [city, setCity] = useState(branch.city ?? '');
    const [pin, setPin] = useState(branch.pin ?? '');
    const [inheritGst, setInheritGst] = useState(
        branch.gst_mode !== 'independent',
    );
    const [gstRegistrationType, setGstRegistrationType] = useState(
        branch.gst_registration_type ?? company_gst.gst_registration_type ?? '',
    );
    const [taxLiability, setTaxLiability] = useState(
        branch.tax_liability ?? company_gst.tax_liability ?? '',
    );
    const [gstin, setGstin] = useState(branch.gstin ?? company_gst.gstin ?? '');
    const [gstRateId, setGstRateId] = useState(
        branch.gst_rate_id ?? company_gst.gst_rate_id ?? '',
    );
    const [remark, setRemark] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const submitAllowed = useMemo(() => ({ current: false }), []);

    const locationLabel = [branch.city, branch.state]
        .map((part) => part?.trim() ?? '')
        .filter(Boolean)
        .join(', ');

    const openConfirmation = () => {
        setReviewFields([
            { label: 'Company', value: company.name },
            { label: 'Branch name', value: branchName.trim() || '—' },
            {
                label: 'Contact number',
                value: contactNumber.trim() || '—',
            },
            {
                label: 'Head Office',
                value: isHeadOffice === '1' ? 'Yes' : 'No',
            },
            {
                label: 'Address',
                value:
                    [address, city, state, pin]
                        .map((part) => part.trim())
                        .filter(Boolean)
                        .join(', ') || '—',
            },
            {
                label: 'GST configuration',
                value: inheritGst
                    ? 'Inherit from Company'
                    : 'Independent Branch GST',
            },
            {
                label: 'Effective GSTIN',
                value: inheritGst
                    ? (company_gst.gstin ?? '—')
                    : gstin.trim() || '—',
            },
            { label: 'Remark', value: remark.trim() || '—' },
        ]);
        setConfirmOpen(true);
    };

    return (
        <>
            <Head title={`${branch.name} · Branches`} />
            <OwnerPageHeader
                title={branch.name}
                description={`${company.name} · Branch register · Scope: ${ownerPanelScope}`}
                actions={<WorkspaceStatusBadge status={branch.status} />}
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" asChild>
                        <Link href={links.index}>Back to branches</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={links.users}>Manage users</Link>
                    </Button>
                    {canEnterBranch ? (
                        <Form
                            {...enterBranchContext.form({
                                company: company.id,
                                branch: branch.id,
                            })}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="redirect"
                                        value="/operations"
                                    />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                    >
                                        Enter Branch Panel
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </div>

                <FormSection
                    title="Branch detail"
                    description="Register snapshot — effective GST is resolved server-side for documents."
                    columns={3}
                >
                    <DetailItem label="Contact" value={branch.contact_number} />
                    <DetailItem
                        label="Head Office"
                        value={branch.is_head_office ? 'Yes' : 'No'}
                    />
                    <DetailItem
                        label="City / State"
                        value={locationLabel || '—'}
                    />
                    <DetailItem label="Pincode" value={branch.pin} />
                    <DetailItem
                        label="GST mode"
                        value={
                            branch.gst_mode === 'independent'
                                ? 'Independent'
                                : 'Inherit company'
                        }
                    />
                    <DetailItem
                        label="Effective GSTIN"
                        value={effective_gst.gstin ?? '—'}
                        mono
                    />
                    <DetailItem
                        label="GST scheme"
                        value={
                            effective_gst.tax_liability?.toUpperCase() ?? '—'
                        }
                    />
                    <DetailItem
                        label="Supplier state"
                        value={effective_gst.supplier_state ?? '—'}
                    />
                    <DetailItem
                        label="Created"
                        value={formatTimestamp(branch.created_at)}
                    />
                    {branch.address ? (
                        <div className="space-y-1 md:col-span-3">
                            <dt className="text-xs font-medium text-muted-foreground uppercase">
                                Address
                            </dt>
                            <dd className="text-sm whitespace-pre-wrap">
                                {branch.address}
                            </dd>
                        </div>
                    ) : null}
                </FormSection>

                {canManage ? (
                    <Form
                        id="owner-branch-edit-form"
                        {...OwnerBranchesController.update.form({
                            branch: branch.id,
                        })}
                        className="space-y-6 rounded-xl border border-border p-4"
                        onSubmit={(event) => {
                            if (!submitAllowed.current) {
                                event.preventDefault();
                                openConfirmation();
                            }
                            submitAllowed.current = false;
                        }}
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <div className="space-y-1">
                                    <h2 className="font-medium">Edit branch</h2>
                                    <p className="text-sm text-muted-foreground">
                                        Update identity, address, and GST mode.
                                        User assignments remain under Users.
                                    </p>
                                </div>

                                <FormSection title="Identity" columns={2}>
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="edit-name" required>
                                            Branch name
                                        </Label>
                                        <Input
                                            id="edit-name"
                                            name="name"
                                            required
                                            maxLength={255}
                                            value={branchName}
                                            onChange={(event) =>
                                                setBranchName(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors.name ?? errors?.name
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="contact_number">
                                            Contact number
                                        </Label>
                                        <Input
                                            id="contact_number"
                                            name="contact_number"
                                            inputMode="numeric"
                                            maxLength={10}
                                            placeholder="10-digit mobile"
                                            value={contactNumber}
                                            onChange={(event) =>
                                                setContactNumber(
                                                    event.target.value.replace(
                                                        /\D/g,
                                                        '',
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                formErrors.contact_number ??
                                                errors?.contact_number
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="head_office" required>
                                            Head Office
                                        </Label>
                                        <select
                                            id="head_office"
                                            name="is_head_office"
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                            value={isHeadOffice}
                                            onChange={(event) =>
                                                setIsHeadOffice(
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="0">No</option>
                                            <option
                                                value="1"
                                                disabled={
                                                    existing_head_office !==
                                                    null
                                                }
                                            >
                                                Yes
                                            </option>
                                        </select>
                                        {existing_head_office ? (
                                            <p className="text-xs text-amber-700">
                                                Existing Head Office:{' '}
                                                {existing_head_office.name}
                                            </p>
                                        ) : null}
                                    </div>
                                </FormSection>

                                <FormSection title="Address" columns={2}>
                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="address">Address</Label>
                                        <textarea
                                            id="address"
                                            name="address"
                                            rows={3}
                                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                            value={address}
                                            onChange={(event) =>
                                                setAddress(event.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <StateCityFields
                                            stateId={stateId}
                                            cityId={cityId}
                                            stateName={state}
                                            cityName={city}
                                            stateError={
                                                formErrors.state_id ??
                                                errors?.state_id
                                            }
                                            cityError={
                                                formErrors.city_id ??
                                                errors?.city_id
                                            }
                                            onChange={(next) => {
                                                setStateId(next.state_id);
                                                setCityId(next.city_id);
                                                setState(next.state);
                                                setCity(next.city);
                                            }}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="pin">Pincode</Label>
                                        <Input
                                            id="pin"
                                            name="pin"
                                            value={pin}
                                            onChange={(event) =>
                                                setPin(event.target.value)
                                            }
                                        />
                                    </div>
                                </FormSection>

                                <FormSection
                                    title="GST configuration"
                                    columns={1}
                                >
                                    <label className="flex items-center gap-2 text-sm font-medium">
                                        <input
                                            type="checkbox"
                                            checked={inheritGst}
                                            onChange={(event) =>
                                                setInheritGst(
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        Inherit GST from Company
                                    </label>
                                    <input
                                        type="hidden"
                                        name="gst_mode"
                                        value={
                                            inheritGst
                                                ? 'inherit_company'
                                                : 'independent'
                                        }
                                    />
                                    {inheritGst ? (
                                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    GSTIN
                                                </dt>
                                                <dd>
                                                    {company_gst.gstin ?? '—'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Scheme
                                                </dt>
                                                <dd>
                                                    {company_gst.tax_liability?.toUpperCase() ??
                                                        '—'}
                                                </dd>
                                            </div>
                                        </dl>
                                    ) : (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor="gst_registration_type"
                                                    required
                                                >
                                                    GST registration type
                                                </Label>
                                                <select
                                                    id="gst_registration_type"
                                                    name="gst_registration_type"
                                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                                    value={gstRegistrationType}
                                                    onChange={(event) =>
                                                        setGstRegistrationType(
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="">
                                                        Select…
                                                    </option>
                                                    {gst_options.registration_types.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor="tax_liability"
                                                    required
                                                >
                                                    GST scheme
                                                </Label>
                                                <select
                                                    id="tax_liability"
                                                    name="tax_liability"
                                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                                    value={taxLiability}
                                                    onChange={(event) =>
                                                        setTaxLiability(
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="">
                                                        Select…
                                                    </option>
                                                    {gst_options.tax_liabilities.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="gstin">
                                                    GST number
                                                </Label>
                                                <Input
                                                    id="gstin"
                                                    name="gstin"
                                                    value={gstin}
                                                    onChange={(event) =>
                                                        setGstin(
                                                            event.target.value.toUpperCase(),
                                                        )
                                                    }
                                                    maxLength={15}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="gst_rate_id">
                                                    GST rate
                                                </Label>
                                                <select
                                                    id="gst_rate_id"
                                                    name="gst_rate_id"
                                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                                    value={gstRateId}
                                                    onChange={(event) =>
                                                        setGstRateId(
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select…
                                                    </option>
                                                    {gst_options.gst_rates.map(
                                                        (rate) => (
                                                            <option
                                                                key={rate.id}
                                                                value={rate.id}
                                                            >
                                                                {rate.name} (
                                                                {
                                                                    rate.rate_percent
                                                                }
                                                                %)
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                        </div>
                                    )}
                                </FormSection>

                                <div className="space-y-2">
                                    <Label htmlFor="edit-remark" required>
                                        Remark
                                    </Label>
                                    <Input
                                        id="edit-remark"
                                        name="remark"
                                        required
                                        maxLength={1000}
                                        value={remark}
                                        onChange={(event) =>
                                            setRemark(event.target.value)
                                        }
                                        placeholder="Reason for this update"
                                    />
                                    <InputError
                                        message={
                                            formErrors.remark ?? errors?.remark
                                        }
                                    />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    Save changes
                                </Button>
                            </>
                        )}
                    </Form>
                ) : null}

                <FormSection title="Assigned members" columns={1}>
                    {assignments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No explicit branch assignments (Owner access is
                            automatic).
                        </p>
                    ) : (
                        <ul className="space-y-2" role="list">
                            {assignments.map((assignment) => (
                                <li key={assignment.id} className="text-sm">
                                    {assignment.user.name} (
                                    {assignment.user.email})
                                </li>
                            ))}
                        </ul>
                    )}
                </FormSection>

                <FormSection title="Active roles" columns={1}>
                    {roleAssignments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No active Customer roles on this branch.
                        </p>
                    ) : (
                        <ul className="space-y-2" role="list">
                            {roleAssignments.map((assignment) => (
                                <li key={assignment.id} className="text-sm">
                                    {assignment.user.name} —{' '}
                                    {assignment.role.name}
                                </li>
                            ))}
                        </ul>
                    )}
                </FormSection>

                <FormSection title="Audit history" columns={1}>
                    {audit.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No audit entries for this branch yet.
                        </p>
                    ) : (
                        <ul className="space-y-2" role="list">
                            {audit.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="rounded-lg border border-border px-3 py-2 text-sm"
                                >
                                    <div className="font-medium">
                                        {entry.action}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {entry.remark ?? '—'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {formatTimestamp(entry.created_at)}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </FormSection>

                {canDeactivate ? (
                    <FormSection
                        title="Deactivate branch"
                        description="Soft deactivation with audit trail. The last active branch cannot be deactivated."
                        columns={1}
                    >
                        {!confirmDeactivate ? (
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => setConfirmDeactivate(true)}
                            >
                                Deactivate…
                            </Button>
                        ) : (
                            <Form
                                {...OwnerBranchesController.deactivate.form({
                                    branch: branch.id,
                                })}
                                className="max-w-xl space-y-4"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <div className="space-y-2">
                                            <Label
                                                htmlFor="deactivate-remark"
                                                required
                                            >
                                                Confirmation remark
                                            </Label>
                                            <Input
                                                id="deactivate-remark"
                                                name="remark"
                                                required
                                                maxLength={1000}
                                                placeholder="Why is this branch being deactivated?"
                                            />
                                            <InputError
                                                message={
                                                    formErrors.remark ??
                                                    errors?.remark
                                                }
                                            />
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Confirm deactivation
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setConfirmDeactivate(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </FormSection>
                ) : null}
            </OwnerPageHeader>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="edit"
                title="Save branch changes?"
                description="Confirm identity, address, and GST updates before submitting."
                fields={reviewFields}
                onConfirm={() => {
                    submitAllowed.current = true;
                    const form = document.getElementById(
                        'owner-branch-edit-form',
                    ) as HTMLFormElement | null;
                    form?.requestSubmit();
                }}
            />
        </>
    );
}

function DetailItem({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null | undefined;
    mono?: boolean;
}) {
    return (
        <div className="space-y-1">
            <dt className="text-xs font-medium text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className={`text-sm ${mono ? 'font-mono text-xs' : ''}`}>
                {value?.trim() ? value : '—'}
            </dd>
        </div>
    );
}
