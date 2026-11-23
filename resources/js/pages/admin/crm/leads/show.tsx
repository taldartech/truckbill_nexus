import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Save, UserPlus } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

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

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const PIPELINE_ORDER = ['new', 'contacted', 'qualified', 'converted'] as const;

function buildLeadWorkflowSteps(status: string): WorkflowStep[] {
    const normalized = status.toLowerCase();

    if (normalized === 'disqualified' || normalized === 'lost') {
        return PIPELINE_ORDER.map((id, position) => ({
            id,
            label: id.charAt(0).toUpperCase() + id.slice(1),
            status: position === 0 ? 'blocked' : 'upcoming',
        }));
    }

    const idx = Math.max(
        0,
        PIPELINE_ORDER.indexOf(normalized as (typeof PIPELINE_ORDER)[number]),
    );

    return PIPELINE_ORDER.map((id, position) => {
        const label = id.charAt(0).toUpperCase() + id.slice(1);
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

    const overdueFollowUps = follow_ups.filter(
        (followUp) => followUp.is_overdue && !followUp.completed_at,
    ).length;

    const [tab, setTab] = useState('overview');

    const workflowSteps = useMemo(
        () => buildLeadWorkflowSteps(lead.status),
        [lead.status],
    );

    return (
        <>
            <Head title={`${lead.contact_name} · lead`} />
            <PageShell
                title={lead.contact_name}
                description={`${lead.interested_product} · ${lead.source}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge status={lead.status} />
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
                            id: 'conversion',
                            label: 'Conversion',
                            tone: lead.can_convert ? 'warning' : undefined,
                        },
                        {
                            id: 'activity',
                            label: 'Activity',
                            count: activities.length,
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
                                                        {status}
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

                    <DetailTabPanel id="conversion" activeId={tab}>
                        {lead.can_convert ? (
                            <Form
                                action={urls.convert}
                                method="post"
                                className="space-y-0"
                            >
                                <FormSection
                                    title="Convert to customer company"
                                    description="Creates or reuses the Customer identity and company profile. Existing passwords are never overwritten."
                                    columns={2}
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="name">
                                            Company name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            defaultValue={
                                                lead.company_name ?? ''
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="pan">PAN</Label>
                                        <Input
                                            id="pan"
                                            name="pan"
                                            required
                                            maxLength={10}
                                            className="uppercase"
                                        />
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="registered_address">
                                            Registered address
                                        </Label>
                                        <Input
                                            id="registered_address"
                                            name="registered_address"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="state">State</Label>
                                        <Input
                                            id="state"
                                            name="state"
                                            required
                                            defaultValue="Maharashtra"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="city">City</Label>
                                        <Input id="city" name="city" required />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="pin">PIN</Label>
                                        <Input
                                            id="pin"
                                            name="pin"
                                            required
                                            maxLength={6}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="mobile-convert">
                                            Mobile
                                        </Label>
                                        <Input
                                            id="mobile-convert"
                                            name="mobile"
                                            required
                                            defaultValue={lead.mobile ?? ''}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email-convert">
                                            Email
                                        </Label>
                                        <Input
                                            id="email-convert"
                                            type="email"
                                            name="email"
                                            defaultValue={lead.email ?? ''}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="gst_registration_type">
                                            GST registration
                                        </Label>
                                        <select
                                            id="gst_registration_type"
                                            name="gst_registration_type"
                                            required
                                            className={selectClass}
                                            defaultValue={
                                                gst_registration_types[0]
                                            }
                                        >
                                            {gst_registration_types.map(
                                                (value) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {value}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="tax_liability">
                                            Tax liability
                                        </Label>
                                        <select
                                            id="tax_liability"
                                            name="tax_liability"
                                            required
                                            className={selectClass}
                                            defaultValue={tax_liabilities[0]}
                                        >
                                            {tax_liabilities.map((value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="gstin">GSTIN</Label>
                                        <Input
                                            id="gstin"
                                            name="gstin"
                                            maxLength={15}
                                            className="uppercase"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="gst_rate_id">
                                            GST rate
                                        </Label>
                                        <select
                                            id="gst_rate_id"
                                            name="gst_rate_id"
                                            className={selectClass}
                                            defaultValue={
                                                gst_rates[0]?.id ?? ''
                                            }
                                        >
                                            <option value="">None</option>
                                            {gst_rates.map((rate) => (
                                                <option
                                                    key={rate.id}
                                                    value={rate.id}
                                                >
                                                    {rate.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="data_visibility">
                                            Data visibility
                                        </Label>
                                        <select
                                            id="data_visibility"
                                            name="data_visibility"
                                            required
                                            className={selectClass}
                                            defaultValue={data_visibilities[0]}
                                        >
                                            {data_visibilities.map((value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="timezone">
                                            Timezone
                                        </Label>
                                        <Input
                                            id="timezone"
                                            name="timezone"
                                            defaultValue="Asia/Kolkata"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="initial_branch_name">
                                            Initial branch
                                        </Label>
                                        <Input
                                            id="initial_branch_name"
                                            name="initial_branch_name"
                                            defaultValue="Head Office"
                                        />
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="new_password">
                                            New password (only when creating a
                                            new identity)
                                        </Label>
                                        <Input
                                            id="new_password"
                                            type="password"
                                            name="new_password"
                                            minLength={12}
                                            autoComplete="new-password"
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Button type="submit" variant="default">
                                            Convert lead
                                        </Button>
                                    </div>
                                </FormSection>
                            </Form>
                        ) : lead.converted_company ? (
                            <FormSection title="Conversion result" columns={1}>
                                <p className="text-sm">
                                    Converted to{' '}
                                    <span className="font-medium">
                                        {lead.converted_company.name}
                                    </span>
                                    .
                                </p>
                            </FormSection>
                        ) : (
                            <p className="rounded-lg border border-border bg-muted/20 px-3 py-4 text-sm text-muted-foreground">
                                Conversion unlocks when the lead reaches
                                Qualified status.
                            </p>
                        )}
                    </DetailTabPanel>

                    <DetailTabPanel id="activity" activeId={tab}>
                        <FormSection title="Activity" columns={1}>
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
                                                className="relative z-1 mt-1 size-[22px] shrink-0 rounded-full bg-[var(--brand-green)] ring-2 ring-card"
                                                aria-hidden
                                            />
                                            <div className="min-w-0 flex-1 rounded-md border border-border bg-muted/20 px-3 py-2 text-sm">
                                                <span className="font-medium">
                                                    {activity.activity_type}
                                                </span>
                                                {activity.body
                                                    ? ` — ${activity.body}`
                                                    : ''}
                                                {activity.created_at ? (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {activity.created_at}
                                                    </p>
                                                ) : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </FormSection>
                    </DetailTabPanel>
                </DetailTabs>
            </PageShell>
        </>
    );
}
