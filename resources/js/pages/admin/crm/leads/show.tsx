import { Form, Head, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Copy,
    Mail,
    Pencil,
    Plus,
    RotateCcw,
    Save,
    UserPlus,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import {
    DetailItem,
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import {
    WorkflowProgress,
    type WorkflowStep,
} from '@/components/workflow-progress';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { useClipboard } from '@/hooks/use-clipboard';

type AssignableUser = { id: number; name: string; email: string };

type OnboardingSummary = {
    status: string;
    recipient: string | null;
    sent_at: string | null;
    expires_at: string | null;
    completed_at: string | null;
    delivery_status: string | null;
    can_send: boolean;
    can_resend: boolean;
    can_revoke: boolean;
    accept_url: string | null;
};

type Props = {
    lead: {
        id: string;
        contact_name: string;
        email: string | null;
        mobile: string | null;
        company_name: string | null;
        state: string | null;
        city: string | null;
        status: string;
        source: string;
        source_channel: string | null;
        interested_product: string;
        utm_source: string | null;
        utm_medium: string | null;
        utm_campaign: string | null;
        landing_url: string | null;
        referrer: string | null;
        edit_url?: string | null;
        remarks: string | null;
        assignee: { id: number; name: string; email: string } | null;
        allowed_transitions: string[];
        can_invite: boolean;
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
        is_note: boolean;
        actor: string | null;
        created_at: string | null;
    }[];
    assignable_users: AssignableUser[];
    can_assign: boolean;
    onboarding: OnboardingSummary;
    urls: {
        index: string;
        transition: string;
        assign: string;
        follow_up: string;
        note: string;
        invite: string;
        invite_resend: string;
        invite_revoke: string;
    };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const PIPELINE_ORDER = [
    'new',
    'contacted',
    'qualified',
    'onboarding_invite_sent',
    'onboarded',
    'converted',
] as const;

function formatPipelineLabel(id: string): string {
    return id
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function formatActivityType(activity_type: string, is_note: boolean): string {
    if (is_note) {
        return 'Note';
    }
    if (activity_type === 'website_enquiry') {
        return 'Website enquiry';
    }
    return activity_type.replace(/_/g, ' ');
}

function resolveActivityActor(
    activity_type: string,
    actor: string | null,
): string {
    if (actor) {
        return actor;
    }
    if (activity_type === 'website_enquiry') {
        return 'Website';
    }
    return 'System';
}

function hasCampaignAttribution(lead: Props['lead']): boolean {
    return (
        Boolean(lead.utm_source) ||
        Boolean(lead.utm_medium) ||
        Boolean(lead.utm_campaign) ||
        Boolean(lead.landing_url) ||
        Boolean(lead.referrer) ||
        Boolean(lead.source_channel)
    );
}

function buildLeadWorkflowSteps(status: string): WorkflowStep[] {
    const normalized = status.toLowerCase();

    if (normalized === 'disqualified' || normalized === 'lost') {
        return PIPELINE_ORDER.map((id, position) => ({
            id,
            label: formatPipelineLabel(id),
            status: position === 0 ? 'blocked' : 'upcoming',
        }));
    }

    const idx = Math.max(
        0,
        PIPELINE_ORDER.indexOf(normalized as (typeof PIPELINE_ORDER)[number]),
    );

    return PIPELINE_ORDER.map((id, position) => {
        const label = formatPipelineLabel(id);
        if (position < idx) {
            return { id, label, status: 'complete' as const };
        }
        if (position === idx) {
            return { id, label, status: 'current' as const };
        }
        return { id, label, status: 'upcoming' as const };
    });
}

export default function CrmLeadShow({
    lead,
    follow_ups,
    activities,
    assignable_users,
    can_assign,
    onboarding,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: {
            success?: string | null;
            onboarding_accept_url?: string | null;
        };
        errors?: Record<string, string>;
    }>().props;

    const [, copyToClipboard] = useClipboard();
    const [noteDialogOpen, setNoteDialogOpen] = useState(false);
    const [copiedAcceptUrl, setCopiedAcceptUrl] = useState(false);

    const acceptUrl = flash?.onboarding_accept_url ?? null;

    const overdueFollowUps = follow_ups.filter(
        (followUp) => followUp.is_overdue && !followUp.completed_at,
    ).length;

    const [tab, setTab] = useState('overview');

    const workflowSteps = useMemo(
        () => buildLeadWorkflowSteps(lead.status),
        [lead.status],
    );

    const canSendInvite = onboarding.can_send;

    async function handleCopyAcceptUrl() {
        if (!acceptUrl) {
            return;
        }
        const ok = await copyToClipboard(acceptUrl);
        setCopiedAcceptUrl(ok);
    }

    return (
        <>
            <Head title={`${lead.contact_name} · lead`} />
            <PageShell
                title={lead.contact_name}
                description={`${lead.interested_product} · ${lead.source}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge status={lead.status} />
                        {lead.edit_url ? (
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={lead.edit_url}
                                    className="inline-flex items-center gap-1.5"
                                >
                                    <Pencil className="size-4" aria-hidden />
                                    Edit
                                </a>
                            </Button>
                        ) : null}
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to leads"
                            href={urls.index}
                            variant="outline"
                        />
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} />

                {acceptUrl ? (
                    <div className="rounded-lg border border-border bg-muted/20 px-3 py-3 text-sm">
                        <p className="font-medium">Onboarding test link</p>
                        <p className="mt-1 text-muted-foreground">
                            Copy the accept URL for local or staging QA (not
                            customer-facing email).
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <Input
                                readOnly
                                value={acceptUrl}
                                className="max-w-xl font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="gap-1.5"
                                onClick={() => void handleCopyAcceptUrl()}
                            >
                                <Copy className="size-4" aria-hidden />
                                {copiedAcceptUrl ? 'Copied' : 'Copy link'}
                            </Button>
                        </div>
                    </div>
                ) : null}

                <WorkflowProgress
                    aria-label="Lead lifecycle"
                    steps={workflowSteps}
                />

                <DetailTabs
                    aria-label="Lead detail sections"
                    activeId={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'overview', label: 'Overview' },
                        {
                            id: 'follow-ups',
                            label: 'Follow-ups',
                            count: follow_ups.length,
                            tone: overdueFollowUps > 0 ? 'warning' : undefined,
                        },
                        {
                            id: 'assignment',
                            label: 'Assignment',
                        },
                        {
                            id: 'notes-activity',
                            label: 'Notes & activity',
                            count: activities.length,
                        },
                        {
                            id: 'onboarding',
                            label: 'Onboarding',
                            tone:
                                canSendInvite ||
                                onboarding.can_resend ||
                                onboarding.can_revoke
                                    ? 'warning'
                                    : undefined,
                        },
                    ]}
                >
                    <DetailTabPanel id="overview" activeId={tab}>
                        <FormSection title="Contact details" columns={2}>
                            <dl className="contents">
                                <DetailItem
                                    label="Email"
                                    value={lead.email ?? '—'}
                                />
                                <DetailItem
                                    label="Mobile"
                                    value={lead.mobile ?? '—'}
                                />
                                <DetailItem
                                    label="Company"
                                    value={lead.company_name ?? '—'}
                                />
                                <DetailItem
                                    label="State"
                                    value={lead.state ?? '—'}
                                />
                                <DetailItem
                                    label="City"
                                    value={lead.city ?? '—'}
                                />
                                <DetailItem
                                    label="Assignee"
                                    value={
                                        lead.assignee
                                            ? lead.assignee.email
                                            : 'Unassigned'
                                    }
                                />
                                {lead.remarks ? (
                                    <DetailItem
                                        className="md:col-span-2"
                                        label="Remarks"
                                        value={lead.remarks}
                                    />
                                ) : null}
                                {lead.converted_company ? (
                                    <DetailItem
                                        className="md:col-span-2"
                                        label="Converted company"
                                        value={
                                            <span className="font-medium">
                                                {lead.converted_company.name}
                                            </span>
                                        }
                                    />
                                ) : null}
                            </dl>
                        </FormSection>

                        {hasCampaignAttribution(lead) ? (
                            <FormSection
                                title="Campaign attribution"
                                description="Values captured at lead intake."
                                columns={2}
                            >
                                <dl className="contents">
                                    {lead.source_channel ? (
                                        <DetailItem
                                            label="Source channel"
                                            value={lead.source_channel}
                                        />
                                    ) : null}
                                    {lead.utm_source ? (
                                        <DetailItem
                                            label="UTM source"
                                            value={lead.utm_source}
                                        />
                                    ) : null}
                                    {lead.utm_medium ? (
                                        <DetailItem
                                            label="UTM medium"
                                            value={lead.utm_medium}
                                        />
                                    ) : null}
                                    {lead.utm_campaign ? (
                                        <DetailItem
                                            label="UTM campaign"
                                            value={lead.utm_campaign}
                                        />
                                    ) : null}
                                    {lead.landing_url ? (
                                        <DetailItem
                                            className="md:col-span-2"
                                            label="Landing URL"
                                            value={
                                                <a
                                                    href={lead.landing_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="break-all text-[var(--brand-blue)] underline-offset-4 hover:underline"
                                                >
                                                    {lead.landing_url}
                                                </a>
                                            }
                                        />
                                    ) : null}
                                    {lead.referrer ? (
                                        <DetailItem
                                            className="md:col-span-2"
                                            label="Referrer"
                                            value={lead.referrer}
                                        />
                                    ) : null}
                                </dl>
                            </FormSection>
                        ) : null}

                        {lead.allowed_transitions.length > 0 ? (
                            <Form
                                action={urls.transition}
                                method="post"
                                className="space-y-0"
                            >
                                <FormSection
                                    title="Update status"
                                    description="Disqualified and Lost require a remark."
                                    columns={2}
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="status">
                                            New status
                                        </Label>
                                        <select
                                            id="status"
                                            name="status"
                                            required
                                            className={selectClass}
                                            defaultValue={
                                                lead.allowed_transitions[0]
                                            }
                                        >
                                            {lead.allowed_transitions.map(
                                                (status) => (
                                                    <option
                                                        key={status}
                                                        value={status}
                                                    >
                                                        {status.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="transition-remark">
                                            Remark
                                        </Label>
                                        <textarea
                                            id="transition-remark"
                                            name="remark"
                                            rows={2}
                                            className={textareaClass}
                                            placeholder="Required for Disqualified/Lost"
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
                    </DetailTabPanel>

                    <DetailTabPanel id="follow-ups" activeId={tab}>
                        <Form
                            action={urls.follow_up}
                            method="post"
                            className="space-y-0"
                        >
                            <FormSection
                                title="Schedule follow-up"
                                description="Schedule the next touchpoint."
                                columns={2}
                            >
                                <div className="space-y-2">
                                    <Label htmlFor="due_at">Due at</Label>
                                    <Input
                                        id="due_at"
                                        type="datetime-local"
                                        name="due_at"
                                        required
                                    />
                                </div>
                                {can_assign ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="followup-assignee">
                                            Assignee
                                        </Label>
                                        <select
                                            id="followup-assignee"
                                            name="assigned_to_user_id"
                                            className={selectClass}
                                            defaultValue={
                                                lead.assignee?.id ?? ''
                                            }
                                        >
                                            <option value="">
                                                Lead assignee / me
                                            </option>
                                            {assignable_users.map((user) => (
                                                <option
                                                    key={user.id}
                                                    value={user.id}
                                                >
                                                    {user.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                ) : null}
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="followup-notes">
                                        Notes
                                    </Label>
                                    <textarea
                                        id="followup-notes"
                                        name="notes"
                                        rows={2}
                                        className={textareaClass}
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <Button type="submit" variant="default">
                                        Schedule follow-up
                                    </Button>
                                </div>
                            </FormSection>
                        </Form>

                        <FormSection title="Scheduled follow-ups" columns={1}>
                            <ul className="space-y-3">
                                {follow_ups.length === 0 ? (
                                    <li className="text-sm text-muted-foreground">
                                        No follow-ups scheduled.
                                    </li>
                                ) : (
                                    follow_ups.map((followUp) => (
                                        <li
                                            key={followUp.id}
                                            className="rounded-lg border border-border bg-muted/20 p-3 text-sm"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span>
                                                    Due {followUp.due_at ?? '—'}
                                                </span>
                                                {followUp.is_overdue ? (
                                                    <WorkspaceStatusBadge status="overdue" />
                                                ) : null}
                                            </div>
                                            <p className="mt-1 text-muted-foreground">
                                                {followUp.assignee ??
                                                    'Unassigned'}
                                                {followUp.notes
                                                    ? ` · ${followUp.notes}`
                                                    : ''}
                                            </p>
                                            {followUp.completed_at ? (
                                                <p className="text-muted-foreground">
                                                    Completed{' '}
                                                    {followUp.completed_at}
                                                    {followUp.completion_notes
                                                        ? ` · ${followUp.completion_notes}`
                                                        : ''}
                                                </p>
                                            ) : followUp.complete_url ? (
                                                <Form
                                                    action={
                                                        followUp.complete_url
                                                    }
                                                    method="post"
                                                    className="mt-3 flex flex-wrap items-end gap-2"
                                                >
                                                    <div className="min-w-[12rem] flex-1 space-y-2">
                                                        <Label
                                                            htmlFor={`complete-${followUp.id}`}
                                                        >
                                                            Completion remark
                                                        </Label>
                                                        <Input
                                                            id={`complete-${followUp.id}`}
                                                            name="remark"
                                                        />
                                                    </div>
                                                    <AppIconAction
                                                        icon={Check}
                                                        label="Complete follow-up"
                                                        type="submit"
                                                        variant="default"
                                                        size="default"
                                                    />
                                                </Form>
                                            ) : null}
                                        </li>
                                    ))
                                )}
                            </ul>
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="assignment" activeId={tab}>
                        {can_assign ? (
                            <Form
                                action={urls.assign}
                                method="post"
                                className="space-y-0"
                            >
                                <FormSection
                                    title="Assign lead"
                                    description="Route ownership with a mandatory remark."
                                    columns={2}
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="assigned_to_user_id">
                                            Assignee
                                        </Label>
                                        <select
                                            id="assigned_to_user_id"
                                            name="assigned_to_user_id"
                                            required
                                            className={selectClass}
                                            defaultValue={
                                                lead.assignee?.id ?? ''
                                            }
                                        >
                                            <option value="" disabled>
                                                Select internal user
                                            </option>
                                            {assignable_users.map((user) => (
                                                <option
                                                    key={user.id}
                                                    value={user.id}
                                                >
                                                    {user.name} ({user.email})
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="assign-remark">
                                            Remark
                                        </Label>
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
                                            label="Assign lead"
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
                        ) : (
                            <p className="rounded-lg border border-border bg-muted/20 px-3 py-4 text-sm text-muted-foreground">
                                Assignment is not available for this lead in its
                                current status.
                            </p>
                        )}
                    </DetailTabPanel>

                    <DetailTabPanel id="notes-activity" activeId={tab}>
                        <FormSection
                            title="Notes & activity"
                            description="Newest first. Notes are visually distinct from system events."
                            columns={1}
                        >
                            <div className="mb-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() => setNoteDialogOpen(true)}
                                >
                                    <Plus className="size-4" aria-hidden />
                                    Add note
                                </Button>
                            </div>

                            {activities.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No activity yet.
                                </p>
                            ) : (
                                <ul className="relative space-y-0">
                                    {activities.map((activity, index) => (
                                        <li
                                            key={activity.id}
                                            className="relative flex gap-3 pb-4"
                                        >
                                            {index < activities.length - 1 ? (
                                                <span
                                                    className="absolute top-3 left-[11px] h-[calc(100%-0.25rem)] w-px bg-border"
                                                    aria-hidden
                                                />
                                            ) : null}
                                            <span
                                                className={
                                                    activity.is_note
                                                        ? 'relative z-1 mt-1 size-[22px] shrink-0 rounded-full bg-[#2D8CFF] ring-2 ring-card'
                                                        : activity.activity_type ===
                                                            'website_enquiry'
                                                          ? 'relative z-1 mt-1 size-[22px] shrink-0 rounded-full bg-[var(--brand-blue)] ring-2 ring-card'
                                                          : 'relative z-1 mt-1 size-[22px] shrink-0 rounded-full bg-[var(--brand-green)] ring-2 ring-card'
                                                }
                                                aria-hidden
                                            />
                                            <div
                                                className={
                                                    activity.is_note
                                                        ? 'min-w-0 flex-1 rounded-md border border-[#2D8CFF]/30 bg-[#2D8CFF]/5 px-3 py-2 text-sm'
                                                        : activity.activity_type ===
                                                            'website_enquiry'
                                                          ? 'min-w-0 flex-1 rounded-md border border-[var(--brand-blue)]/30 bg-[var(--brand-blue)]/5 px-3 py-2 text-sm'
                                                          : 'min-w-0 flex-1 rounded-md border border-border bg-muted/20 px-3 py-2 text-sm'
                                                }
                                            >
                                                {activity.activity_type ===
                                                'website_enquiry' ? (
                                                    <span className="inline-flex items-center rounded-full bg-[var(--brand-blue)]/15 px-2 py-0.5 text-xs font-semibold text-[var(--brand-blue)]">
                                                        Website enquiry
                                                    </span>
                                                ) : (
                                                    <span className="font-medium">
                                                        {formatActivityType(
                                                            activity.activity_type,
                                                            activity.is_note,
                                                        )}
                                                    </span>
                                                )}
                                                {activity.body ? (
                                                    <p className="mt-1 whitespace-pre-wrap">
                                                        {activity.body}
                                                    </p>
                                                ) : null}
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {resolveActivityActor(
                                                        activity.activity_type,
                                                        activity.actor,
                                                    )}
                                                    {activity.created_at
                                                        ? ` · ${activity.created_at}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </FormSection>

                        <Dialog
                            open={noteDialogOpen}
                            onOpenChange={setNoteDialogOpen}
                        >
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Add note</DialogTitle>
                                    <DialogDescription>
                                        Record an internal note on this lead.
                                        Notes appear in the activity timeline.
                                    </DialogDescription>
                                </DialogHeader>
                                <Form
                                    action={urls.note}
                                    method="post"
                                    onSuccess={() => setNoteDialogOpen(false)}
                                    className="space-y-4"
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="note-body">Body</Label>
                                        <textarea
                                            id="note-body"
                                            name="body"
                                            required
                                            rows={5}
                                            className={textareaClass}
                                            placeholder="What did you learn or agree with the prospect?"
                                        />
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setNoteDialogOpen(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                        <Button type="submit">Save note</Button>
                                    </DialogFooter>
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </DetailTabPanel>

                    <DetailTabPanel id="onboarding" activeId={tab}>
                        <FormSection
                            title="Onboarding invitation"
                            description="Customer completes account setup via the public website flow."
                            columns={2}
                        >
                            <dl className="contents">
                                <DetailItem
                                    label="Status"
                                    value={
                                        <WorkspaceStatusBadge
                                            status={onboarding.status}
                                        />
                                    }
                                />
                                <DetailItem
                                    label="Recipient"
                                    value={onboarding.recipient ?? '—'}
                                />
                                <DetailItem
                                    label="Sent at"
                                    value={onboarding.sent_at ?? '—'}
                                />
                                <DetailItem
                                    label="Expires at"
                                    value={onboarding.expires_at ?? '—'}
                                />
                                <DetailItem
                                    label="Completed at"
                                    value={onboarding.completed_at ?? '—'}
                                />
                                <DetailItem
                                    label="Delivery"
                                    value={
                                        onboarding.delivery_status ? (
                                            <WorkspaceStatusBadge
                                                status={
                                                    onboarding.delivery_status
                                                }
                                            />
                                        ) : (
                                            '—'
                                        )
                                    }
                                />
                            </dl>

                            <div className="flex flex-wrap gap-2 md:col-span-2">
                                {canSendInvite ? (
                                    <Form
                                        action={urls.invite}
                                        method="post"
                                        className="inline"
                                    >
                                        <Button
                                            type="submit"
                                            variant="default"
                                            className="gap-1.5"
                                        >
                                            <Mail
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Send onboarding invite
                                        </Button>
                                    </Form>
                                ) : null}
                                {onboarding.can_resend ? (
                                    <Form
                                        action={urls.invite_resend}
                                        method="post"
                                        className="inline"
                                    >
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className="gap-1.5"
                                        >
                                            <RotateCcw
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Resend invite
                                        </Button>
                                    </Form>
                                ) : null}
                            </div>

                            {onboarding.can_revoke ? (
                                <Form
                                    action={urls.invite_revoke}
                                    method="post"
                                    className="space-y-0 md:col-span-2"
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="revoke-remark">
                                            Revoke remark
                                        </Label>
                                        <textarea
                                            id="revoke-remark"
                                            name="remark"
                                            required
                                            rows={2}
                                            className={textareaClass}
                                            placeholder="Reason for revoking this invitation"
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        className="mt-3 gap-1.5"
                                    >
                                        <XCircle
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Revoke invitation
                                    </Button>
                                </Form>
                            ) : null}

                            {!lead.email ? (
                                <p className="text-sm text-muted-foreground md:col-span-2">
                                    Add an email on this lead before sending an
                                    onboarding invite.
                                </p>
                            ) : null}
                        </FormSection>
                    </DetailTabPanel>
                </DetailTabs>
            </PageShell>
        </>
    );
}
