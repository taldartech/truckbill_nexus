import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    identity: {
        id: number;
        name: string;
        email: string;
        identity_type: string | null;
        is_super_admin: boolean;
        is_active: boolean;
        deactivated_at: string | null;
        is_self: boolean;
    };
    owned_companies: {
        id: string;
        name: string;
        is_active: boolean;
        show_url: string;
    }[];
    urls: {
        index: string;
        deactivate: string;
        reactivate: string;
    };
};

export default function AdminIdentityShow({
    identity,
    owned_companies,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={`${identity.email} · identity`} />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Identities
                    </Link>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Lifecycle
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {identity.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {identity.email} · {identity.identity_type}
                        {identity.is_super_admin ? ' · Super Admin' : ''} ·{' '}
                        {identity.is_active ? 'Active' : 'Deactivated'}
                    </p>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.remark ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.remark}
                    </p>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">
                        {identity.is_active ? 'Deactivate' : 'Reactivate'}
                    </h2>
                    {identity.is_self ? (
                        <p className="text-sm text-muted-foreground">
                            You cannot change your own identity lifecycle from
                            this screen.
                        </p>
                    ) : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Deactivation revokes active sessions. Last Super
                                Admin protection remains enforced.
                            </p>
                            <Form
                                action={
                                    identity.is_active
                                        ? urls.deactivate
                                        : urls.reactivate
                                }
                                method="post"
                                className="grid gap-3"
                            >
                                <label className="flex flex-col gap-1 text-sm">
                                    Remark
                                    <textarea
                                        name="remark"
                                        required
                                        rows={3}
                                        className="rounded-md border border-border bg-background px-3 py-2"
                                    />
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="confirm"
                                        value="1"
                                        required
                                    />
                                    I confirm this identity lifecycle change
                                </label>
                                <Button
                                    type="submit"
                                    variant={
                                        identity.is_active
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {identity.is_active
                                        ? 'Deactivate identity'
                                        : 'Reactivate identity'}
                                </Button>
                            </Form>
                        </>
                    )}
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Owned companies</h2>
                    {owned_companies.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No owned companies.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {owned_companies.map((company) => (
                                <li key={company.id}>
                                    <Link
                                        href={company.show_url}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {company.name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ·{' '}
                                        {company.is_active
                                            ? 'Active'
                                            : 'Deactivated'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
