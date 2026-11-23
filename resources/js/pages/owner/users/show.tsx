import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import OwnerUsersController from '@/actions/App/Http/Controllers/Owner/OwnerUsersController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type OwnerUsersShowProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    membership: {
        id: string;
        status: string;
        is_owner: boolean;
        user: { id: number | null; name: string | null; email: string | null };
        branch_assignments: Array<{
            id: string;
            status: string;
            branch: { id: string | null; name: string | null };
        }>;
        role_assignments: Array<{
            id: string;
            status: string;
            role: {
                id: string | null;
                name: string | null;
                slug: string | null;
            };
            branch: { id: string | null; name: string | null };
        }>;
    };
    assignableBranches: Array<{ id: string; name: string }>;
    customerRoles: Array<{ id: string; name: string; slug: string }>;
    links: {
        index: string;
        dashboard: string;
        deactivate: string;
    };
};

type FlashPageProps = {
    flash?: { success?: string | null };
};

export default function OwnerUsersShow({
    company,
    ownerPanelScope,
    canManage,
    membership,
    assignableBranches,
    customerRoles,
    links,
}: OwnerUsersShowProps) {
    const { flash } = usePage<FlashPageProps>().props;
    const activeBranchAssignments = membership.branch_assignments.filter(
        (assignment) => assignment.status === 'Active',
    );
    const [assignBranchId, setAssignBranchId] = useState(
        assignableBranches[0]?.id ?? '',
    );
    const [roleBranchId, setRoleBranchId] = useState(
        activeBranchAssignments[0]?.branch.id ?? '',
    );
    const assignBranchForm = useMemo(
        () =>
            assignBranchId
                ? OwnerUsersController.assignBranch.form({
                      membership: membership.id,
                      branch: assignBranchId,
                  })
                : null,
        [assignBranchId, company.id, membership.id],
    );
    const assignRoleForm = useMemo(
        () =>
            roleBranchId
                ? OwnerUsersController.assignRole.form({
                      membership: membership.id,
                      branch: roleBranchId,
                  })
                : null,
        [company.id, membership.id, roleBranchId],
    );

    return (
        <>
            <Head
                title={`${membership.user.name ?? 'User'} · ${company.name}`}
            />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Membership
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {membership.user.name}
                            {membership.is_owner ? ' · Owner' : ''}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {membership.user.email}
                        </p>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <WorkspaceStatusBadge status={membership.status} />
                            <span>Scope: {ownerPanelScope}</span>
                        </div>
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

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-medium">Branch access</h2>
                    {membership.branch_assignments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No branch assignments.
                        </p>
                    ) : (
                        <ul className="space-y-2" role="list">
                            {membership.branch_assignments.map((assignment) => (
                                <li
                                    key={assignment.id}
                                    className="flex items-center justify-between gap-3 text-sm"
                                >
                                    <span>{assignment.branch.name}</span>
                                    <WorkspaceStatusBadge
                                        status={assignment.status}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    {canManage &&
                    membership.status === 'Active' &&
                    assignableBranches.length > 0 &&
                    assignBranchForm ? (
                        <Form
                            key={assignBranchId}
                            {...assignBranchForm}
                            className="mt-4 space-y-3 border-t border-border pt-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="assign_branch">
                                            Assign branch
                                        </Label>
                                        <select
                                            id="assign_branch"
                                            value={assignBranchId}
                                            onChange={(event) =>
                                                setAssignBranchId(
                                                    event.target.value,
                                                )
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                        >
                                            {assignableBranches.map(
                                                (branch) => (
                                                    <option
                                                        key={branch.id}
                                                        value={branch.id}
                                                    >
                                                        {branch.name}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="remark">Remark</Label>
                                        <Input
                                            id="remark"
                                            name="remark"
                                            required
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        Assign branch
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-medium">Branch roles</h2>
                    <p className="text-sm text-muted-foreground">
                        Roles come from the Permission Master Customer domain.
                        Owner Panel access is never granted by these roles.
                    </p>
                    {membership.role_assignments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No role assignments.
                        </p>
                    ) : (
                        <ul className="space-y-3" role="list">
                            {membership.role_assignments.map((assignment) => (
                                <li
                                    key={assignment.id}
                                    className="rounded-lg border border-border p-3"
                                >
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="font-medium">
                                                {assignment.role.name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {assignment.branch.name}
                                            </p>
                                            <WorkspaceStatusBadge
                                                status={assignment.status}
                                            />
                                        </div>
                                        {canManage &&
                                        assignment.status === 'Active' ? (
                                            <Form
                                                {...OwnerUsersController.revokeRole.form(
                                                    {
                                                        roleAssignment:
                                                            assignment.id,
                                                    },
                                                )}
                                                className="flex flex-col gap-2 sm:w-56"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <Input
                                                            name="remark"
                                                            required
                                                            placeholder="Revoke remark"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.remark
                                                            }
                                                        />
                                                        <Button
                                                            type="submit"
                                                            variant="secondary"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Revoke role
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {canManage &&
                    membership.status === 'Active' &&
                    activeBranchAssignments.length > 0 &&
                    customerRoles.length > 0 &&
                    assignRoleForm ? (
                        <Form
                            key={roleBranchId}
                            {...assignRoleForm}
                            className="mt-4 space-y-3 border-t border-border pt-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="role_branch">
                                            Branch
                                        </Label>
                                        <select
                                            id="role_branch"
                                            value={roleBranchId}
                                            onChange={(event) =>
                                                setRoleBranchId(
                                                    event.target.value,
                                                )
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                        >
                                            {activeBranchAssignments.map(
                                                (assignment) => (
                                                    <option
                                                        key={assignment.id}
                                                        value={
                                                            assignment.branch
                                                                .id ?? ''
                                                        }
                                                    >
                                                        {assignment.branch.name}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="role_id">Role</Label>
                                        <select
                                            id="role_id"
                                            name="role_id"
                                            required
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                            defaultValue=""
                                        >
                                            <option value="" disabled>
                                                Select role
                                            </option>
                                            {customerRoles.map((role) => (
                                                <option
                                                    key={role.id}
                                                    value={role.id}
                                                >
                                                    {role.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.role_id} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="role_remark">
                                            Remark
                                        </Label>
                                        <Input
                                            id="role_remark"
                                            name="remark"
                                            required
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        Assign role
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </section>

                {canManage &&
                membership.status === 'Active' &&
                !membership.is_owner ? (
                    <section className="max-w-xl space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-medium">Deactivate membership</h2>
                        <p className="text-sm text-muted-foreground">
                            Owner membership cannot be deactivated here.
                            Ownership transfer is an Internal Team action.
                        </p>
                        <Form
                            {...OwnerUsersController.deactivateMembership.form({
                                membership: membership.id,
                            })}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="deactivate_remark">
                                            Remark
                                        </Label>
                                        <Input
                                            id="deactivate_remark"
                                            name="remark"
                                            required
                                        />
                                        <InputError message={errors.remark} />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        disabled={processing}
                                        data-test="deactivate-membership"
                                    >
                                        Deactivate membership
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                ) : null}

                <div className="flex flex-wrap gap-3">
                    <Button asChild variant="secondary">
                        <Link href={links.index}>Back to users</Link>
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={links.dashboard}>Owner Panel</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
