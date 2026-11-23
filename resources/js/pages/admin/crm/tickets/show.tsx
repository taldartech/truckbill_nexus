import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowLeft, Save, UserPlus } from 'lucide-react';
import {
    DetailItem,
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

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

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

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
            <PageShell
                title={ticket.subject}
                description={`${ticket.category} · ${ticket.priority} priority`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge status={ticket.status} />
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to tickets"
                            href={urls.index}
                            variant="outline"
                        />
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} />

                <FormSection title="Ticket details" columns={2}>
                    <dl className="contents">
                        <DetailItem
                            className="md:col-span-2"
                            label="Description"
                            value={ticket.description}
                        />
                        <DetailItem
                            label="Assignee"
                            value={ticket.assignee ?? 'Unassigned'}
                        />
                        <DetailItem label="Due" value={ticket.due_at ?? '—'} />
                    </dl>
                </FormSection>

                {ticket.can_assign ? (
                    <Form
                        action={urls.assign}
                        method="post"
                        className="space-y-0"
                    >
                        <FormSection
                            title="Assign ticket"
                            description="Route to an internal owner with audit remark."
                            columns={2}
                        >
                            <div className="space-y-2">
                                <label
                                    htmlFor="assigned_to_user_id"
                                    className="text-sm font-medium"
                                >
                                    Assignee
                                </label>
                                <select
                                    id="assigned_to_user_id"
                                    name="assigned_to_user_id"
                                    required
                                    className={selectClass}
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
                            </div>
                            <div className="space-y-2 md:col-span-2">
                                <label
                                    htmlFor="assign-remark"
                                    className="text-sm font-medium"
                                >
                                    Remark
                                </label>
                                <textarea
                                    id="assign-remark"
                                    name="remark"
                                    required
                                    rows={2}
                                    className={textareaClass}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <AppIconAction
                                    icon={UserPlus}
                                    label="Assign ticket"
                                    type="submit"
                                    variant="default"
                                    size="default"
                                    className="gap-2 px-3"
                                >
                                    <span className="text-sm font-medium">
                                        Assign
                                    </span>
                                </AppIconAction>
                            </div>
                        </FormSection>
                    </Form>
                ) : null}

                {ticket.allowed_transitions.length > 0 ? (
                    <Form
                        action={urls.transition}
                        method="post"
                        className="space-y-0"
                    >
                        <FormSection
                            title="Update status"
                            description="Reopen and close require a remark."
                            columns={2}
                        >
                            <div className="space-y-2">
                                <label
                                    htmlFor="status"
                                    className="text-sm font-medium"
                                >
                                    New status
                                </label>
                                <select
                                    id="status"
                                    name="status"
                                    required
                                    defaultValue={ticket.allowed_transitions[0]}
                                    className={selectClass}
                                >
                                    {ticket.allowed_transitions.map(
                                        (status) => (
                                            <option key={status} value={status}>
                                                {status}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                            <div className="space-y-2 md:col-span-2">
                                <label
                                    htmlFor="transition-remark"
                                    className="text-sm font-medium"
                                >
                                    Remark
                                </label>
                                <textarea
                                    id="transition-remark"
                                    name="remark"
                                    rows={2}
                                    className={textareaClass}
                                    placeholder="Required for reopen/close"
                                />
                            </div>
                            <div className="md:col-span-2">
                                <AppIconAction
                                    icon={Save}
                                    label="Save status"
                                    type="submit"
                                    variant="warning"
                                    size="default"
                                    className="gap-2 px-3"
                                >
                                    <span className="text-sm font-medium">
                                        Save status
                                    </span>
                                </AppIconAction>
                            </div>
                        </FormSection>
                    </Form>
                ) : null}

                <FormSection title="Activity" columns={1}>
                    {activities.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No activity yet.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {activities.map((activity) => (
                                <li
                                    key={activity.id}
                                    className="rounded-md border border-border bg-muted/20 px-3 py-2"
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
                </FormSection>
            </PageShell>
        </>
    );
}
