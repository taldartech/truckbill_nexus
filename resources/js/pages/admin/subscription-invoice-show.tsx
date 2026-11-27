import { Form, Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDateSmart } from '@/lib/dates';

type ActiveProfileOption = {
    id: string;
    legal_name: string;
    pan: string | null;
    gstin: string | null;
    gst_rate_percent: string;
    registered_address: string;
    city: string;
    state_name: string;
    state_code: string | null;
    pin_code: string;
    contact_number: string;
    email: string;
    bank_name: string | null;
    bank_account_name: string | null;
    bank_account_number: string | null;
    bank_ifsc: string | null;
    website: string | null;
    notes_terms: string | null;
    compatible: boolean;
    compatibility_message: string | null;
};

type Props = {
    document: {
        is_approved: boolean;
        document_title: string;
        banner: string | null;
        supplier: Record<string, string | null> | null;
        supplier_logo_data_uri?: string | null;
        customer: Record<string, string | null>;
        invoice: {
            id: string;
            invoice_number: string | null;
            document_type: string;
            status: string;
            financial_year: string;
            invoice_date: string | null;
            sac_code: string | null;
            service_period_start: string | null;
            service_period_end: string | null;
            issued_at: string | null;
            approved_at: string | null;
            approved_by_name: string | null;
            payment_date: string | null;
            statutory_deadline: string | null;
            credit_note_reason: string | null;
        };
        line_items: {
            description?: string;
            name?: string;
            quantity?: number;
            amount?: string;
        }[];
        payment_reference: string | null;
        totals: {
            subtotal_amount: string;
            discount_amount: string;
            taxable_amount: string;
            gst_rate_percent: string;
            cgst_amount: string;
            sgst_amount: string;
            igst_amount: string;
            total_tax_amount: string;
            amount: string;
            currency: string;
        };
    };
    canManage: boolean;
    activeProfiles: ActiveProfileOption[];
    approvalDefaults: {
        invoice_date: string;
        min_date: string;
        max_date: string;
        payment_date: string;
        statutory_deadline: string;
        window_exceeded: boolean;
    };
    links: {
        index: string;
        approve: string | null;
        download: string | null;
        credit_note: string | null;
    };
};

