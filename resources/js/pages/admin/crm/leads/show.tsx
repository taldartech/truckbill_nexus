import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    lead: {
        id: string;
        contact_name: string;
        email: string | null;
        mobile: string | null;
        company_name: string | null;
        status: string;
        source: string;
        interested_product: string;
        remarks: string | null;
        assignee: { id: number; name: string; email: string } | null;
        allowed_transitions: string[];
    };
    activities: {
        id: string;
        activity_type: string;
        body: string | null;
        created_at: string | null;
    }[];
    urls: { index: string; transition: string };
};

export default function CrmLeadShow({ lead, activities, urls }: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={`${lead.contact_name} · lead`} />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Leads
                    </Link>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {lead.contact_name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {lead.status} · {lead.interested_product} ·{' '}
                        {lead.source}
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
                {errors?.status ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.status}
                    </p>
                ) : null}

                <section className="space-y-2 rounded-xl border border-border p-4 text-sm">
                    <p>Email: {lead.email ?? '—'}</p>
                    <p>Mobile: {lead.mobile ?? '—'}</p>
                    <p>Company: {lead.company_name ?? '—'}</p>
                    <p>
                        Assignee:{' '}
                        {lead.assignee ? lead.assignee.email : 'Unassigned'}
                    </p>
                    {lead.remarks ? <p>Remarks: {lead.remarks}</p> : null}
                </section>

                {lead.allowed_transitions.length > 0 ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Update status</h2>
                        <Form
                            action={urls.transition}
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <label className="flex flex-col gap-1 text-sm">
                                New status
                                <select
                                    name="status"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    defaultValue={lead.allowed_transitions[0]}
                                >
                                    {lead.allowed_transitions.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm md:col-span-2">
                                Remark
                                <textarea
                                    name="remark"
                                    rows={2}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    placeholder="Required for Disqualified/Lost"
                                />
                            </label>
                            <Button type="submit">Save status</Button>
                        </Form>
                    </section>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Activity</h2>
                    {activities.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No activity yet.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {activities.map((activity) => (
                                <li
                                    key={activity.id}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    <span className="font-medium">
                                        {activity.activity_type}
                                    </span>
                                    {activity.body ? ` — ${activity.body}` : ''}
                                    <div className="text-xs text-muted-foreground">
                                        {activity.created_at}
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
