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
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function AdminInternalUsersIndex({
    note,
    store_url,
    roles,
    users,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const columns = useMemo<DataTableColumn<InternalUserRow>[]>(
        () => [
            {
                id: 'user',
                header: 'User',
                priority: 0,
                cell: (row) => (
                    <>
                        <div className="font-medium">{row.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.email}
                        </div>
                    </>
                ),
            },
            {
                id: 'role',
                header: 'Role',
                priority: 2,
                cell: (row) =>
                    row.is_super_admin ? 'Super Admin' : 'Internal',
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
            <Head title="Internal users" />
            <PageShell title="Internal users" description={note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} keys={['email', 'password']} />

                <Form action={store_url} method="post">
                    <FormSection title="Create internal user" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" name="name" required />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                required
                                autoComplete="new-password"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="password_confirmation">
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
                        <div className="space-y-2">
                            <Label htmlFor="role_id">Role (optional)</Label>
                            <select
                                id="role_id"
                                name="role_id"
                                className={selectClass}
                                defaultValue=""
                            >
                                <option value="">None</option>
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <label className="flex items-center gap-2 text-sm md:self-end">
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
                                Create internal user
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Internal users"
                        columns={columns}
                        rows={users}
                        rowKey={(row) => String(row.id)}
                        emptyTitle="No internal users"
                        emptyDescription="Create an internal user above."
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
