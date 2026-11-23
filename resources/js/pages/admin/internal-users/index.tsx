import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';

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
                        <div>{row.name}</div>
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
                cell: (row) => (row.is_active ? 'Active' : 'Deactivated'),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Internal users" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Administration
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Internal users
                    </h1>
                    <p className="text-sm text-muted-foreground">{note}</p>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.email || errors?.password ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.email || errors.password}
                    </p>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Create Internal user</h2>
                    <Form
                        action={store_url}
                        method="post"
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Name
                            <input
                                name="name"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Email
                            <input
                                name="email"
                                type="email"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Password
                            <input
                                name="password"
                                type="password"
                                required
                                autoComplete="new-password"
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Confirm password
                            <input
                                name="password_confirmation"
                                type="password"
                                required
                                autoComplete="new-password"
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Role (optional)
                            <select
                                name="role_id"
                                className="rounded-md border border-border bg-background px-3 py-2"
                                defaultValue=""
                            >
                                <option value="">None</option>
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-sm md:pt-7">
                            <input
                                type="checkbox"
                                name="is_super_admin"
                                value="1"
                            />
                            Grant Super Admin
                        </label>
                        <Button
                            type="submit"
                            className="md:col-span-2 md:w-fit"
                        >
                            Create Internal user
                        </Button>
                    </Form>
                </section>

                <ResponsiveDataTable
                    caption="Internal users"
                    columns={columns}
                    rows={users}
                    rowKey={(row) => String(row.id)}
                    emptyTitle="No internal users"
                    emptyDescription="Create an internal user above."
                    actions={(user) => (
                        <Link
                            href={user.show_url}
                            className="text-sm font-medium underline-offset-4 hover:underline"
                        >
                            Lifecycle
                        </Link>
                    )}
                />
            </div>
        </>
    );
}
