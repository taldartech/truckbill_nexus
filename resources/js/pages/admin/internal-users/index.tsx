import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    note: string;
    store_url: string;
    roles: { id: string; name: string; slug: string }[];
    users: {
        id: number;
        name: string;
        email: string;
        is_super_admin: boolean;
        is_active: boolean;
        deactivated_at: string | null;
        show_url: string;
    }[];
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

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[36rem] text-left text-sm">
                        <thead className="border-b border-border bg-muted/30 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">User</th>
                                <th className="px-4 py-3 font-medium">Role</th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr
                                    key={user.id}
                                    className="border-b border-border last:border-0"
                                >
                                    <td className="px-4 py-3">
                                        <div className="font-medium">
                                            {user.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {user.email}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {user.is_super_admin
                                            ? 'Super Admin'
                                            : 'Internal'}
                                    </td>
                                    <td className="px-4 py-3">
                                        {user.is_active
                                            ? 'Active'
                                            : 'Deactivated'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link
                                            href={user.show_url}
                                            className="text-sm font-medium underline-offset-4 hover:underline"
                                        >
                                            Lifecycle
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
