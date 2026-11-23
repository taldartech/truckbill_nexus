import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
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
        assign: string;
        follow_up: string;
        quotation_store: string;
        broadcast: string;
        convert: string;
    };
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

export default function BranchEnquiryShow({
    company,
    branch,
    enquiry,
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
    const vehicleId = vehicles[0]?.id ?? '';
    const suggestedVehicles = useMemo(
        () => conversion_preview?.suggested_vehicles ?? [],
        [conversion_preview],
    );

    const broadcastTargets = transporters.slice(0, 3).map((t) => t.id);

    return (
        <>
            <Head
                title={`${enquiry.enquiry_number ?? 'Enquiry'} · ${branch.name}`}
            />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Enquiries
                    </Link>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {company.name} · {branch.name}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {enquiry.enquiry_number ?? enquiry.id.slice(0, 8)}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {enquiry.status} · {enquiry.source}
                        {enquiry.assigned_user_name
                            ? ` · Assigned to ${enquiry.assigned_user_name}`
                            : ''}
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

                {errors && Object.keys(errors).length > 0 ? (
                    <p className="text-sm text-destructive" role="alert">
                        {Object.values(errors).join(' ')}
                    </p>
                ) : null}

                <section className="space-y-2 rounded-xl border border-border p-4 text-sm">
                    <p>
                        {enquiry.pickup_location} → {enquiry.delivery_location}
                    </p>
                    <p>Vehicles requested: {enquiry.vehicle_quantity}</p>
                    {enquiry.instructions ? (
                        <p>Instructions: {enquiry.instructions}</p>
                    ) : null}
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Goods</h2>
                    <ul className="space-y-2 text-sm">
                        {enquiry.goods.map((row) => (
                            <li
                                key={row.id}
                                className="rounded-md border border-border px-3 py-2"
                            >
                                #{row.position}{' '}
                                {row.goods_description ?? 'Goods'}
                            </li>
                        ))}
                    </ul>
                </section>

                {capabilities.assign ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Assignment</h2>
                        <Form
                            action={urls.assign}
                            method="post"
                            className="space-y-3"
                        >
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="to_branch_id">Branch</Label>
                                    <select
                                        id="to_branch_id"
                                        name="to_branch_id"
                                        className={fieldClass}
                                        defaultValue={
                                            enquiry.branch_id ?? branch.id
                                        }
                                    >
                                        {branches.map((b) => (
                                            <option key={b.id} value={b.id}>
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
                                            enquiry.assigned_user_id ?? ''
                                        }
                                    >
                                        <option value="">
                                            Unassigned user
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
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="assign_remark">Remark</Label>
                                <Input
                                    id="assign_remark"
                                    name="remark"
                                    required
                                />
                            </div>
                            <Button type="submit">Assign / reassign</Button>
                        </Form>
                        {enquiry.assignment_history.length > 0 ? (
                            <ul className="space-y-2 text-xs text-muted-foreground">
                                {enquiry.assignment_history.map((row, i) => (
                                    <li key={`${row.assigned_at}-${i}`}>
                                        {row.assigned_at} — {row.remark}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </section>
                ) : null}

                {capabilities.edit ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Follow-ups</h2>
                        <Form
                            action={urls.follow_up}
                            method="post"
                            className="space-y-3"
                        >
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="follow_up_at">Due at</Label>
                                    <Input
                                        id="follow_up_at"
                                        name="follow_up_at"
                                        type="datetime-local"
                                        required
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="remarks">Remarks</Label>
                                    <Input
                                        id="remarks"
                                        name="remarks"
                                        required
                                    />
                                </div>
                            </div>
                            <Button type="submit">Add follow-up</Button>
                        </Form>
                        <ul className="space-y-2 text-sm">
                            {enquiry.follow_ups.map((row) => (
                                <li
                                    key={row.id}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    {row.follow_up_at} — {row.remarks}
                                    {row.completed_at
                                        ? ' (completed)'
                                        : ' (open)'}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {capabilities.edit ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Quotations</h2>
                        <Form
                            action={urls.quotation_store}
                            method="post"
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <div className="space-y-1 md:col-span-1">
                                <Label htmlFor="transporter_party_id">
                                    Transporter
                                </Label>
                                <select
                                    id="transporter_party_id"
                                    name="transporter_party_id"
                                    className={fieldClass}
                                    required
                                >
                                    <option value="">Select</option>
                                    {transporters.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="quoted_freight">Freight</Label>
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
                                <Button type="submit" className="w-full">
                                    Save quotation
                                </Button>
                            </div>
                        </Form>
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
                                    {quote.selection_status !== 'preferred' ? (
                                        <Form
                                            action={quote.select_url}
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
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        <div className="space-y-2 border-t border-border pt-3">
                            <h3 className="text-sm font-medium">
                                Broadcast request (queued only)
                            </h3>
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
                                <Button type="submit" variant="secondary">
                                    Queue broadcast request
                                </Button>
                            </Form>
                            {enquiry.broadcast_requests.map((req) => (
                                <p
                                    key={req.id}
                                    className="text-xs text-muted-foreground"
                                >
                                    {req.requested_at} · {req.status} ·{' '}
                                    {req.target_count} targets · channels:{' '}
                                    {req.channels.join(', ')}
                                </p>
                            ))}
                        </div>
                    </section>
                ) : null}

                {capabilities.convert && !enquiry.conversion ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">
                            Conversion (Option C — N vehicles → N LRs)
                        </h2>
                        {conversion_preview && !conversion_preview.eligible ? (
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
                            {showConvert ? 'Hide preview' : 'Preview & confirm'}
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
                                {suggestedVehicles.map((vehicle, vIndex) => (
                                    <div key={vehicle.position}>
                                        <p className="text-sm font-medium">
                                            Vehicle slot {vehicle.position}
                                        </p>
                                        {vehicle.goods.map((g) => (
                                            <div key={g.goods_row_id}>
                                                <input
                                                    type="hidden"
                                                    name={`vehicles[${vIndex}][goods][][goods_row_id]`}
                                                    value={g.goods_row_id}
                                                />
                                                <input
                                                    type="hidden"
                                                    name={`vehicles[${vIndex}][goods][][package_count]`}
                                                    value={
                                                        g.package_count ?? ''
                                                    }
                                                />
                                                <input
                                                    type="hidden"
                                                    name={`vehicles[${vIndex}][goods][][actual_weight]`}
                                                    value={
                                                        g.actual_weight ?? ''
                                                    }
                                                />
                                                <input
                                                    type="hidden"
                                                    name={`vehicles[${vIndex}][goods][][charged_weight]`}
                                                    value={
                                                        g.charged_weight ?? ''
                                                    }
                                                />
                                            </div>
                                        ))}
                                    </div>
                                ))}
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
                    </section>
                ) : null}

                {enquiry.conversion ? (
                    <section className="rounded-xl border border-border p-4 text-sm">
                        <h2 className="font-semibold">Converted</h2>
                        <p>
                            {enquiry.conversion.converted_at} —{' '}
                            {enquiry.conversion.lorry_receipt_ids.length} LR(s)
                            created
                        </p>
                    </section>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Status history</h2>
                    <ul className="space-y-2 text-sm">
                        {enquiry.status_history.map((row, index) => (
                            <li
                                key={`${row.changed_at}-${index}`}
                                className="rounded-md border border-border px-3 py-2"
                            >
                                {row.from_status ?? '—'} → {row.to_status}
                                {row.remark ? ` — ${row.remark}` : ''}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
