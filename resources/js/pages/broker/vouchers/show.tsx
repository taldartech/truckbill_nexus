import { Head, Link, useForm } from '@inertiajs/react';
import { AppIconAction } from '@/components/app-icon-action';
import { ArrowLeft, Eye, FileText } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';

type Props = {
    voucher: {
        id: string;
        voucher_number: string;
        status: string;
        payment_mode: string | null;
        payment_type: string;
        amount: string | null;
        voucher_date: string | null;
        bank_cash_account_name: string | null;
        cash_reference: string | null;
        pump_reference: string | null;
        remark: string | null;
        payee_name: string | null;
        created_at: string | null;
        lorry_hire: {
            id: string;
            hire_number: string;
            lr_number: string | null;
            transporter_name: string | null;
            vehicle_registration: string | null;
            freight_total: string | null;
            tds_amount: string | null;
            tds_percent: string | null;
            net_payable: string | null;
            outstanding_amount: string | null;
            paid_amount: string | null;
            adjustments: Array<{
                type: string;
                amount: string | null;
                reason: string | null;
            }>;
            voucher_history: Array<{
                id: string;
                voucher_number: string;
                payment_type: string;
                amount: string | null;
                status: string;
                is_current: boolean;
            }>;
        } | null;
    };
    permissions: { can_status: boolean };
    nextStatus: string | null;
    urls: {
        index: string;
        lorry_hire_show: string;
        status: string;
        lr_show?: string | null;
    };
};

export default function VoucherShow({
    voucher,
    permissions,
    nextStatus,
    urls,
}: Props) {
    const statusForm = useForm({ status: nextStatus ?? '' });
    const hire = voucher.lorry_hire;

    return (
        <>
            <Head title={`Voucher ${voucher.voucher_number}`} />
            <PageShell
                title={`Voucher ${voucher.voucher_number}`}
                actions={
                    <Link
                        href={urls.index}
                        className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        All vouchers
                    </Link>
                }
            >
                <div className="flex flex-wrap items-center gap-3">
                    <WorkspaceStatusBadge status={voucher.status} />
                    {hire ? (
                        <AppIconAction
                            icon={Eye}
                            label={`Open hire ${hire.hire_number}`}
                            href={urls.lorry_hire_show}
                            variant="info"
                            size="sm"
                        />
                    ) : null}
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Voucher amount"
                        value={voucher.amount ?? '—'}
                    />
                    <MetricCard
                        label="Payment type"
                        value={voucher.payment_type}
                    />
                    <MetricCard
                        label="Payment mode"
                        value={voucher.payment_mode ?? '—'}
                    />
                    <MetricCard
                        label="Account / reference"
                        value={
                            voucher.bank_cash_account_name ??
                            voucher.cash_reference ??
                            voucher.pump_reference ??
                            '—'
                        }
                    />
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    <section className="rounded-lg border border-border p-4">
                        <h2 className="text-sm font-semibold">
                            Payment details
                        </h2>
                        <dl className="mt-3 space-y-2 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt className="text-muted-foreground">Date</dt>
                                <dd>
                                    {voucher.voucher_date ??
                                        voucher.created_at ??
                                        '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-muted-foreground">Payee</dt>
                                <dd>{voucher.payee_name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-muted-foreground">
                                    Bank/Cash account
                                </dt>
                                <dd>{voucher.bank_cash_account_name ?? '—'}</dd>
                            </div>
                            {voucher.remark ? (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Remark
                                    </dt>
                                    <dd className="mt-1">{voucher.remark}</dd>
                                </div>
                            ) : null}
                        </dl>
                    </section>

                    {hire ? (
                        <section className="rounded-lg border border-border p-4">
                            <h2 className="text-sm font-semibold">
                                Lorry Hire context
                            </h2>
                            <p className="mt-2 text-sm font-medium">
                                {hire.hire_number}
                                {hire.lr_number
                                    ? ` · LR ${hire.lr_number}`
                                    : ''}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {hire.transporter_name ?? '—'} ·{' '}
                                {hire.vehicle_registration ?? '—'}
                            </p>
                            <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Transporter freight
                                    </dt>
                                    <dd>{hire.freight_total ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        TDS
                                        {hire.tds_percent
                                            ? ` (${hire.tds_percent}%)`
                                            : ''}
                                    </dt>
                                    <dd>{hire.tds_amount ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Net payable
                                    </dt>
                                    <dd>{hire.net_payable ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Paid / Outstanding
                                    </dt>
                                    <dd>
                                        {hire.paid_amount ?? '—'} /{' '}
                                        {hire.outstanding_amount ?? '—'}
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    ) : null}
                </div>

                {hire && hire.adjustments.length > 0 ? (
                    <section className="mt-6 rounded-lg border border-border p-4">
                        <h2 className="text-sm font-semibold">Adjustments</h2>
                        <ul className="mt-2 space-y-2 text-sm">
                            {hire.adjustments.map((row, index) => (
                                <li
                                    key={`${row.type}-${index}`}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    {row.type.replaceAll('_', ' ')} ·{' '}
                                    {row.amount ?? '—'}
                                    {row.reason ? ` — ${row.reason}` : ''}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {hire && hire.voucher_history.length > 0 ? (
                    <section className="mt-6 rounded-lg border border-border p-4">
                        <h2 className="text-sm font-semibold">
                            Voucher history on this hire
                        </h2>
                        <ul className="mt-2 space-y-2 text-sm">
                            {hire.voucher_history.map((row) => (
                                <li
                                    key={row.id}
                                    className={`flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 ${
                                        row.is_current
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-border'
                                    }`}
                                >
                                    <span>
                                        {row.voucher_number} ·{' '}
                                        {row.payment_type} · {row.amount ?? '—'}
                                    </span>
                                    <WorkspaceStatusBadge status={row.status} />
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {permissions.can_status && nextStatus ? (
                    <form
                        className="mt-6 flex flex-wrap items-center gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            statusForm.post(urls.status);
                        }}
                    >
                        <input type="hidden" name="status" value={nextStatus} />
                        <Button
                            type="submit"
                            disabled={statusForm.processing}
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Mark as {nextStatus.replaceAll('_', ' ')}
                        </Button>
                        <AppIconAction
                            icon={FileText}
                            label="Back to hire"
                            href={urls.lorry_hire_show}
                            variant="outline"
                            size="sm"
                        />
                    </form>
                ) : null}
            </PageShell>
        </>
    );
}
