import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus, Power, RotateCcw } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type BillingProfileRow = {
    id: string;
    legal_name: string;
    pan: string | null;
    contact_number: string;
    email: string;
    city: string;
    state_name: string;
    state_code: string | null;
    pin_code: string;
    gstin: string | null;
    bank_name: string | null;
    has_logo: boolean;
    logo_url: string | null;
    is_active: boolean;
    updated_at: string | null;
    show_url: string;
    edit_url: string | null;
    deactivate_url: string | null;
    reactivate_url: string | null;
};

type Props = {
    filters: { status: string; q: string };
    canManage: boolean;
    profiles: BillingProfileRow[];
    links: {
        index: string;
        create: string | null;
        invoices: string;
    };
};

export default function BillingProfilesIndex({
    filters,
    canManage,
    profiles,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const [search, setSearch] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [lifecycleTarget, setLifecycleTarget] = useState<{
        mode: 'deactivate' | 'reactivate';
        profile: BillingProfileRow;
    } | null>(null);
    const [lifecycleRemark, setLifecycleRemark] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const applyFilters = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            links.index,
            {
                q: search || undefined,
                status,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitLifecycle = () => {
        if (!lifecycleTarget) {
            return;
        }
        const url =
            lifecycleTarget.mode === 'deactivate'
                ? lifecycleTarget.profile.deactivate_url
                : lifecycleTarget.profile.reactivate_url;
        if (!url || lifecycleRemark.trim() === '') {
            return;
        }
        setSubmitting(true);
        router.post(
            url,
            { remark: lifecycleRemark.trim() },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                },
                onSuccess: () => {
                    setLifecycleTarget(null);
                    setLifecycleRemark('');
                },
            },
        );
    };

    const columns = useMemo<DataTableColumn<BillingProfileRow>[]>(
        () => [
            {
                id: 'company',
                header: 'Company Name',
                priority: 0,
                cell: (row) => (
                    <div className="flex items-center gap-2.5">
                        {row.logo_url ? (
                            <img
                                src={row.logo_url}
                                alt={`${row.legal_name} logo`}
                                className="size-8 rounded border border-border bg-background object-contain p-0.5"
                            />
                        ) : null}
                        <div>
                            <Link
                                href={row.show_url}
                                className="font-medium hover:underline"
                            >
                                {row.legal_name}
                            </Link>
                            <div className="text-xs text-muted-foreground">
                                {row.email} · {row.contact_number}
                            </div>
                        </div>
                    </div>
                ),
            },
            {
                id: 'gstin',
                header: 'GSTIN / PAN',
                priority: 1,
                cell: (row) => (
                    <div className="font-mono text-xs">
                        <div>{row.gstin || 'Unregistered'}</div>
                        <div className="text-muted-foreground">
                            PAN: {row.pan || '—'}
                        </div>
                    </div>
                ),
            },
            {
                id: 'location',
                header: 'City / State',
                priority: 2,
                cell: (row) => (
                    <div className="text-xs">
                        <div>
                            {row.city}, {row.state_name}
                        </div>
                        <div className="text-muted-foreground">
                            PIN {row.pin_code}
                        </div>
                    </div>
                ),
            },
            {
                id: 'bank',
                header: 'Bank',
                priority: 3,
                className: 'text-xs text-muted-foreground',
                cell: (row) => row.bank_name || '—',
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'inactive'}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Billing Profiles" />
            <PageShell
                title="Company Billing Profiles"
                description="Manage TruckBill supplier billing entities selected when approving Customer subscription invoices."
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.invoices}>
                                Subscription Invoices
                            </Link>
                        </Button>
                        {canManage && links.create ? (
                            <Button asChild size="sm">
                                <Link href={links.create}>
                                    <Plus className="size-4" />
                                    Add Billing Profile
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['billing_profile', 'remark']}
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 rounded-xl border border-border bg-card p-3 sm:grid-cols-3 sm:items-end"
                >
                    <div className="space-y-1">
                        <Label htmlFor="bp_search">Search</Label>
                        <Input
                            id="bp_search"
                            placeholder="Company name, GSTIN, PAN, city, email"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="bp_status">Status</Label>
                        <select
                            id="bp_status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Company billing profiles"
                        columns={columns}
                        rows={profiles}
                        rowKey={(row) => row.id}
                        emptyTitle="No billing profiles configured"
                        emptyDescription="Create one or more Billing Profiles to select from when approving subscription invoices."
                        actions={(row) => (
                            <div className="flex items-center justify-end gap-1">
                                <AppIconAction
                                    icon={Eye}
                                    label="View billing profile"
                                    href={row.show_url}
                                    variant="info"
                                />
                                {row.edit_url ? (
                                    <AppIconAction
                                        icon={Pencil}
                                        label="Edit billing profile"
                                        href={row.edit_url}
                                        variant="outline"
                                    />
                                ) : null}
                                {row.deactivate_url ? (
                                    <AppIconAction
                                        icon={Power}
                                        label="Deactivate billing profile"
                                        type="button"
                                        variant="destructive"
                                        onClick={() => {
                                            setLifecycleRemark('');
                                            setLifecycleTarget({
                                                mode: 'deactivate',
                                                profile: row,
                                            });
                                        }}
                                    />
                                ) : null}
                                {row.reactivate_url ? (
                                    <AppIconAction
                                        icon={RotateCcw}
                                        label="Reactivate billing profile"
                                        type="button"
                                        variant="secondary"
                                        onClick={() => {
                                            setLifecycleRemark('');
                                            setLifecycleTarget({
                                                mode: 'reactivate',
                                                profile: row,
                                            });
                                        }}
                                    />
                                ) : null}
                            </div>
                        )}
                    />
                </div>

                <Dialog
                    open={lifecycleTarget !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setLifecycleTarget(null);
                            setLifecycleRemark('');
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {lifecycleTarget?.mode === 'deactivate'
                                    ? 'Deactivate Billing Profile'
                                    : 'Reactivate Billing Profile'}
                            </DialogTitle>
                            <DialogDescription>
                                {lifecycleTarget?.mode === 'deactivate'
                                    ? `Deactivating "${lifecycleTarget.profile.legal_name}" prevents new invoice approvals under it while preserving all historical approved invoices.`
                                    : `Reactivating "${lifecycleTarget?.profile.legal_name}" allows selecting it for future invoice approvals.`}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2 py-2">
                            <Label htmlFor="lifecycle_remark" required>
                                Audit Remark
                            </Label>
                            <textarea
                                id="lifecycle_remark"
                                required
                                rows={3}
                                className={textareaClass}
                                placeholder="Reason for status change"
                                value={lifecycleRemark}
                                onChange={(e) =>
                                    setLifecycleRemark(e.target.value)
                                }
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setLifecycleTarget(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    lifecycleTarget?.mode === 'deactivate'
                                        ? 'destructive'
                                        : 'default'
                                }
                                disabled={
                                    submitting || lifecycleRemark.trim() === ''
                                }
                                onClick={submitLifecycle}
                            >
                                {submitting
                                    ? 'Saving…'
                                    : lifecycleTarget?.mode === 'deactivate'
                                      ? 'Confirm Deactivation'
                                      : 'Confirm Reactivation'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </>
    );
}
