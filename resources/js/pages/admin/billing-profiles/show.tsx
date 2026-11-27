import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil, Power, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
import { formatDisplayDateSmart } from '@/lib/dates';

type Props = {
    profile: {
        id: string;
        legal_name: string;
        pan: string;
        logo_url: string | null;
        contact_number: string;
        email: string;
        registered_address: string;
        city: string;
        state_name: string;
        state_code: string;
        pin_code: string;
        gstin: string;
        bank_name: string;
        bank_account_name: string;
        bank_account_number: string;
        bank_ifsc: string;
        website: string;
        notes_terms: string;
        is_active: boolean;
        created_at: string | null;
        updated_at: string | null;
        updated_by: string | null;
    };
    audits: {
        id: string;
        action: string;
        remark: string | null;
        created_at: string | null;
    }[];
    canManage: boolean;
    links: {
        index: string;
        edit: string | null;
        deactivate: string | null;
        reactivate: string | null;
        invoices: string;
    };
};

export default function BillingProfileShow({
    profile,
    audits,
    canManage,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [lifecycleOpen, setLifecycleOpen] = useState(false);
    const [remark, setRemark] = useState('');
    const [processing, setProcessing] = useState(false);

    const lifecycleUrl = profile.is_active
        ? links.deactivate
        : links.reactivate;

    const handleLifecycleSubmit = () => {
        if (!lifecycleUrl || remark.trim() === '') {
            return;
        }
        setProcessing(true);
        router.post(
            lifecycleUrl,
            { remark: remark.trim() },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setLifecycleOpen(false);
                    setRemark('');
                },
            },
        );
    };

    const fields: [string, string][] = [
        ['Company Name', profile.legal_name],
        ['PAN', profile.pan || '—'],
        ['Contact Number', profile.contact_number],
        ['Email', profile.email],
        ['Registered Address', profile.registered_address],
        ['City', profile.city],
        [
            'State',
            `${profile.state_name}${profile.state_code ? ` (${profile.state_code})` : ''}`,
        ],
        ['PIN Code', profile.pin_code],
        ['GSTIN', profile.gstin || 'Unregistered'],
        ['Bank Name', profile.bank_name || '—'],
        ['Account Holder Name', profile.bank_account_name || '—'],
        ['Account Number', profile.bank_account_number || '—'],
        ['IFSC Code', profile.bank_ifsc || '—'],
        ['Website', profile.website || '—'],
        ['Notes / Terms', profile.notes_terms || '—'],
    ];

    return (
        <>
            <Head title={`${profile.legal_name} · Billing Profile`} />
            <PageShell
                title={profile.legal_name}
                description="Company Billing Profile details and immutable audit trail."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge
                            status={profile.is_active ? 'active' : 'inactive'}
                        />
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.index}>All Profiles</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.invoices}>Linked Invoices</Link>
                        </Button>
                        {canManage && links.edit ? (
                            <Button asChild size="sm">
                                <Link href={links.edit}>
                                    <Pencil className="size-4" />
                                    Edit
                                </Link>
                            </Button>
                        ) : null}
                        {canManage && lifecycleUrl ? (
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    profile.is_active
                                        ? 'destructive'
                                        : 'secondary'
                                }
                                onClick={() => setLifecycleOpen(true)}
                            >
                                {profile.is_active ? (
                                    <>
                                        <Power className="size-4" />
                                        Deactivate
                                    </>
                                ) : (
                                    <>
                                        <RotateCcw className="size-4" />
                                        Reactivate
                                    </>
                                )}
                            </Button>
                        ) : null}
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-4 rounded-xl border border-border bg-card p-6 lg:col-span-2">
                        {profile.logo_url ? (
                            <div className="border-b border-border pb-4">
                                <p className="mb-2 text-xs font-medium text-muted-foreground">
                                    Company Logo
                                </p>
                                <img
                                    src={profile.logo_url}
                                    alt={`${profile.legal_name} logo`}
                                    className="max-h-16 max-w-[220px] rounded border border-border bg-background object-contain p-1"
                                />
                            </div>
                        ) : null}
                        <dl className="grid gap-4 sm:grid-cols-2">
                            {fields.map(([label, value]) => (
                                <div
                                    key={label}
                                    className={
                                        label === 'Registered Address' ||
                                        label === 'Notes / Terms'
                                            ? 'sm:col-span-2'
                                            : undefined
                                    }
                                >
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="mt-0.5 text-sm whitespace-pre-wrap">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>

                    <div className="space-y-4 rounded-xl border border-border bg-card p-6">
                        <h3 className="text-sm font-semibold">
                            Audit & Metadata
                        </h3>
                        <dl className="space-y-2 text-xs">
                            <div>
                                <dt className="text-muted-foreground">
                                    Last Updated
                                </dt>
                                <dd>
                                    {formatDisplayDateSmart(profile.updated_at)}
                                    {profile.updated_by
                                        ? ` · ${profile.updated_by}`
                                        : ''}
                                </dd>
                            </div>
                        </dl>
                        <div className="border-t border-border pt-3">
                            <p className="mb-2 text-xs font-medium text-muted-foreground">
                                Recent Changes
                            </p>
                            {audits.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    No audit entries yet.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-xs">
                                    {audits.map((log) => (
                                        <li
                                            key={log.id}
                                            className="rounded border border-border/70 p-2"
                                        >
                                            <div className="flex items-center justify-between font-medium capitalize">
                                                <span>{log.action}</span>
                                                <span className="text-muted-foreground">
                                                    {formatDisplayDateSmart(
                                                        log.created_at,
                                                    )}
                                                </span>
                                            </div>
                                            {log.remark ? (
                                                <p className="mt-1 text-muted-foreground">
                                                    {log.remark}
                                                </p>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>

                <Dialog open={lifecycleOpen} onOpenChange={setLifecycleOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {profile.is_active
                                    ? 'Deactivate Billing Profile'
                                    : 'Reactivate Billing Profile'}
                            </DialogTitle>
                            <DialogDescription>
                                {profile.is_active
                                    ? 'Deactivated profiles cannot be used to approve new invoices. Historical approved invoices remain unchanged.'
                                    : 'Reactivate this Billing Profile for future invoice approvals.'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2 py-2">
                            <Label htmlFor="show_lifecycle_remark" required>
                                Audit Remark
                            </Label>
                            <textarea
                                id="show_lifecycle_remark"
                                required
                                rows={3}
                                className={textareaClass}
                                value={remark}
                                onChange={(e) => setRemark(e.target.value)}
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setLifecycleOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    profile.is_active
                                        ? 'destructive'
                                        : 'default'
                                }
                                disabled={processing || remark.trim() === ''}
                                onClick={handleLifecycleSubmit}
                            >
                                {processing ? 'Saving…' : 'Confirm'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </>
    );
}
