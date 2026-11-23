import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import OwnerBranchesController from '@/actions/App/Http/Controllers/Owner/OwnerBranchesController';
import InputError from '@/components/input-error';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';

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

type OwnerBranchesCreateProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    capacity: {
        branch_limit: number;
        used_branches: number;
        available_branch_slots: number;
    };
    canCreate: boolean;
    members: MemberOption[];
    customerRoles: RoleOption[];
    links: {
        index: string;
        store: string;
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
    company,
    ownerPanelScope,
    capacity,
    canCreate,
    members,
    customerRoles,
    links,
}: OwnerBranchesCreateProps) {
    const { errors } = usePage<PageErrors>().props;
    const [assignments, setAssignments] = useState<AssignmentDraft[]>([]);

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

    return (
        <>
            <Head title={`Create branch · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Create branch"
                width="form"
            >
                {!canCreate ? (
                    <ShellEmptyState
                        title="Branch capacity reached"
                        description="Purchase additional branch capacity before creating another branch."
                    />
                ) : (
                    <Form
                        {...OwnerBranchesController.store.form()}
                        className="max-w-2xl space-y-6 rounded-xl border border-border p-4"
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="branch-name">
                                        Branch name
                                    </Label>
                                    <Input
                                        id="branch-name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        autoFocus
                                    />
                                    <InputError
                                        message={
                                            formErrors.name ?? errors?.name
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="branch-remark">
                                        Remark
                                    </Label>
                                    <Input
                                        id="branch-remark"
                                        name="remark"
                                        required
                                        maxLength={1000}
                                        placeholder="Why is this branch being created?"
                                    />
                                    <InputError
                                        message={
                                            formErrors.remark ?? errors?.remark
                                        }
                                    />
                                </div>

                                <div className="space-y-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <div>
                                            <h2 className="font-medium">
                                                Assign existing members
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Optional. Selects existing
                                                company members and Customer
                                                roles only. Does not create
                                                identities. The Owner already
                                                accesses every branch.
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={addAssignment}
                                            disabled={
                                                members.length === 0 ||
                                                customerRoles.length === 0
                                            }
                                        >
                                            Add member
                                        </Button>
                                    </div>

                                    {assignments.map((row, index) => (
                                        <div
                                            key={`assignment-${index}`}
                                            className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_1fr_auto]"
                                        >
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`assignments-${index}-user`}
                                                >
                                                    Member
                                                </Label>
                                                <select
                                                    id={`assignments-${index}-user`}
                                                    name={`assignments[${index}][user_id]`}
                                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                                    value={row.user_id}
                                                    onChange={(event) =>
                                                        updateAssignment(
                                                            index,
                                                            'user_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    {members.map((member) => (
                                                        <option
                                                            key={member.user_id}
                                                            value={
                                                                member.user_id
                                                            }
                                                        >
                                                            {member.name} (
                                                            {member.email})
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`assignments-${index}-role`}
                                                >
                                                    Role
                                                </Label>
                                                <select
                                                    id={`assignments-${index}-role`}
                                                    name={`assignments[${index}][role_id]`}
                                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                                    value={row.role_id}
                                                    onChange={(event) =>
                                                        updateAssignment(
                                                            index,
                                                            'role_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
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
                                            </div>
                                            <div className="flex items-end">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        removeAssignment(index)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Create branch
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link href={links.index}>Cancel</Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </CompanyOwnerPageShell>
        </>
    );
}
