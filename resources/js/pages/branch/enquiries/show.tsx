import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { ChangeReview } from '@/components/form-confirmation/types';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import {
    WorkflowProgress,
    type WorkflowStep,
} from '@/components/workflow-progress';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateTime } from '@/lib/dates';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type GoodsRow = {
    id: string;
    position: number;
    goods_description: string | null;
    package_count: string | number | null;
    actual_weight: string | number | null;
    charged_weight: string | number | null;
};

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    enquiry: {
        id: string;
        enquiry_number: string | null;
        status: string;
        source: string;
        branch_id: string | null;
        customer_party_id: string | null;
        customer_name: string | null;
        pickup_location: string;
        delivery_location: string;
        vehicle_quantity: number;
        instructions: string | null;
        assigned_user_id: number | null;
        assigned_user_name: string | null;
        goods: GoodsRow[];
        status_history: {
            from_status: string | null;
            to_status: string;
            remark: string | null;
            changed_at: string | null;
        }[];
        assignment_history: {
            remark: string;
            assigned_at: string | null;
            to_branch_id: string | null;
            to_assigned_user_id: number | null;
        }[];
        follow_ups: {
            id: string;
            follow_up_at: string | null;
            remarks: string;
            completed_at: string | null;
        }[];
        quotations: {
            id: string;
            transporter_party_id: string;
            transporter_name: string | null;
            quoted_freight: string;
            selection_status: string;
            remarks: string | null;
            select_url: string;
        }[];
        broadcast_requests: {
            id: string;
            status: string;
            channels: string[];
            target_count: number;
            requested_at: string | null;
        }[];
        conversion: {
            id: string;
            converted_at: string | null;
            lorry_receipt_ids: string[];
        } | null;
    };
    customers?: { id: string; name: string }[];
    branches: { id: string; name: string }[];
    assignable_users: { id: number; name: string; email: string | null }[];
    transporters: { id: string; name: string }[];
    vehicles: { id: string; registration_number: string | null }[];
    conversion_preview: {
        eligible: boolean;
        eligibility_errors: string[];
        suggested_vehicles: {
            position: number;
            goods: {
                goods_row_id: string;
                package_count: string | null;
                actual_weight: string | null;
                charged_weight: string | null;
            }[];
        }[];
        freight_per_vehicle: string[];
        reconciliation_totals: Record<string, string | number>;
    } | null;
    capabilities: { assign: boolean; edit: boolean; convert: boolean };
    urls: {
        index: string;
        update?: string;
        assign: string;
        follow_up: string;
        quotation_store: string;
        broadcast: string;
        convert: string;
        parties_create?: string;
        parties_store?: string;
        parties_options?: string;
    };
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

const ENQUIRY_PIPELINE = [
    'new',
    'under_review',
    'quotation_requested',
    'quotations_received',
    'selected',
    'converted',
] as const;

const PIPELINE_LABELS: Record<(typeof ENQUIRY_PIPELINE)[number], string> = {
    new: 'New',
    under_review: 'Under review',
    quotation_requested: 'Quote requested',
    quotations_received: 'Quotes received',
    selected: 'Selected',
    converted: 'Converted',
};

function buildEnquiryWorkflowSteps(
    status: string,
    hasConversion: boolean,
): WorkflowStep[] {
    const normalized = status.toLowerCase();

    if (normalized === 'rejected' || normalized === 'cancelled') {
        return ENQUIRY_PIPELINE.slice(0, 4).map((id, position) => ({
            id,
            label: PIPELINE_LABELS[id],
            status: position === 0 ? 'blocked' : 'upcoming',
        }));
    }

    if (hasConversion || normalized === 'converted') {
        return ENQUIRY_PIPELINE.map((id) => ({
            id,
            label: PIPELINE_LABELS[id],
            status: 'complete' as const,
        }));
    }

    const idx = Math.max(
        0,
        ENQUIRY_PIPELINE.indexOf(
            normalized as (typeof ENQUIRY_PIPELINE)[number],
        ),
    );

    return ENQUIRY_PIPELINE.map((id, position) => {
        const label = PIPELINE_LABELS[id];
        if (position < idx) {
            return { id, label, status: 'complete' as const };
        }
        if (position === idx) {
            const stepStatus: WorkflowStep['status'] =
                normalized === 'on_hold' ? 'blocked' : 'current';
            return { id, label, status: stepStatus };
        }
        return { id, label, status: 'upcoming' as const };
    });
}

