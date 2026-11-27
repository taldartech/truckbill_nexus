import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MobileNumberInput } from '@/components/ui/mobile-number-input';
import {
    AppFormCard,
    AppFormGrid,
    AppFormPage,
    AppFormSection,
} from '@/components/app-form-page';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';
import { WorkspaceContextAction } from '@/components/workspace-context-action';
import { CreditCard } from 'lucide-react';

type MemberOption = {
    user_id: number;
    name: string | null;
    email: string | null;
};

type RoleOption = {
    id: string;
    name: string;
    slug: string;
};

type CompanyOption = {
    id: string;
    name: string;
    status: string;
    available_branch_slots: number;
};

type OwnerBranchesCreateProps = {
    mode?: 'global' | 'company';
    company: { id: string; name: string; status: string } | null;
    companyLocked?: boolean;
    companyOptions?: CompanyOption[];
    ownerPanelScope: string;
    capacity: {
        branch_limit: number;
        used_branches: number;
        available_branch_slots: number;
    } | null;
    canCreate: boolean;
    existingHeadOffice?: { id: string; name: string } | null;
    companyGst?: {
        gst_registration_type: string | null;
        tax_liability: string | null;
        gstin: string | null;
        gst_rate_id: string | null;
        gst_rate_snapshot: Record<string, unknown> | null;
    };
    gstOptions?: {
        registration_types: Array<{ value: string; label: string }>;
        tax_liabilities: Array<{ value: string; label: string }>;
        gst_rates: Array<{
            id: string;
            label?: string;
            rate_percent: string;
        }>;
    };
    members: MemberOption[];
    customerRoles: RoleOption[];
    links: {
        index: string;
        store: string | null;
        create?: string;
        subscription?: string | null;
        subscription_redirect?: string | null;
    };
};

type AssignmentDraft = {
    user_id: string;
    role_id: string;
};

type PageErrors = {
    errors?: Record<string, string>;
};

