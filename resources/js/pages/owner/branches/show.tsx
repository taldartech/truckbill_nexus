import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import OwnerBranchesController from '@/actions/App/Http/Controllers/Owner/OwnerBranchesController';
import InputError from '@/components/input-error';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { branch as enterBranchContext } from '@/routes/workspace/context';

type OwnerBranchesShowProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    canEnterBranch: boolean;
    canDeactivate: boolean;
    branch: {
        id: string;
        name: string;
        status: string;
        created_at: string | null;
        updated_at: string | null;
        deactivated_at: string | null;
    };
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

export default function OwnerBranchesShow({
    company,
    ownerPanelScope,
    canManage,
    canEnterBranch,
    canDeactivate,
    branch,
    assignments,
    roleAssignments,
    audit,
    links,
}: OwnerBranchesShowProps) {
    const { flash, errors } = usePage<FlashPageProps>().props;
    const [confirmDeactivate, setConfirmDeactivate] = useState(false);

    return (
        <>
            <Head title={`${branch.name} · Branches`} />
            <OwnerPageHeader
                title={branch.name}
                description={`${company.name} · Branch detail · Scope: ${ownerPanelScope}`}
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
                                    <Button type="submit" disabled={processing}>
                                        Enter Branch Panel
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </div>

                {canManage ? (
                    <Form
                        {...OwnerBranchesController.update.form({
                            branch: branch.id,
                        })}
                        className="max-w-xl space-y-4 rounded-xl border border-border p-4"
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <div>
                                    <h2 className="font-medium">Edit branch</h2>
                                    <p className="text-sm text-muted-foreground">
                                        Branch edit updates identity fields
                                        only. User assignments are managed from
                                        Users.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="edit-name">Name</Label>
                                    <Input
                                        id="edit-name"
                                        name="name"
                                        defaultValue={branch.name}
                                        required
                                        maxLength={255}
                                    />
                                    <InputError
                                        message={
                                            formErrors.name ?? errors?.name
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="edit-remark">Remark</Label>
                                    <Input
                                        id="edit-remark"
                                        name="remark"
                                        required
                                        maxLength={1000}
                                        placeholder="Reason for this update"
                                    />
                                    <InputError
                                        message={
                                            formErrors.remark ?? errors?.remark
                                        }
                                    />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Save changes
                                </Button>
                            </>
                        )}
                    </Form>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-medium">Assigned members</h2>
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
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-medium">Active roles</h2>
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
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-medium">Audit history</h2>
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
                                        {entry.created_at ?? '—'}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {canDeactivate ? (
                    <section className="max-w-xl space-y-4 rounded-xl border border-destructive/40 p-4">
                        <div>
                            <h2 className="font-medium text-destructive">
                                Deactivate branch
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Deactivation is soft and audited. The last
                                active branch cannot be deactivated.
                            </p>
                        </div>
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
                                className="space-y-4"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="deactivate-remark">
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
                    </section>
                ) : null}
            </OwnerPageHeader>
        </>
    );
}
