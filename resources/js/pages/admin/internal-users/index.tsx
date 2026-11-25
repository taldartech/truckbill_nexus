import { Form, Head, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useMemo } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type InternalUserRow = {
    id: number;
    name: string;
    email: string;
    role_name?: string;
    is_super_admin: boolean;
    is_active: boolean;
    deactivated_at: string | null;
    show_url: string;
};

type Props = {
    note: string;
    store_url: string;
    roles: { id: string; name: string; slug: string }[];
    users: InternalUserRow[];
    filters?: { q: string; status: string; role: string };
    urls?: { index: string };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function resolveRoleLabel(row: InternalUserRow): string {
    if (row.role_name) {
        return row.role_name;
    }
    return row.is_super_admin ? 'Super Admin' : '—';
}

export default function AdminInternalUsersIndex({
    note,
    store_url,
    roles,
    users,
    filters,
    urls,
}: Props) {
    const { flash, errors, url } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
        url: string;
    }>().props;

    const indexUrl = urls?.index ?? url.split('?')[0];

    const columns = useMemo<DataTableColumn<InternalUserRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Name',
                priority: 0,
                cell: (row) => row.name,
            },
            {
                id: 'email',
                header: 'Email',
                priority: 2,
                cell: (row) => row.email,
            },
            {
                id: 'role',
                header: 'Role',
                priority: 2,
                cell: (row) => resolveRoleLabel(row),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Users" />
            <PageShell title="Users" description={note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['email', 'password', 'role_id']}
                />

                {filters ? (
                    <FormSection title="Search & filters" columns={2}>
                        <Form
                            action={indexUrl}
                            method="get"
                            className="contents"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="q">Search</Label>
                                <Input
                                    id="q"
                                    name="q"
                                    defaultValue={filters.q}
                                    placeholder="Name or email"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="status">Status</Label>
                                <select
                                    id="status"
                                    name="status"
                                    defaultValue={filters.status || 'all'}
                                    className={selectClass}
                                >
                                    <option value="all">All</option>
                                    <option value="active">Active</option>
                                    <option value="deactivated">
                                        Deactivated
                                    </option>
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="role">Role</Label>
                                <select
                                    id="role"
                                    name="role"
                                    defaultValue={filters.role || 'all'}
                                    className={selectClass}
                                >
                                    <option value="all">All roles</option>
                                    {roles.map((role) => (
                                        <option key={role.id} value={role.slug}>
                                            {role.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-end">
                                <Button type="submit" variant="secondary">
                                    Apply filters
                                </Button>
                            </div>
                        </Form>
                    </FormSection>
                ) : null}

                <Form action={store_url} method="post">
                    <FormSection title="Create user" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="name" required>
                                Name
                            </Label>
                            <Input id="name" name="name" required />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="email" required>
                                Email
                            </Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="password" required>
                                Password
                            </Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                required
                                autoComplete="new-password"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="password_confirmation" required>
                                Confirm password
                            </Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                required
                                autoComplete="new-password"
                            />
                        </div>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="role_id" required>
                                Role
                            </Label>
                            <select
                                id="role_id"
                                name="role_id"
                                required
                                className={selectClass}
                                defaultValue=""
                            >
                                <option value="" disabled>
                                    Select internal role
                                </option>
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <label className="flex items-center gap-2 text-sm md:col-span-2">
                            <input
                                type="checkbox"
                                name="is_super_admin"
                                value="1"
                                className="size-4 rounded border border-input"
                            />
                            Grant Super Admin
                        </label>
                        <div className="md:col-span-2">
                            <Button type="submit" variant="default">
                                Create user
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Users"
                        columns={columns}
                        rows={users}
                        rowKey={(row) => String(row.id)}
                        emptyTitle="No users"
                        emptyDescription="Create a user using the form above."
                        actions={(user) => (
                            <AppIconAction
                                icon={Eye}
                                label="View lifecycle"
                                href={user.show_url}
                                variant="info"
                            />
                        )}
                    />
                </div>
            </PageShell>
        </>
    );
}
