import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type AssignableUser = { id: number; name: string; email: string };

type Props = {
    ticket: {
        id: string;
        subject: string;
        description: string;
        category: string;
        priority: string;
        status: string;
        assignee: string | null;
        due_at: string | null;
        allowed_transitions: string[];
        can_assign: boolean;
    };
    assignable_users: AssignableUser[];
    activities: {
        id: string;
        activity_type: string;
        body: string | null;
        created_at: string | null;
    }[];
    urls: {
        index: string;
        dashboard: string;
        transition: string;
        assign: string;
    };
};

export default function CrmTicketShow({
    ticket,
    assignable_users,
    activities,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={`${ticket.subject} · ticket`} />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Tickets
                    </Link>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {ticket.subject}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {ticket.status} · {ticket.priority} · {ticket.category}
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
                {Object.values(errors ?? {}).map((message) => (
                    <p key={message} className="text-sm text-destructive" role="alert">
                        {message}
                    </p>
                ))}

                <section className="space-y-2 rounded-xl border border-border p-4 text-sm">
                    <p>{ticket.description}</p>
                    <p>Assignee: {ticket.assignee ?? 'Unassigned'}</p>
                    <p>Due: {ticket.due_at ?? '—'}</p>
                </section>

                {ticket.can_assign ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Assign ticket</h2>
                        <Form
                            action={urls.assign}
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <label className="flex flex-col gap-1 text-sm">
                                Assignee
                                <select
                                    name="assigned_to_user_id"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                >
                                    <option value="" disabled>
                                        Select internal user
                                    </option>
                                    {assignable_users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm md:col-span-2">
                                Remark
                                <textarea
                                    name="remark"
                                    required
                                    rows={2}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <Button type="submit">Assign</Button>
                        </Form>
                    </section>
                ) : null}

                {ticket.allowed_transitions.length > 0 ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Update status</h2>
                        <Form
                            action={urls.transition}
                            method="post"
                            className="grid gap-3"
                        >
                            <label className="flex flex-col gap-1 text-sm">
                                New status
                                <select
                                    name="status"
                                    required
                                    defaultValue={ticket.allowed_transitions[0]}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                >
                                    {ticket.allowed_transitions.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Remark
                                <textarea
                                    name="remark"
                                    rows={2}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    placeholder="Required for reopen/close"
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
