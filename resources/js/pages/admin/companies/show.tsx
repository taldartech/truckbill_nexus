import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    company: {
        id: string;
        name: string;
        pan: string | null;
        gstin: string | null;
        is_active: boolean;
        deactivated_at: string | null;
        owner: { id: number; name: string; email: string } | null;
    };
    customer_candidates: { id: number; name: string; email: string }[];
    transfers: {
        id: string;
        previous_owner: string | null;
        new_owner: string | null;
        initiated_by: string | null;
        remark: string;
        transferred_at: string | null;
    }[];
    audit_entries: {
        id: number;
        action: string;
        remark: string | null;
        created_at: string | null;
    }[];
    urls: {
        index: string;
        deactivate: string;
        reactivate: string;
        transfer: string;
    };
};

export default function AdminCompanyShow({
    company,
    customer_candidates,
    transfers,
    audit_entries,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={`${company.name} · lifecycle`} />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Companies
                    </Link>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Lifecycle
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {company.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Status:{' '}
                        <span className="font-medium text-foreground">
                            {company.is_active ? 'Active' : 'Deactivated'}
                        </span>
                        {company.owner
                            ? ` · Owner ${company.owner.email}`
                            : null}
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
                {errors?.remark || errors?.target_user_id ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.remark || errors.target_user_id}
                    </p>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">
                        {company.is_active ? 'Deactivate' : 'Reactivate'}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Mandatory remark and confirmation. Soft-delete and
                        restore are prohibited (DEC-003).
                    </p>
                    <Form
                        action={
                            company.is_active
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
                                placeholder="Reason for lifecycle change"
                            />
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="confirm"
                                value="1"
                                required
                            />
                            I confirm this lifecycle change
                        </label>
                        <Button
                            type="submit"
                            variant={
                                company.is_active ? 'destructive' : 'default'
                            }
                        >
                            {company.is_active
                                ? 'Deactivate company'
                                : 'Reactivate company'}
                        </Button>
                    </Form>
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Ownership transfer</h2>
                    <p className="text-sm text-muted-foreground">
                        Transfers ownership to an existing active Customer.
                        Memberships and subscriptions are preserved.
                    </p>
                    <Form
                        action={urls.transfer}
                        method="post"
                        className="grid gap-3"
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            New owner
                            <select
                                name="target_user_id"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                                defaultValue=""
                            >
                                <option value="" disabled>
                                    Select customer
                                </option>
                                {customer_candidates
                                    .filter(
                                        (candidate) =>
                                            candidate.id !== company.owner?.id,
                                    )
                                    .map((candidate) => (
                                        <option
                                            key={candidate.id}
                                            value={candidate.id}
                                        >
                                            {candidate.email} — {candidate.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
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
                            I confirm ownership transfer
                        </label>
                        <Button type="submit">Transfer ownership</Button>
                    </Form>
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Transfer history</h2>
                    {transfers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No ownership transfers recorded.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {transfers.map((transfer) => (
                                <li
                                    key={transfer.id}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    {transfer.previous_owner} →{' '}
                                    {transfer.new_owner}
                                    <div className="text-xs text-muted-foreground">
                                        {transfer.transferred_at} ·{' '}
                                        {transfer.remark}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Recent company audit</h2>
                    {audit_entries.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No company audit entries.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {audit_entries.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    <span className="font-medium">
                                        {entry.action}
                                    </span>
                                    {entry.remark ? ` — ${entry.remark}` : ''}
                                    <div className="text-xs text-muted-foreground">
                                        {entry.created_at}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