export default function OwnerBranchesCreate({
    mode = 'company',
    company,
    companyLocked = false,
    companyOptions = [],
    capacity,
    canCreate,
    existingHeadOffice = null,
    companyGst,
    gstOptions,
    members,
    customerRoles,
    links,
}: OwnerBranchesCreateProps) {
    const page = usePage<PageErrors>();
    const { errors } = page.props;
    const canQuickCreateGeography = readCanQuickCreateGeography(page.props);
    const [assignments, setAssignments] = useState<AssignmentDraft[]>([]);
    const [branchName, setBranchName] = useState('');
    const [contactNumber, setContactNumber] = useState('');
    const [isHeadOffice, setIsHeadOffice] = useState('0');
    const [address, setAddress] = useState('');
    const [stateId, setStateId] = useState('');
    const [cityId, setCityId] = useState('');
    const [state, setState] = useState('');
    const [city, setCity] = useState('');
    const [pin, setPin] = useState('');
    const [inheritGst, setInheritGst] = useState(true);
    const [gstRegistrationType, setGstRegistrationType] = useState(
        companyGst?.gst_registration_type ?? '',
    );
    const [taxLiability, setTaxLiability] = useState(
        companyGst?.tax_liability ?? '',
    );
    const [gstin, setGstin] = useState(companyGst?.gstin ?? '');
    const [gstRateId, setGstRateId] = useState(companyGst?.gst_rate_id ?? '');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const submitAllowed = useMemo(() => ({ current: false }), []);

    const companyComboboxOptions: ComboboxOption[] = companyOptions.map(
        (option) => ({
            id: option.id,
            label: option.name,
            description: `${option.status} · ${option.available_branch_slots} slot(s) available`,
        }),
    );

    const selectCompany = (companyId: string) => {
        if (!links.create || companyId === '') {
            return;
        }
        router.get(
            links.create,
            { company: companyId },
            { preserveState: false },
        );
    };

    const addAssignment = () => {
        setAssignments((current) => [
            ...current,
            {
                user_id: members[0] ? String(members[0].user_id) : '',
                role_id: customerRoles[0]?.id ?? '',
            },
        ]);
    };

    const updateAssignment = (
        index: number,
        field: keyof AssignmentDraft,
        value: string,
    ) => {
        setAssignments((current) =>
            current.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [field]: value } : row,
            ),
        );
    };

    const removeAssignment = (index: number) => {
        setAssignments((current) =>
            current.filter((_, rowIndex) => rowIndex !== index),
        );
    };

    const openConfirmation = () => {
        setReviewFields([
            { label: 'Company', value: company?.name ?? '—' },
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
                label: 'GSTIN',
                value: inheritGst
                    ? (companyGst?.gstin ?? '—')
                    : gstin.trim() || '—',
            },
            {
                label: 'Capacity remaining',
                value:
                    capacity !== null
                        ? String(capacity.available_branch_slots)
                        : '—',
            },
            {
                label: 'Member assignments',
                value:
                    assignments.length === 0
                        ? 'None'
                        : `${assignments.length} assignment(s)`,
            },
        ]);
        setConfirmOpen(true);
    };

    const title = company ? `Create branch · ${company.name}` : 'Create branch';

    return (
        <>
            <Head title={title} />
            <AppFormPage
                title="Create branch"
                description={
                    mode === 'global'
                        ? 'Select the company this branch belongs to. This does not change your operational workspace.'
                        : `Creating a branch for ${company?.name ?? 'the selected company'}.`
                }
                actions={
                    <Button asChild variant="outline">
                        <Link href={links.index}>Back to branches</Link>
                    </Button>
                }
            >
                {mode === 'global' ? (
                    <AppFormCard className="mb-6">
                        <SearchableCombobox
                            id="target_company"
                            name="target_company_display"
                            label="Company"
                            options={companyComboboxOptions}
                            value={company?.id ?? ''}
                            onValueChange={(next) => selectCompany(next)}
                            required
                            clearable={false}
                            disabled={companyLocked}
                            placeholder="Search owned companies…"
                            emptyMessage="No eligible active companies."
                        />
                        {!company ? (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Choose a company to load capacity and continue.
                            </p>
                        ) : null}
                    </AppFormCard>
                ) : null}

                {company && company.status !== 'Active' ? (
                    <ShellEmptyState
                        title="Company deactivated"
                        description="Branch creation is unavailable for this deactivated company. Select a different active company."
                    />
                ) : !company ? null : !canCreate ? (
                    <ShellEmptyState
                        title="Branch capacity reached"
                        description="Purchase additional branch capacity for this company before creating another branch. This is a capacity limit — the company is not deactivated."
                        action={
                            links.subscription ? (
                                <WorkspaceContextAction
                                    contextUrl={links.subscription}
                                    redirect={
                                        links.subscription_redirect ??
                                        '/management/subscription'
                                    }
                                    icon={CreditCard}
                                    label="Open subscription"
                                />
                            ) : undefined
                        }
                    />
                ) : links.store ? (
                    <Form
                        action={links.store}
                        method="post"
                        className="space-y-6"
                        onBefore={() => {
                            if (!submitAllowed.current) {
                                openConfirmation();
                                return false;
                            }
                            submitAllowed.current = false;
                            return true;
                        }}
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <AppFormCard>
                                    <AppFormSection title="Branch details">
                                        {companyLocked || mode === 'company' ? (
                                            <div className="mb-4 space-y-1">
                                                <Label>Company</Label>
                                                <p className="text-sm font-medium">
                                                    {company.name}
                                                </p>
                                            </div>
                                        ) : null}

                                        {capacity ? (
                                            <p className="mb-4 text-sm text-muted-foreground">
                                                Capacity:{' '}
                                                {capacity.used_branches} used of{' '}
                                                {capacity.branch_limit} ·{' '}
                                                {
                                                    capacity.available_branch_slots
                                                }{' '}
                                                available
                                            </p>
                                        ) : null}

                                        <AppFormGrid cols={3}>
                                            <div className="space-y-2 md:col-span-2">
                                                <Label
                                                    htmlFor="branch-name"
                                                    required
                                                >
                                                    Branch name
                                                </Label>
                                                <Input
                                                    id="branch-name"
                                                    name="name"
                                                    required
                                                    maxLength={255}
                                                    autoFocus
                                                    value={branchName}
                                                    onChange={(e) =>
                                                        setBranchName(
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        formErrors.name ??
                                                        errors?.name
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="contact_number">
                                                    Contact number
                                                </Label>
                                                <MobileNumberInput
                                                    id="contact_number"
                                                    name="contact_number"
                                                    placeholder="10-digit mobile"
                                                    value={contactNumber}
                                                    onChange={(e) =>
                                                        setContactNumber(
                                                            e.target.value,
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
                                                <Label
                                                    htmlFor="head_office"
                                                    required
                                                >
                                                    Head Office
                                                </Label>
                                                <select
                                                    id="head_office"
                                                    name="is_head_office"
                                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                                    value={isHeadOffice}
                                                    onChange={(e) =>
                                                        setIsHeadOffice(
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="0">
                                                        No
                                                    </option>
                                                    <option
                                                        value="1"
                                                        disabled={
                                                            existingHeadOffice !==
                                                            null
                                                        }
                                                    >
                                                        Yes
                                                    </option>
                                                </select>
                                                {existingHeadOffice ? (
                                                    <p className="text-xs text-amber-700">
                                                        Existing Head Office:{' '}
                                                        {
                                                            existingHeadOffice.name
                                                        }
                                                        . Clear that designation
                                                        before assigning
                                                        another.
                                                    </p>
                                                ) : (
                                                    <p className="text-xs text-muted-foreground">
                                                        Only one Head Office is
                                                        allowed per company.
                                                    </p>
                                                )}
                                            </div>
                                            <div className="space-y-2 md:col-span-3">
                                                <Label htmlFor="address">
                                                    Address
                                                </Label>
                                                <textarea
                                                    id="address"
                                                    name="address"
                                                    rows={3}
                                                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                                    value={address}
                                                    onChange={(e) =>
                                                        setAddress(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Branch address"
                                                />
                                            </div>
                                            <div className="md:col-span-2">
                                                <StateCityFields
                                                    canQuickCreate={
                                                        canQuickCreateGeography
                                                    }
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
                                                        setStateId(
                                                            next.state_id,
                                                        );
                                                        setCityId(next.city_id);
                                                        setState(next.state);
                                                        setCity(next.city);
                                                    }}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="pin">
                                                    Pincode
                                                </Label>
                                                <Input
                                                    id="pin"
                                                    name="pin"
                                                    value={pin}
                                                    onChange={(e) =>
                                                        setPin(e.target.value)
                                                    }
                                                />
                                            </div>
                                        </AppFormGrid>
                                    </AppFormSection>
                                </AppFormCard>

                                <AppFormCard>
                                    <AppFormSection title="GST configuration">
                                        <label className="mb-4 flex items-center gap-2 text-sm font-medium">
                                            <input
                                                type="checkbox"
                                                checked={inheritGst}
                                                onChange={(e) =>
                                                    setInheritGst(
                                                        e.target.checked,
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
                                            <dl className="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        GSTIN
                                                    </dt>
                                                    <dd>
                                                        {companyGst?.gstin ??
                                                            '—'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Scheme
                                                    </dt>
                                                    <dd>
                                                        {companyGst?.tax_liability?.toUpperCase() ??
                                                            '—'}
                                                    </dd>
                                                </div>
                                            </dl>
                                        ) : (
                                            <AppFormGrid cols={4}>
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
                                                        value={
                                                            gstRegistrationType
                                                        }
                                                        onChange={(e) =>
                                                            setGstRegistrationType(
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    >
                                                        <option value="">
                                                            Select…
                                                        </option>
                                                        {(
                                                            gstOptions?.registration_types ??
                                                            []
                                                        ).map((option) => (
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
                                                        ))}
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
                                                        onChange={(e) =>
                                                            setTaxLiability(
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    >
                                                        <option value="">
                                                            Select…
                                                        </option>
                                                        {(
                                                            gstOptions?.tax_liabilities ??
                                                            []
                                                        ).map((option) => (
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
                                                        ))}
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
                                                        onChange={(e) =>
                                                            setGstin(
                                                                e.target.value.toUpperCase(),
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
                                                        onChange={(e) =>
                                                            setGstRateId(
                                                                e.target.value,
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            Select…
                                                        </option>
                                                        {(
                                                            gstOptions?.gst_rates ??
                                                            []
                                                        ).map((rate) => (
                                                            <option
                                                                key={rate.id}
                                                                value={rate.id}
                                                            >
                                                                {rate.label ??
                                                                    `${rate.rate_percent}%`}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                            </AppFormGrid>
                                        )}
                                    </AppFormSection>
                                </AppFormCard>

                                <AppFormCard>
                                    <AppFormSection
                                        title="Optional member assignments"
                                        description="Assign existing company members to this branch. Leave empty to assign later."
                                        actions={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={addAssignment}
                                                disabled={members.length === 0}
                                            >
                                                Add assignment
                                            </Button>
                                        }
                                    >
                                        {assignments.map((row, index) => (
                                            <div
                                                key={`assignment-${index}`}
                                                className="mb-3 grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-3"
                                            >
                                                <select
                                                    name={`assignments[${index}][user_id]`}
                                                    className="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                                    value={row.user_id}
                                                    onChange={(e) =>
                                                        updateAssignment(
                                                            index,
                                                            'user_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    {members.map((member) => (
                                                        <option
                                                            key={member.user_id}
                                                            value={
                                                                member.user_id
                                                            }
                                                        >
                                                            {member.name ??
                                                                member.email}
                                                        </option>
                                                    ))}
                                                </select>
                                                <select
                                                    name={`assignments[${index}][role_id]`}
                                                    className="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                                    value={row.role_id}
                                                    onChange={(e) =>
                                                        updateAssignment(
                                                            index,
                                                            'role_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                >
                                                    {customerRoles.map(
                                                        (role) => (
                                                            <option
                                                                key={role.id}
                                                                value={role.id}
                                                            >
                                                                {role.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        removeAssignment(index)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        ))}
                                    </AppFormSection>
                                </AppFormCard>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                    >
                                        Create branch
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        asChild
                                    >
                                        <Link href={links.index}>Cancel</Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                ) : null}
            </AppFormPage>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="create"
                title="Create this branch?"
                description="Confirm company, branch name, and capacity impact before submitting."
                fields={reviewFields}
                onConfirm={() => {
                    submitAllowed.current = true;
                    const form = document.querySelector(
                        'form[action]',
                    ) as HTMLFormElement | null;
                    form?.requestSubmit();
                }}
            />
        </>
    );
}
