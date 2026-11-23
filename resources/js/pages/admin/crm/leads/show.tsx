import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type AssignableUser = { id: number; name: string; email: string };

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
        can_convert: boolean;
        converted_company: { id: string; name: string } | null;
    };
    follow_ups: {
        id: string;
        due_at: string | null;
        notes: string | null;
        completed_at: string | null;
        completion_notes: string | null;
        assignee: string | null;
        is_overdue: boolean;
        complete_url: string | null;
    }[];
    activities: {
        id: string;
        activity_type: string;
        body: string | null;
        created_at: string | null;
    }[];
    assignable_users: AssignableUser[];
    can_assign: boolean;
    gst_rates: { id: string; label: string }[];
    gst_registration_types: string[];
    tax_liabilities: string[];
    data_visibilities: string[];
    urls: {
        index: string;
        transition: string;
        assign: string;
        follow_up: string;
        convert: string;
    };
};

export default function CrmLeadShow({
    lead,
    follow_ups,
    activities,
    assignable_users,
    can_assign,
    gst_rates,
    gst_registration_types,
    tax_liabilities,
    data_visibilities,
    urls,
}: Props) {
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
                        {lead.status} · {lead.interested_product} · {lead.source}
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
                    <p>Email: {lead.email ?? '—'}</p>
                    <p>Mobile: {lead.mobile ?? '—'}</p>
                    <p>Company: {lead.company_name ?? '—'}</p>
                    <p>
                        Assignee:{' '}
                        {lead.assignee ? lead.assignee.email : 'Unassigned'}
                    </p>
                    {lead.remarks ? <p>Remarks: {lead.remarks}</p> : null}
                    {lead.converted_company ? (
                        <p>
                            Converted company:{' '}
                            <span className="font-medium">
                                {lead.converted_company.name}
                            </span>
                        </p>
                    ) : null}
                </section>

                {can_assign ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Assign lead</h2>
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
                                    defaultValue={lead.assignee?.id ?? ''}
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
                    <h2 className="font-semibold">Follow-ups</h2>
                    <Form
                        action={urls.follow_up}
                        method="post"
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Due at
                            <input
                                type="datetime-local"
                                name="due_at"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        {can_assign ? (
                            <label className="flex flex-col gap-1 text-sm">
                                Assignee
                                <select
                                    name="assigned_to_user_id"
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    defaultValue={lead.assignee?.id ?? ''}
                                >
                                    <option value="">Lead assignee / me</option>
                                    {assignable_users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : null}
                        <label className="flex flex-col gap-1 text-sm md:col-span-2">
                            Notes
                            <textarea
                                name="notes"
                                rows={2}
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <Button type="submit">Schedule follow-up</Button>
                    </Form>

                    <ul className="space-y-3 text-sm">
                        {follow_ups.length === 0 ? (
                            <li className="text-muted-foreground">
                                No follow-ups scheduled.
                            </li>
                        ) : (
                            follow_ups.map((followUp) => (
                                <li
                                    key={followUp.id}
                                    className="rounded-lg border border-border p-3"
                                >
                                    <p>
                                        Due {followUp.due_at ?? '—'}
                                        {followUp.is_overdue ? (
                                            <span className="ml-2 text-amber-700">
                                                Overdue
                                            </span>
                                        ) : null}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {followUp.assignee ?? 'Unassigned'}
                                        {followUp.notes
                                            ? ` · ${followUp.notes}`
                                            : ''}
                                    </p>
                                    {followUp.completed_at ? (
                                        <p className="text-muted-foreground">
                                            Completed {followUp.completed_at}
                                            {followUp.completion_notes
                                                ? ` · ${followUp.completion_notes}`
                                                : ''}
                                        </p>
                                    ) : followUp.complete_url ? (
                                        <Form
                                            action={followUp.complete_url}
                                            method="post"
                                            className="mt-2 flex flex-wrap items-end gap-2"
                                        >
                                            <label className="flex min-w-[12rem] flex-1 flex-col gap-1">
                                                Completion remark
                                                <input
                                                    name="remark"
                                                    className="rounded-md border border-border bg-background px-3 py-2"
                                                />
                                            </label>
                                            <Button type="submit" size="sm">
                                                Complete
                                            </Button>
                                        </Form>
                                    ) : null}
                                </li>
                            ))
                        )}
                    </ul>
                </section>

                {lead.can_convert ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Convert to customer company</h2>
                        <p className="text-sm text-muted-foreground">
                            Creates or reuses the Customer identity and a company
                            profile. Existing passwords are never overwritten.
                        </p>
                        <Form
                            action={urls.convert}
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <label className="flex flex-col gap-1 text-sm">
                                Company name
                                <input
                                    name="name"
                                    required
                                    defaultValue={lead.company_name ?? ''}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                PAN
                                <input
                                    name="pan"
                                    required
                                    maxLength={10}
                                    className="rounded-md border border-border bg-background px-3 py-2 uppercase"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm md:col-span-2">
                                Registered address
                                <input
                                    name="registered_address"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                State
                                <input
                                    name="state"
                                    required
                                    defaultValue="Maharashtra"
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                City
                                <input
                                    name="city"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                PIN
                                <input
                                    name="pin"
                                    required
                                    maxLength={6}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Mobile
                                <input
                                    name="mobile"
                                    required
                                    defaultValue={lead.mobile ?? ''}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Email
                                <input
                                    type="email"
                                    name="email"
                                    defaultValue={lead.email ?? ''}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                GST registration
                                <select
                                    name="gst_registration_type"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    defaultValue={gst_registration_types[0]}
                                >
                                    {gst_registration_types.map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Tax liability
                                <select
                                    name="tax_liability"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    defaultValue={tax_liabilities[0]}
                                >
                                    {tax_liabilities.map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                GSTIN
                                <input
                                    name="gstin"
                                    maxLength={15}
                                    className="rounded-md border border-border bg-background px-3 py-2 uppercase"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                GST rate
                                <select
                                    name="gst_rate_id"
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    defaultValue={gst_rates[0]?.id ?? ''}
                                >
                                    <option value="">None</option>
                                    {gst_rates.map((rate) => (
                                        <option key={rate.id} value={rate.id}>
                                            {rate.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Data visibility
                                <select
                                    name="data_visibility"
                                    required
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    defaultValue={data_visibilities[0]}
                                >
                                    {data_visibilities.map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Timezone
                                <input
                                    name="timezone"
                                    defaultValue="Asia/Kolkata"
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Initial branch
                                <input
                                    name="initial_branch_name"
                                    defaultValue="Head Office"
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm md:col-span-2">
                                New password (only when creating a new identity)
                                <input
                                    type="password"
                                    name="new_password"
                                    minLength={12}
                                    className="rounded-md border border-border bg-background px-3 py-2"
                                    autoComplete="new-password"
                                />
                            </label>
                            <Button type="submit" className="bg-[#2FB36F] hover:bg-[#2FB36F]/90">
                                Convert lead
                            </Button>
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
                                <li key={activity.id}>
                                    <span className="font-medium">
                                        {activity.activity_type}
                                    </span>
                                    {activity.body ? ` — ${activity.body}` : ''}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
