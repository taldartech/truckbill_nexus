import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import {
    DetailItem,
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

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
    branches?: { id: string; name: string }[];
    enquiries_entitled?: boolean;
    enquiry_webhook_credentials?: {
        id: string;
        label: string | null;
        token_prefix: string;
        receiving_branch_id: string | null;
        last_used_at: string | null;
        revoked_at: string | null;
        is_usable: boolean;
        rotate_url: string;
        revoke_url: string;
        update_url: string;
    }[];
    urls: {
        index: string;
        deactivate: string;
        reactivate: string;
        transfer: string;
        enquiry_webhook_store?: string;
    };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function AdminCompanyShow({
    company,
    customer_candidates,
    transfers,
    audit_entries,
    branches = [],
    enquiries_entitled = false,
    enquiry_webhook_credentials = [],
    urls,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: {
            success?: string | null;
            enquiry_webhook_plain_token?: string | null;
        };
        errors?: Record<string, string>;
    }>().props;

    const plainToken = flash?.enquiry_webhook_plain_token ?? null;

    return (
        <>
            <Head title={`${company.name} · lifecycle`} />
            <PageShell
                title={company.name}
                description="Company lifecycle, ownership transfer, and audit trail."
                badges={
                    <WorkspaceStatusBadge
                        status={company.is_active ? 'active' : 'deactivated'}
                    />
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to companies"
                            href={urls.index}
                            variant="outline"
                        />
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['remark', 'target_user_id']}
                />

                <FormSection title="Profile" columns={2}>
                    <dl className="contents">
                        <DetailItem label="PAN" value={company.pan ?? '—'} />
                        <DetailItem
                            label="GSTIN"
                            value={company.gstin ?? '—'}
                        />
                        <DetailItem
                            label="Owner"
                            value={company.owner?.email ?? '—'}
                        />
                        {company.deactivated_at ? (
                            <DetailItem
                                label="Deactivated at"
                                value={company.deactivated_at}
                            />
                        ) : null}
                    </dl>
                </FormSection>

                <Form
                    action={
                        company.is_active ? urls.deactivate : urls.reactivate
                    }
                    method="post"
                >
                    <FormSection
                        title={
                            company.is_active
                                ? 'Deactivate company'
                                : 'Reactivate company'
                        }
                        description="A remark is required. Soft-delete and restore of companies are not permitted."
                        columns={2}
                    >
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="lifecycle-remark">Remark</Label>
                            <textarea
                                id="lifecycle-remark"
                                name="remark"
                                required
                                rows={3}
                                className={textareaClass}
                                placeholder="Reason for lifecycle change"
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm md:col-span-2">
                            <input
                                type="checkbox"
                                name="confirm"
                                value="1"
                                required
                                className="size-4 rounded border border-input"
                            />
                            I confirm this lifecycle change
                        </label>
                        <div className="md:col-span-2">
                            <Button
                                type="submit"
                                variant={
                                    company.is_active
                                        ? 'destructive'
                                        : 'default'
                                }
                            >
                                {company.is_active
                                    ? 'Deactivate company'
                                    : 'Reactivate company'}
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                {enquiries_entitled && urls.enquiry_webhook_store ? (
                    <Form action={urls.enquiry_webhook_store} method="post">
                        <FormSection
                            title="Website enquiry webhook tokens"
                            description="Generate company-scoped bearer tokens. Plain token is shown once; only the prefix is stored on screen afterwards."
                            columns={2}
                        >
                            {plainToken ? (
                                <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 md:col-span-2">
                                    <p className="font-medium">
                                        Copy token now
                                    </p>
                                    <code className="mt-1 block font-mono text-xs break-all">
                                        {plainToken}
                                    </code>
                                </div>
                            ) : null}
                            <div className="space-y-2">
                                <Label htmlFor="webhook-label">Label</Label>
                                <Input
                                    id="webhook-label"
                                    name="label"
                                    placeholder="Production website"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="receiving_branch_id">
                                    Receiving branch
                                </Label>
                                <select
                                    id="receiving_branch_id"
                                    name="receiving_branch_id"
                                    className={selectClass}
                                    defaultValue=""
                                >
                                    <option value="">
                                        Unassigned company queue
                                    </option>
                                    {branches.map((branch) => (
                                        <option
                                            key={branch.id}
                                            value={branch.id}
                                        >
                                            {branch.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-2">
                                <Button type="submit">Generate token</Button>
                            </div>
                            {enquiry_webhook_credentials.length > 0 ? (
                                <ul className="space-y-2 text-sm md:col-span-2">
                                    {enquiry_webhook_credentials.map((row) => (
                                        <li
                                            key={row.id}
                                            className="rounded-md border border-border bg-muted/20 px-3 py-2"
                                        >
                                            <span className="font-mono text-xs">
                                                {row.token_prefix}…
                                            </span>
                                            {row.label ? ` · ${row.label}` : ''}
                                            <div className="text-xs text-muted-foreground">
                                                {row.is_usable
                                                    ? 'Active'
                                                    : 'Revoked/expired'}{' '}
                                                · last used{' '}
                                                {row.last_used_at ?? '—'}
                                            </div>
                                            {row.is_usable ? (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <Form
                                                        action={row.rotate_url}
                                                        method="post"
                                                    >
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            Rotate
                                                        </Button>
                                                    </Form>
                                                    <Form
                                                        action={row.revoke_url}
                                                        method="post"
                                                    >
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="destructive"
                                                        >
                                                            Revoke
                                                        </Button>
                                                    </Form>
                                                </div>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-muted-foreground md:col-span-2">
                                    No webhook credentials yet.
                                </p>
                            )}
                        </FormSection>
                    </Form>
                ) : null}

                <Form action={urls.transfer} method="post">
                    <FormSection
                        title="Ownership transfer"
                        description="Transfers ownership to an existing active Customer. Memberships and subscriptions are preserved."
                        columns={2}
                    >
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="target_user_id">New owner</Label>
                            <select
                                id="target_user_id"
                                name="target_user_id"
                                required
                                className={selectClass}
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
                        </div>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="transfer-remark">Remark</Label>
                            <textarea
                                id="transfer-remark"
                                name="remark"
                                required
                                rows={3}
                                className={textareaClass}
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm md:col-span-2">
                            <input
                                type="checkbox"
                                name="confirm"
                                value="1"
                                required
                                className="size-4 rounded border border-input"
                            />
                            I confirm ownership transfer
                        </label>
                        <div className="md:col-span-2">
                            <Button type="submit" variant="warning">
                                Transfer ownership
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                <FormSection title="Transfer history" columns={1}>
                    {transfers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No ownership transfers recorded.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {transfers.map((transfer) => (
                                <li
                                    key={transfer.id}
                                    className="rounded-md border border-border bg-muted/20 px-3 py-2"
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
                </FormSection>

                <FormSection title="Recent company audit" columns={1}>
                    {audit_entries.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No company audit entries.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {audit_entries.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="rounded-md border border-border bg-muted/20 px-3 py-2"
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
                </FormSection>
            </PageShell>
        </>
    );
}