export default function BranchEnquiryShow({
    company,
    branch,
    enquiry,
    customers = [],
    branches,
    assignable_users,
    transporters,
    vehicles,
    conversion_preview,
    capabilities,
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [showConvert, setShowConvert] = useState(false);
    const [tab, setTab] = useState('overview');
    const [customerPartyId, setCustomerPartyId] = useState(
        enquiry.customer_party_id ?? '',
    );
    const [editConfirmOpen, setEditConfirmOpen] = useState(false);
    const [editChanges, setEditChanges] = useState<ChangeReview[]>([]);
    const editSubmitAllowed = useRef(false);
    const vehicleId = vehicles[0]?.id ?? '';
    const suggestedVehicles = useMemo(
        () => conversion_preview?.suggested_vehicles ?? [],
        [conversion_preview],
    );

    const broadcastTargets = transporters.slice(0, 3).map((t) => t.id);

    const openEditConfirmation = (form: HTMLFormElement) => {
        const data = new FormData(form);
        const formText = (key: string): string => {
            const value = data.get(key);
            return typeof value === 'string' ? value.trim() : '';
        };
        const customerName =
            customers.find((option) => option.id === customerPartyId)?.name ??
            (
                form.querySelector(
                    '#customer_party_id',
                ) as HTMLSelectElement | null
            )?.selectedOptions?.[0]?.text?.trim() ??
            enquiry.customer_name ??
            '—';
        const pickup = formText('pickup_location');
        const delivery = formText('delivery_location');
        const remark = formText('remark');

        const changes: ChangeReview[] = [];
        if ((enquiry.customer_party_id ?? '') !== customerPartyId) {
            changes.push({
                label: 'Customer',
                previous: enquiry.customer_name ?? '—',
                next: customerName || '—',
                changeType: 'modified',
            });
        }
        if (pickup !== enquiry.pickup_location) {
            changes.push({
                label: 'Pickup',
                previous: enquiry.pickup_location,
                next: pickup || '—',
                changeType: 'modified',
            });
        }
        if (delivery !== enquiry.delivery_location) {
            changes.push({
                label: 'Delivery',
                previous: enquiry.delivery_location,
                next: delivery || '—',
                changeType: 'modified',
            });
        }
        changes.push({
            label: 'Audit remark',
            previous: '—',
            next: remark || '—',
            changeType: 'added',
        });
        setEditChanges(changes);
        setEditConfirmOpen(true);
    };

    const openFollowUps = enquiry.follow_ups.filter(
        (row) => !row.completed_at,
    ).length;

    const workflowSteps = useMemo(
        () => buildEnquiryWorkflowSteps(enquiry.status, !!enquiry.conversion),
        [enquiry.status, enquiry.conversion],
    );

    const preferredQuotation = enquiry.quotations.some(
        (quote) => quote.selection_status === 'preferred',
    );

    return (
        <>
            <Head
                title={`${enquiry.enquiry_number ?? 'Enquiry'} · ${branch.name}`}
            />
            <PageShell
                title={enquiry.enquiry_number ?? enquiry.id.slice(0, 8)}
                description={`${company.name} · ${branch.name} · ${enquiry.source}`}
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to enquiries"
                        href={urls.index}
                        variant="outline"
                    />
                }
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Status"
                        value={<WorkspaceStatusBadge status={enquiry.status} />}
                        tone="blue"
                    />
                    <MetricCard
                        label="Vehicles"
                        value={enquiry.vehicle_quantity}
                        tone="green"
                    />
                    <MetricCard
                        label="Assignee"
                        value={enquiry.assigned_user_name ?? 'Unassigned'}
                        tone="amber"
                    />
                    <MetricCard
                        label="Route"
                        value={`${enquiry.pickup_location} → ${enquiry.delivery_location}`}
                        hint={enquiry.instructions ?? undefined}
                        tone="neutral"
                        className="sm:col-span-2 lg:col-span-1"
                    />
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                {errors && Object.keys(errors).length > 0 ? (
                    <p className="text-sm text-destructive" role="alert">
                        {Object.values(errors).join(' ')}
                    </p>
                ) : null}

                <WorkflowProgress
                    aria-label="Enquiry lifecycle"
                    steps={workflowSteps}
                />

                <DetailTabs
                    aria-label="Enquiry detail sections"
                    activeId={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'overview', label: 'Overview' },
                        { id: 'assignment', label: 'Assignment' },
                        {
                            id: 'follow-ups',
                            label: 'Follow-ups',
                            count: enquiry.follow_ups.length,
                            tone: openFollowUps > 0 ? 'warning' : undefined,
                        },
                        {
                            id: 'quotations',
                            label: 'Quotations',
                            count: enquiry.quotations.length,
                        },
                        {
                            id: 'conversion',
                            label: 'Conversion',
                            tone:
                                capabilities.convert &&
                                !enquiry.conversion &&
                                conversion_preview?.eligible
                                    ? 'warning'
                                    : undefined,
                        },
                        {
                            id: 'history',
                            label: 'History',
                            count: enquiry.status_history.length,
                        },
                    ]}
                >
                    <DetailTabPanel id="overview" activeId={tab}>
                        <FormSection title="Route & instructions" columns={2}>
                            <div className="space-y-1">
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Customer
                                </p>
                                <p className="text-sm font-medium">
                                    {enquiry.customer_name ?? '—'}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Pickup
                                </p>
                                <p className="text-sm font-medium">
                                    {enquiry.pickup_location}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Delivery
                                </p>
                                <p className="text-sm font-medium">
                                    {enquiry.delivery_location}
                                </p>
                            </div>
                            {enquiry.instructions ? (
                                <div className="space-y-1 md:col-span-2">
                                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Instructions
                                    </p>
                                    <p className="text-sm">
                                        {enquiry.instructions}
                                    </p>
                                </div>
                            ) : null}
                        </FormSection>

                        {capabilities.edit && urls.update ? (
                            <FormSection title="Edit enquiry" columns={2}>
                                <Form
                                    id="enquiry-edit-form"
                                    action={urls.update}
                                    method="patch"
                                    className="space-y-3 md:col-span-2"
                                    onSubmit={(event) => {
                                        if (!editSubmitAllowed.current) {
                                            event.preventDefault();
                                            openEditConfirmation(
                                                event.currentTarget,
                                            );
                                        } else {
                                            editSubmitAllowed.current = false;
                                        }
                                    }}
                                >
                                    <SearchablePartySelect
                                        id="customer_party_id"
                                        name="customer_party_id"
                                        label="Customer"
                                        options={customers}
                                        searchUrl={urls.parties_options}
                                        role="customer"
                                        createStoreUrl={urls.parties_store}
                                        createHref={urls.parties_create}
                                        createLabel="Add"
                                        createRole="customer"
                                        required
                                        defaultValue={
                                            enquiry.customer_party_id ?? ''
                                        }
                                        onValueChange={setCustomerPartyId}
                                        emptyMessage="No eligible Customers in this branch."
                                    />
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label htmlFor="pickup_location">
                                                Pickup
                                            </Label>
                                            <Input
                                                id="pickup_location"
                                                name="pickup_location"
                                                defaultValue={
                                                    enquiry.pickup_location
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="delivery_location">
                                                Delivery
                                            </Label>
                                            <Input
                                                id="delivery_location"
                                                name="delivery_location"
                                                defaultValue={
                                                    enquiry.delivery_location
                                                }
                                                required
                                            />
                                        </div>
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="edit_remark">
                                            Audit remark
                                        </Label>
                                        <Input
                                            id="edit_remark"
                                            name="remark"
                                            required
                                            placeholder="Why is this enquiry changing?"
                                        />
                                    </div>
                                    <Button type="submit">Save changes</Button>
                                </Form>
                            </FormSection>
                        ) : null}

                        <FormSection title="Goods" columns={2}>
                            <ul className="space-y-2 text-sm md:col-span-2">
                                {enquiry.goods.map((row) => (
                                    <li
                                        key={row.id}
                                        className="rounded-md border border-border px-3 py-2"
                                    >
                                        #{row.position}{' '}
                                        {row.goods_description ?? 'Goods'}
                                        {row.package_count != null ? (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {row.package_count} pkg
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </FormSection>

                        {enquiry.conversion ? (
                            <FormSection title="Conversion summary" columns={1}>
                                <p className="text-sm">
                                    {formatDisplayDateTime(
                                        enquiry.conversion.converted_at,
                                    )}{' '}
                                    —{' '}
                                    {
                                        enquiry.conversion.lorry_receipt_ids
                                            .length
                                    }{' '}
                                    LR(s) created
                                </p>
                            </FormSection>
                        ) : null}
                    </DetailTabPanel>

                    <DetailTabPanel id="assignment" activeId={tab}>
                        {capabilities.assign ? (
                            <FormSection title="Assign / reassign" columns={2}>
                                <Form
                                    action={urls.assign}
                                    method="post"
                                    className="space-y-3 md:col-span-2"
                                >
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label htmlFor="to_branch_id">
                                                Branch
                                            </Label>
                                            <select
                                                id="to_branch_id"
                                                name="to_branch_id"
                                                className={fieldClass}
                                                defaultValue={
                                                    enquiry.branch_id ??
                                                    branch.id
                                                }
                                            >
                                                {branches.map((b) => (
                                                    <option
                                                        key={b.id}
                                                        value={b.id}
                                                    >
                                                        {b.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="to_assigned_user_id">
                                                User
                                            </Label>
                                            <select
                                                id="to_assigned_user_id"
                                                name="to_assigned_user_id"
                                                className={fieldClass}
                                                defaultValue={
                                                    enquiry.assigned_user_id ??
                                                    ''
                                                }
                                            >
                                                <option value="">
                                                    Unassigned user
                                                </option>
                                                {assignable_users.map(
                                                    (user) => (
                                                        <option
                                                            key={user.id}
                                                            value={user.id}
                                                        >
                                                            {user.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="assign_remark">
                                            Remark
                                        </Label>
                                        <Input
                                            id="assign_remark"
                                            name="remark"
                                            required
                                        />
                                    </div>
                                    <Button type="submit">
                                        Assign / reassign
                                    </Button>
                                </Form>
                            </FormSection>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                You do not have permission to change assignment.
                            </p>
                        )}

                        <FormSection title="Assignment history" columns={1}>
                            {enquiry.assignment_history.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No assignment events yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {enquiry.assignment_history.map(
                                        (row, i) => (
                                            <li
                                                key={`${row.assigned_at}-${i}`}
                                                className="rounded-md border border-border px-3 py-2"
                                            >
                                                {formatDisplayDateTime(
                                                    row.assigned_at,
                                                )}{' '}
                                                — {row.remark}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="follow-ups" activeId={tab}>
                        {capabilities.edit ? (
                            <FormSection title="Schedule follow-up" columns={2}>
                                <Form
                                    action={urls.follow_up}
                                    method="post"
                                    className="space-y-3 md:col-span-2"
                                >
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label htmlFor="follow_up_at">
                                                Due at
                                            </Label>
                                            <Input
                                                id="follow_up_at"
                                                name="follow_up_at"
                                                type="datetime-local"
                                                required
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="remarks">
                                                Remarks
                                            </Label>
                                            <Input
                                                id="remarks"
                                                name="remarks"
                                                required
                                            />
                                        </div>
                                    </div>
                                    <Button type="submit">Add follow-up</Button>
                                </Form>
                            </FormSection>
                        ) : null}

                        <FormSection title="Follow-up list" columns={1}>
                            {enquiry.follow_ups.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No follow-ups recorded.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {enquiry.follow_ups.map((row) => (
                                        <li
                                            key={row.id}
                                            className="rounded-md border border-border px-3 py-2"
                                        >
                                            {formatDisplayDateTime(
                                                row.follow_up_at,
                                            )}{' '}
                                            — {row.remarks}
                                            {row.completed_at
                                                ? ' (completed)'
                                                : ' (open)'}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="quotations" activeId={tab}>
                        {capabilities.edit ? (
                            <>
                                <FormSection
                                    title="Record quotation"
                                    columns={3}
                                >
                                    <Form
                                        action={urls.quotation_store}
                                        method="post"
                                        className="grid gap-3 md:col-span-3 md:grid-cols-3"
                                    >
                                        <div className="space-y-1 md:col-span-1">
                                            <SearchablePartySelect
                                                id="transporter_party_id"
                                                name="transporter_party_id"
                                                label="Transporter"
                                                options={transporters}
                                                searchUrl={urls.parties_options}
                                                role="transporter"
                                                createStoreUrl={
                                                    urls.parties_store
                                                }
                                                createHref={urls.parties_create}
                                                createLabel="Add"
                                                createRole="transporter"
                                                required
                                                emptyMessage="No eligible Transporters in this branch."
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="quoted_freight">
                                                Freight
                                            </Label>
                                            <Input
                                                id="quoted_freight"
                                                name="quoted_freight"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                required
                                            />
                                        </div>
                                        <div className="flex items-end">
                                            <Button
                                                type="submit"
                                                className="w-full"
                                            >
                                                Save quotation
                                            </Button>
                                        </div>
                                    </Form>
                                </FormSection>

                                <FormSection
                                    title="Broadcast request (queued only)"
                                    columns={1}
                                >
                                    <Form
                                        action={urls.broadcast}
                                        method="post"
                                        className="space-y-2"
                                    >
                                        {broadcastTargets.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="transporter_party_ids[]"
                                                value={id}
                                            />
                                        ))}
                                        <input
                                            type="hidden"
                                            name="channels[]"
                                            value="email"
                                        />
                                        <Button
                                            type="submit"
                                            variant="secondary"
                                        >
                                            Queue broadcast request
                                        </Button>
                                    </Form>
                                    {enquiry.broadcast_requests.map((req) => (
                                        <p
                                            key={req.id}
                                            className="text-xs text-muted-foreground"
                                        >
                                            {formatDisplayDateTime(
                                                req.requested_at,
                                            )}{' '}
                                            · {req.status} · {req.target_count}{' '}
                                            targets · channels:{' '}
                                            {req.channels.join(', ')}
                                        </p>
                                    ))}
                                </FormSection>
                            </>
                        ) : null}

                        <FormSection title="Quotations" columns={1}>
                            {enquiry.quotations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No quotations yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {enquiry.quotations.map((quote) => (
                                        <li
                                            key={quote.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border px-3 py-2"
                                        >
                                            <span>
                                                {quote.transporter_name ??
                                                    quote.transporter_party_id}{' '}
                                                · ₹{quote.quoted_freight} ·{' '}
                                                {quote.selection_status}
                                            </span>
                                            {quote.selection_status !==
                                            'preferred' ? (
                                                capabilities.edit ? (
                                                    <Form
                                                        action={
                                                            quote.select_url
                                                        }
                                                        method="post"
                                                    >
                                                        <Button
                                                            type="submit"
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            Select preferred
                                                        </Button>
                                                    </Form>
                                                ) : null
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {preferredQuotation ? (
                                <p className="text-xs text-muted-foreground">
                                    A preferred transporter is selected for
                                    conversion eligibility.
                                </p>
                            ) : null}
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="conversion" activeId={tab}>
                        {enquiry.conversion ? (
                            <FormSection title="Converted" columns={1}>
                                <p className="text-sm">
                                    {formatDisplayDateTime(
                                        enquiry.conversion.converted_at,
                                    )}{' '}
                                    —{' '}
                                    {
                                        enquiry.conversion.lorry_receipt_ids
                                            .length
                                    }{' '}
                                    LR(s) created
                                </p>
                            </FormSection>
                        ) : capabilities.convert ? (
                            <FormSection
                                title="Conversion (N vehicles → N LRs)"
                                columns={1}
                            >
                                {conversion_preview &&
                                !conversion_preview.eligible ? (
                                    <p className="text-sm text-destructive">
                                        {conversion_preview.eligibility_errors.join(
                                            ' ',
                                        )}
                                    </p>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setShowConvert((v) => !v)}
                                >
                                    {showConvert
                                        ? 'Hide preview'
                                        : 'Preview & confirm'}
                                </Button>
                                {showConvert && conversion_preview ? (
                                    <Form
                                        action={urls.convert}
                                        method="post"
                                        className="space-y-3"
                                    >
                                        <input
                                            type="hidden"
                                            name="workflow_context"
                                            value="broker"
                                        />
                                        <input
                                            type="hidden"
                                            name="vehicle_id"
                                            value={vehicleId}
                                        />
                                        <input
                                            type="hidden"
                                            name="idempotency_key"
                                            value={`convert-${enquiry.id}`}
                                        />
                                        {suggestedVehicles.map(
                                            (vehicle, vIndex) => (
                                                <div key={vehicle.position}>
                                                    <p className="text-sm font-medium">
                                                        Vehicle slot{' '}
                                                        {vehicle.position}
                                                    </p>
                                                    {vehicle.goods.map((g) => (
                                                        <div
                                                            key={g.goods_row_id}
                                                        >
                                                            <input
                                                                type="hidden"
                                                                name={`vehicles[${vIndex}][goods][][goods_row_id]`}
                                                                value={
                                                                    g.goods_row_id
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name={`vehicles[${vIndex}][goods][][package_count]`}
                                                                value={
                                                                    g.package_count ??
                                                                    ''
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name={`vehicles[${vIndex}][goods][][actual_weight]`}
                                                                value={
                                                                    g.actual_weight ??
                                                                    ''
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name={`vehicles[${vIndex}][goods][][charged_weight]`}
                                                                value={
                                                                    g.charged_weight ??
                                                                    ''
                                                                }
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                            ),
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            Freight per LR:{' '}
                                            {conversion_preview.freight_per_vehicle.join(
                                                ', ',
                                            )}
                                        </p>
                                        <Button type="submit">
                                            Confirm conversion
                                        </Button>
                                    </Form>
                                ) : null}
                            </FormSection>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Conversion is not available for this enquiry or
                                your role.
                            </p>
                        )}
                    </DetailTabPanel>

                    <DetailTabPanel id="history" activeId={tab}>
                        <FormSection title="Status history" columns={1}>
                            {enquiry.status_history.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No status changes recorded.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {enquiry.status_history.map(
                                        (row, index) => (
                                            <li
                                                key={`${row.changed_at}-${index}`}
                                                className="rounded-md border border-border px-3 py-2"
                                            >
                                                {formatDisplayDateTime(
                                                    row.changed_at,
                                                )}{' '}
                                                · {row.from_status ?? '—'} →{' '}
                                                {row.to_status}
                                                {row.remark
                                                    ? ` — ${row.remark}`
                                                    : ''}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                        </FormSection>
                    </DetailTabPanel>
                </DetailTabs>
            </PageShell>

            <FormConfirmationDialog
                open={editConfirmOpen}
                onOpenChange={setEditConfirmOpen}
                mode="edit"
                title="Update this enquiry?"
                description="Review changed fields and the mandatory audit remark before saving."
                changes={editChanges}
                onConfirm={() => {
                    editSubmitAllowed.current = true;
                    setEditConfirmOpen(false);
                    window.setTimeout(() => {
                        const formElement = document.getElementById(
                            'enquiry-edit-form',
                        ) as HTMLFormElement | null;
                        formElement?.requestSubmit();
                    }, 0);
                }}
            />
        </>
    );
}