export default function AdminSubscriptionInvoiceShow({
    document,
    canManage,
    activeProfiles,
    approvalDefaults,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;
    const inv = document.invoice;
    const totals = document.totals;

    const firstCompatible = useMemo(
        () =>
            activeProfiles.find((p) => p.compatible)?.id ??
            activeProfiles[0]?.id ??
            '',
        [activeProfiles],
    );

    const approvalForm = useForm({
        billing_profile_id: firstCompatible,
        invoice_number: '',
        invoice_date: approvalDefaults.invoice_date,
    });

    const [confirmOpen, setConfirmOpen] = useState(false);

    const selectedProfile = useMemo(
        () =>
            activeProfiles.find(
                (p) => p.id === approvalForm.data.billing_profile_id,
            ) ?? null,
        [activeProfiles, approvalForm.data.billing_profile_id],
    );

    const handleReviewSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedProfile || !selectedProfile.compatible) {
            return;
        }
        setConfirmOpen(true);
    };

    const confirmApprove = () => {
        if (!links.approve) {
            return;
        }
        approvalForm.post(links.approve, {
            onSuccess: () => setConfirmOpen(false),
            onError: () => setConfirmOpen(false),
        });
    };

    return (
        <>
            <Head
                title={
                    inv.invoice_number
                        ? `${inv.invoice_number} · Admin`
                        : 'Pending Invoice Approval · Admin'
                }
            />
            <PageShell
                title={
                    inv.invoice_number
                        ? `${document.document_title} · ${inv.invoice_number}`
                        : 'Subscription Invoice · Pending Approval'
                }
                description={
                    document.is_approved
                        ? `FY ${inv.financial_year} · Invoice Date ${formatDisplayDateSmart(inv.invoice_date ?? inv.issued_at)} · Approved by ${inv.approved_by_name || 'Internal Team'}`
                        : `Payment Date ${formatDisplayDateSmart(approvalDefaults.payment_date)} · Statutory Issuance Deadline ${formatDisplayDateSmart(approvalDefaults.statutory_deadline)}`
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <WorkspaceStatusBadge
                            status={
                                document.is_approved
                                    ? inv.status
                                    : 'pending_approval'
                            }
                        />
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.index}>Back to Register</Link>
                        </Button>
                        {links.download ? (
                            <Button asChild size="sm">
                                <a
                                    href={links.download}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Download / Print
                                </a>
                            </Button>
                        ) : null}
                    </div>
                }
            >
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={[
                        'approval',
                        'billing_profile_id',
                        'invoice_number',
                        'invoice_date',
                        'credit_note',
                    ]}
                />

                {approvalDefaults.window_exceeded && !document.is_approved ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    >
                        Statutory GST issuance window (30 days from payment
                        date) has elapsed. Issuing past the deadline is blocked
                        to prevent non-compliant backdating.
                    </div>
                ) : null}

                {canManage && links.approve && !document.is_approved ? (
                    <form
                        onSubmit={handleReviewSubmit}
                        className="w-full space-y-5 rounded-xl border border-border bg-card p-6 shadow-xs"
                    >
                        <div>
                            <h2 className="text-base font-semibold">
                                Approve &amp; Issue Subscription Invoice
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Select an active Billing Profile, enter the
                                manual Invoice Number, and verify the Invoice
                                Date. Payment amount and activated entitlements
                                remain unchanged.
                            </p>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="billing_profile_id" required>
                                    Billing Profile
                                </Label>
                                <select
                                    id="billing_profile_id"
                                    required
                                    value={approvalForm.data.billing_profile_id}
                                    onChange={(e) =>
                                        approvalForm.setData(
                                            'billing_profile_id',
                                            e.target.value,
                                        )
                                    }
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">
                                        Select Billing Profile
                                    </option>
                                    {activeProfiles.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.legal_name} (
                                            {p.gstin || 'Unregistered'} ·{' '}
                                            {Number(p.gst_rate_percent)}%)
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="invoice_number" required>
                                    Invoice Number
                                </Label>
                                <Input
                                    id="invoice_number"
                                    required
                                    maxLength={64}
                                    className="font-mono"
                                    placeholder="TB/2026-27/0001"
                                    value={approvalForm.data.invoice_number}
                                    onChange={(e) =>
                                        approvalForm.setData(
                                            'invoice_number',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="invoice_date" required>
                                    Invoice Date
                                </Label>
                                <Input
                                    id="invoice_date"
                                    type="date"
                                    required
                                    min={approvalDefaults.min_date}
                                    max={approvalDefaults.max_date}
                                    value={approvalForm.data.invoice_date}
                                    onChange={(e) =>
                                        approvalForm.setData(
                                            'invoice_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Valid window:{' '}
                                    {formatDisplayDateSmart(
                                        approvalDefaults.min_date,
                                    )}{' '}
                                    to{' '}
                                    {formatDisplayDateSmart(
                                        approvalDefaults.max_date,
                                    )}
                                </p>
                            </div>
                        </div>

                        {selectedProfile ? (
                            <div
                                className={
                                    selectedProfile.compatible
                                        ? 'rounded-lg border border-border bg-muted/25 p-4 text-xs'
                                        : 'rounded-lg border border-destructive/40 bg-destructive/10 p-4 text-xs'
                                }
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 pb-2">
                                    <span className="font-semibold text-muted-foreground uppercase">
                                        Selected Supplier Billing Information
                                    </span>
                                    <WorkspaceStatusBadge
                                        status={
                                            selectedProfile.compatible
                                                ? 'approved'
                                                : 'rejected'
                                        }
                                    />
                                </div>
                                <p className="text-sm font-semibold">
                                    {selectedProfile.legal_name}
                                </p>
                                <p>
                                    {selectedProfile.registered_address},{' '}
                                    {selectedProfile.city},{' '}
                                    {selectedProfile.state_name} -{' '}
                                    {selectedProfile.pin_code}
                                </p>
                                <p className="font-mono">
                                    GSTIN:{' '}
                                    {selectedProfile.gstin || 'Unregistered'} ·
                                    PAN: {selectedProfile.pan || '—'} · GST
                                    Rate:{' '}
                                    {Number(selectedProfile.gst_rate_percent)}%
                                </p>
                                {!selectedProfile.compatible &&
                                selectedProfile.compatibility_message ? (
                                    <p
                                        role="alert"
                                        className="mt-2 font-medium text-destructive"
                                    >
                                        {selectedProfile.compatibility_message}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={
                                    approvalForm.processing ||
                                    !selectedProfile ||
                                    !selectedProfile.compatible ||
                                    approvalForm.data.invoice_number.trim() ===
                                        '' ||
                                    approvalDefaults.window_exceeded
                                }
                            >
                                Review &amp; Approve Invoice
                            </Button>
                        </div>
                    </form>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-border bg-card p-4 text-xs">
                        <p className="mb-1 font-semibold text-muted-foreground uppercase">
                            Supplier Snapshot
                        </p>
                        {document.supplier ? (
                            <>
                                {document.supplier_logo_data_uri ? (
                                    <img
                                        src={document.supplier_logo_data_uri}
                                        alt="Supplier Logo"
                                        className="mb-2 max-h-12 max-w-[160px] object-contain"
                                    />
                                ) : null}
                                <p className="text-sm font-semibold">
                                    {document.supplier.legal_name}
                                </p>
                                <p>{document.supplier.registered_address}</p>
                                <p>
                                    {[
                                        document.supplier.city,
                                        document.supplier.state_name,
                                        document.supplier.pin_code,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>
                                <p className="font-mono">
                                    GSTIN:{' '}
                                    {document.supplier.gstin || 'Unregistered'}{' '}
                                    · PAN: {document.supplier.pan || '—'}
                                </p>
                                {document.supplier.bank_name ? (
                                    <p className="mt-1 text-muted-foreground">
                                        Bank: {document.supplier.bank_name} ·
                                        A/c:{' '}
                                        {document.supplier.bank_account_number}{' '}
                                        · IFSC: {document.supplier.bank_ifsc}
                                    </p>
                                ) : null}
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                Supplier Billing Profile will lock upon invoice
                                approval.
                            </p>
                        )}
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4 text-xs">
                        <p className="mb-1 font-semibold text-muted-foreground uppercase">
                            Customer Company Billing Information
                        </p>
                        <p className="text-sm font-semibold">
                            {document.customer.legal_name}
                        </p>
                        <p>
                            {[
                                document.customer.registered_address,
                                document.customer.city,
                                document.customer.state,
                                document.customer.pin_code,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </p>
                        <p className="font-mono">
                            GSTIN: {document.customer.gstin || 'Unregistered'}
                        </p>
                        <p className="text-muted-foreground">
                            Service Period:{' '}
                            {inv.service_period_start && inv.service_period_end
                                ? `${formatDisplayDateSmart(inv.service_period_start)} to ${formatDisplayDateSmart(inv.service_period_end)}`
                                : '—'}
                        </p>
                        <p className="text-muted-foreground">
                            Payment Ref: {document.payment_reference || '—'} ·
                            Payment Date:{' '}
                            {formatDisplayDateSmart(inv.payment_date)}
                        </p>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-border bg-card">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-border bg-muted/30">
                            <tr>
                                <th className="px-3 py-2">Description</th>
                                <th className="px-3 py-2">SAC</th>
                                <th className="px-3 py-2 text-right">Qty</th>
                                <th className="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {document.line_items.map((item, idx) => (
                                <tr
                                    key={idx}
                                    className="border-b border-border last:border-0"
                                >
                                    <td className="px-3 py-2">
                                        {item.description || item.name}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {inv.sac_code || '998314'}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {item.quantity ?? 1}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {totals.currency} {item.amount}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/15 text-xs">
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right"
                                >
                                    Discounts
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    - {totals.currency} {totals.discount_amount}
                                </td>
                            </tr>
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right"
                                >
                                    Taxable Amount
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    {totals.currency} {totals.taxable_amount}
                                </td>
                            </tr>
                            <tr>
                                <td
                                    colSpan={3}
                                    className="px-3 py-1.5 text-right"
                                >
                                    GST ({totals.gst_rate_percent}%)
                                </td>
                                <td className="px-3 py-1.5 text-right">
                                    {Number(totals.igst_amount) > 0
                                        ? `IGST ${totals.currency} ${totals.igst_amount}`
                                        : `CGST ${totals.currency} ${totals.cgst_amount} + SGST ${totals.currency} ${totals.sgst_amount}`}
                                </td>
                            </tr>
                            <tr className="border-t border-border text-sm font-bold">
                                <td
                                    colSpan={3}
                                    className="px-3 py-2 text-right"
                                >
                                    Grand Total
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {totals.currency} {totals.amount}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {canManage && links.credit_note ? (
                    <Form
                        action={links.credit_note}
                        method="post"
                        className="space-y-3 rounded-xl border border-border bg-card p-4"
                    >
                        <h3 className="text-sm font-semibold">
                            Issue Credit Note (Controlled Financial Correction)
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Approved tax invoices remain immutable. Issue a
                            Credit Note for refunds or tax/amount corrections.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1 sm:col-span-2">
                                <Label htmlFor="cn_reason" required>
                                    Reason
                                </Label>
                                <Input
                                    id="cn_reason"
                                    name="reason"
                                    required
                                    placeholder="Refund or billing adjustment reason"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="cn_amount">
                                    Credit Amount (optional full if blank)
                                </Label>
                                <Input
                                    id="cn_amount"
                                    name="amount"
                                    type="number"
                                    step="0.01"
                                    placeholder={totals.amount}
                                />
                            </div>
                        </div>
                        <Button type="submit" variant="destructive" size="sm">
                            Issue Credit Note
                        </Button>
                    </Form>
                ) : null}

                <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Confirm Subscription Invoice Approval
                            </DialogTitle>
                            <DialogDescription>
                                Approving locks the Billing Profile, Invoice
                                Number, Invoice Date, and Customer snapshot
                                permanently and makes the invoice available to
                                the Customer.
                            </DialogDescription>
                        </DialogHeader>
                        <dl className="grid gap-2 rounded-lg border border-border bg-muted/20 p-3 text-xs sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">
                                    Billing Profile
                                </dt>
                                <dd className="font-semibold">
                                    {selectedProfile?.legal_name || '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Supplier GSTIN
                                </dt>
                                <dd className="font-mono">
                                    {selectedProfile?.gstin || 'Unregistered'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Invoice Number
                                </dt>
                                <dd className="font-mono font-semibold">
                                    {approvalForm.data.invoice_number}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Invoice Date
                                </dt>
                                <dd>
                                    {formatDisplayDateSmart(
                                        approvalForm.data.invoice_date,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Customer
                                </dt>
                                <dd>{document.customer.legal_name}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Total Amount
                                </dt>
                                <dd className="font-semibold">
                                    {totals.currency} {totals.amount}
                                </dd>
                            </div>
                        </dl>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConfirmOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={approvalForm.processing}
                                onClick={confirmApprove}
                            >
                                {approvalForm.processing
                                    ? 'Approving…'
                                    : 'Confirm & Approve Invoice'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </>
    );
}
