import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import OwnerUsersController from '@/actions/App/Http/Controllers/Owner/OwnerUsersController';
import InputError from '@/components/input-error';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type MembershipRow = {
    id: string;
    status: string;
    is_owner: boolean;
    user: { id: number | null; name: string | null; email: string | null };
    show_url: string;
};

type InvitationRow = {
    id: string;
    email: string;
    branch_name: string | null;
    expires_at: string | null;
};

type OwnerUsersIndexProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    filters: { q: string; status: string };
    memberships: {
        data: MembershipRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    pendingInvitations: InvitationRow[];
    branches: { id: string; name: string }[];
    links: {
        dashboard: string;
        invite: string;
        index: string;
    };
};

type FlashPageProps = {
    flash?: { success?: string | null };
};

export default function OwnerUsersIndex({
    company,
    ownerPanelScope,
    canManage,
    filters,
    memberships,
    pendingInvitations,
    branches,
    links,
}: OwnerUsersIndexProps) {
    const { flash } = usePage<FlashPageProps>().props;
    const [search, setSearch] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [inviteBranchId, setInviteBranchId] = useState('');
    const inviteForm = useMemo(
        () =>
            inviteBranchId
                ? OwnerUsersController.inviteForBranch.form({
                      branch: inviteBranchId,
                  })
                : OwnerUsersController.invite.form(),
        [company.id, inviteBranchId],
    );

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            { q: search || undefined, status },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`Users · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Users & memberships"
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                {canManage ? (
                    <section className="max-w-xl space-y-4 rounded-xl border border-border p-4">
                        <h2 className="font-medium">Invite user</h2>
                        <p className="text-sm text-muted-foreground">
                            Sends a time-limited invitation. Existing Customer
                            passwords are never overwritten on accept.
                        </p>
                        <Form
                            key={inviteBranchId || 'no-branch'}
                            {...inviteForm}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invite_branch">
                                            Branch (optional)
                                        </Label>
                                        <select
                                            id="invite_branch"
                                            value={inviteBranchId}
                                            onChange={(event) =>
                                                setInviteBranchId(
                                                    event.target.value,
                                                )
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                        >
                                            <option value="">
                                                No branch assignment
                                            </option>
                                            {branches.map((branch) => (
                                                <option
                                                    key={branch.id}
                                                    value={branch.id}
                                                >
                                                    {branch.name}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-xs text-muted-foreground">
                                            Branch is selected in the URL path,
                                            never posted as a tenant field.
                                        </p>
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        data-test="invite-user"
                                    >
                                        Send invitation
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        User management writes are unavailable while the company
                        is deactivated.
                    </p>
                )}

                <section className="space-y-4">
                    <form
                        onSubmit={applyFilters}
                        className="flex flex-col gap-3 sm:flex-row sm:items-end"
                    >
                        <div className="grid flex-1 gap-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Name or email"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                value={status}
                                onChange={(event) =>
                                    setStatus(event.target.value)
                                }
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </form>

                    {memberships.data.length === 0 ? (
                        <ShellEmptyState
                            title="No memberships found"
                            description="Try a different search or status filter."
                        />
                    ) : (
                        <ul className="space-y-3" role="list">
                            {memberships.data.map((membership) => (
                                <li
                                    key={membership.id}
                                    className="flex flex-col gap-3 rounded-xl border border-border p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="space-y-1">
                                        <p className="font-medium">
                                            {membership.user.name}
                                            {membership.is_owner
                                                ? ' · Owner'
                                                : ''}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {membership.user.email}
                                        </p>
                                        <WorkspaceStatusBadge
                                            status={membership.status}
                                        />
                                    </div>
                                    <Button
                                        asChild
                                        variant="secondary"
                                        size="sm"
                                    >
                                        <Link href={membership.show_url}>
                                            View
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <p className="text-sm text-muted-foreground">
                        Page {memberships.meta.current_page} of{' '}
                        {memberships.meta.last_page} · {memberships.meta.total}{' '}
                        total
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="font-medium">Pending invitations</h2>
                    {pendingInvitations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No pending invitations.
                        </p>
                    ) : (
                        <ul className="space-y-3" role="list">
                            {pendingInvitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="rounded-xl border border-border p-4"
                                >
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p className="font-medium">
                                                {invitation.email}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {invitation.branch_name
                                                    ? `Branch: ${invitation.branch_name}`
                                                    : 'No branch pre-assignment'}
                                            </p>
                                        </div>
                                        {canManage ? (
                                            <Form
                                                {...OwnerUsersController.revokeInvitation.form(
                                                    {
                                                        invitation:
                                                            invitation.id,
                                                    },
                                                )}
                                                className="flex flex-col gap-2 sm:w-64"
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
                                                            Revoke
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
                </section>

                <div>
                    <Button asChild variant="secondary">
                        <Link href={links.dashboard}>Back to Owner Panel</Link>
                    </Button>
                </div>
            </CompanyOwnerPageShell>
        </>
    );
}
