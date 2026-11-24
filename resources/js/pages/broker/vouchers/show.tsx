import { Head, Link, useForm } from '@inertiajs/react';
import { AppIconAction } from '@/components/app-icon-action';
import { ArrowLeft, Eye } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
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
        cash_reference: string | null;
        pump_reference: string | null;
        remark: string | null;
        created_at: string | null;
        lorry_hire: {
            id: string;
            hire_number: string;
            lr_number: string | null;
            transporter_name: string | null;
            vehicle_registration: string | null;
            outstanding_amount: string | null;
            paid_amount: string | null;
        } | null;
    };
    permissions: { can_status: boolean };
    nextStatus: string | null;
    urls: {
        index: string;
        lorry_hire_show: string;
        status: string;
    };
};

export default function VoucherShow({
    voucher,
    permissions,
    nextStatus,
    urls,
}: Props) {
    const statusForm = useForm({ status: nextStatus ?? '' });

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
                <WorkspaceStatusBadge status={voucher.status} />

                <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Amount
                        </dt>
                        <dd className="text-lg font-semibold">
                            {voucher.amount ?? '—'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Mode / type
                        </dt>
                        <dd className="text-sm">
                            {voucher.payment_mode} · {voucher.payment_type}
                        </dd>
                    </div>
                    {voucher.remark && (
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-muted-foreground">
                                Remark
                            </dt>
                            <dd className="text-sm">{voucher.remark}</dd>
                        </div>
                    )}
                </dl>

                {voucher.lorry_hire && (
                    <div className="mt-8 rounded-lg border border-border p-4">
                        <h2 className="text-sm font-semibold">Lorry Hire</h2>
                        <p className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <AppIconAction
                                icon={Eye}
                                label={`Open lorry hire ${voucher.lorry_hire.hire_number}`}
                                href={urls.lorry_hire_show}
                                variant="info"
                                size="sm"
                            />
                            <span className="font-medium">
                                {voucher.lorry_hire.hire_number}
                            </span>
                            {voucher.lorry_hire.lr_number
                                ? ` · LR ${voucher.lorry_hire.lr_number}`
                                : ''}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {voucher.lorry_hire.transporter_name} ·{' '}
                            {voucher.lorry_hire.vehicle_registration}
                        </p>
                        <p className="mt-2 text-sm">
                            Paid {voucher.lorry_hire.paid_amount} · Outstanding{' '}
                            {voucher.lorry_hire.outstanding_amount}
                        </p>
                    </div>
                )}

                {permissions.can_status && nextStatus && (
                    <form
                        className="mt-8"
                        onSubmit={(event) => {
                            event.preventDefault();
                            statusForm.post(urls.status);
                        }}
                    >
                        <Button
                            type="submit"
                            disabled={statusForm.processing}
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Mark {nextStatus.replace('_', ' ')}
                        </Button>
                        {statusForm.errors.status && (
                            <p className="mt-2 text-sm text-destructive">
                                {statusForm.errors.status}
                            </p>
                        )}
                    </form>
                )}
            </PageShell>
        </>
    );
}
