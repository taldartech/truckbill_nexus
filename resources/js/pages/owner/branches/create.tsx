import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
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
    members,
    customerRoles,
    links,
}: OwnerBranchesCreateProps) {
    const { errors } = usePage<PageErrors>().props;
    const [assignments, setAssignments] = useState<AssignmentDraft[]>([]);
    const [branchName, setBranchName] = useState('');
    const [remark, setRemark] = useState('');
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
            { label: 'Remark', value: remark.trim() || '—' },
        ]);
        setConfirmOpen(true);
    };

    const title = company ? `Create branch · ${company.name}` : 'Create branch';

    return (
        <>
            <Head title={title} />
            <OwnerPageHeader
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
                    <div className="mb-6 max-w-xl space-y-2 rounded-xl border border-border p-4">
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
                            <p className="text-sm text-muted-foreground">
                                Choose a company to load capacity and continue.
                            </p>
                        ) : null}
                    </div>
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
                        className="max-w-2xl space-y-6 rounded-xl border border-border p-4"
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
                                {companyLocked || mode === 'company' ? (
                                    <div className="space-y-1">
                                        <Label>Company</Label>
                                        <p className="text-sm font-medium">
                                            {company.name}
                                        </p>
                                    </div>
                                ) : null}

                                {capacity ? (
                                    <p className="text-sm text-muted-foreground">
                                        Capacity: {capacity.used_branches} used
                                        of {capacity.branch_limit} ·{' '}
                                        {capacity.available_branch_slots}{' '}
                                        available
                                    </p>
                                ) : null}

                                <div className="space-y-2">
                                    <Label htmlFor="branch-name" required>
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
                                            setBranchName(e.target.value)
                                        }
                                    />
                                    <InputError
                                        message={
                                            formErrors.name ?? errors?.name
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="remark" required>
                                        Remark
                                    </Label>
                                    <Input
                                        id="remark"
                                        name="remark"
                                        required
                                        maxLength={1000}
                                        value={remark}
                                        onChange={(e) =>
                                            setRemark(e.target.value)
                                        }
                                        placeholder="Reason for creating this branch"
                                    />
                                    <InputError
                                        message={
                                            formErrors.remark ?? errors?.remark
                                        }
                                    />
                                </div>

                                <div className="space-y-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <Label>
                                            Optional member assignments
                                        </Label>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={addAssignment}
                                            disabled={members.length === 0}
                                        >
                                            Add assignment
                                        </Button>
                                    </div>
                                    {assignments.map((row, index) => (
                                        <div
                                            key={`assignment-${index}`}
                                            className="grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-3"
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
                                                        value={member.user_id}
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
                                                {customerRoles.map((role) => (
                                                    <option
                                                        key={role.id}
                                                        value={role.id}
                                                    >
                                                        {role.name}
                                                    </option>
                                                ))}
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
                                </div>

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
            </OwnerPageHeader>

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
