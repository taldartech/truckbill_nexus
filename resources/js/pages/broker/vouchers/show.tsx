import { Head, useForm } from '@inertiajs/react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    FbCompactTable,
    FbDetailCard,
    FbDetailLayout,
    FbDetailPage,
    FbFinancialSummary,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import { ArrowLeft, Eye, FileText } from 'lucide-react';
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
    const outstandingNumber = Number(hire?.outstanding_amount ?? NaN);
    const balanceDue =
        Number.isFinite(outstandingNumber) && outstandingNumber > 0;
    const cleared =
        Number.isFinite(outstandingNumber) && outstandingNumber <= 0;

    return (
        <>
            <Head title={`Voucher ${voucher.voucher_number}`} />
            <FbDetailPage title={`Voucher ${voucher.voucher_number}`}>
                <FbDetailLayout
                    main={
                        <>
                            <FbDetailCard
                                title={`Voucher Payment (${voucher.voucher_number})`}
                                badges={
                                    <WorkspaceStatusBadge
                                        status={voucher.status}
                                    />
                                }
                                actions={
                                    <>
                                        <AppIconAction
                                            icon={ArrowLeft}
                                            label="Back to vouchers"
                                            href={urls.index}
                                            variant="outline"
                                            size="sm"
                                        />
                                        {hire ? (
                                            <AppIconAction
                                                icon={Eye}
                                                label={`Open hire ${hire.hire_number}`}
                                                href={urls.lorry_hire_show}
                                                variant="info"
                                                size="sm"
                                            />
                                        ) : null}
                                        {urls.lr_show ? (
                                            <AppIconAction
                                                icon={FileText}
                                                label="Open bilty"
                                                href={urls.lr_show}
                                                variant="secondary"
                                                size="sm"
                                            />
                                        ) : null}
                                    </>
                                }
                            >
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Amount"
                                        value={voucher.amount}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="Payment type"
                                        value={voucher.payment_type}
                                    />
                                    <FbInfoItem
                                        label="Payment mode"
                                        value={voucher.payment_mode}
                                    />
                                    <FbInfoItem
                                        label="Date"
                                        value={
                                            voucher.voucher_date ??
                                            voucher.created_at
                                        }
                                    />
                                    <FbInfoItem
                                        label="Payee"
                                        value={voucher.payee_name}
                                    />
                                    <FbInfoItem
                                        label="Account / reference"
                                        value={
                                            voucher.bank_cash_account_name ??
                                            voucher.cash_reference ??
                                            voucher.pump_reference
                                        }
                                    />
                                    {voucher.remark ? (
                                        <FbInfoItem
                                            label="Remark"
                                            value={voucher.remark}
                                            full
                                        />
                                    ) : null}
                                </FbInfoGrid>
                            </FbDetailCard>

                            {hire ? (
                                <FbDetailCard title="Lorry Hire context">
                                    <FbInfoGrid>
                                        <FbInfoItem
                                            label="Hire #"
                                            value={hire.hire_number}
                                            accent
                                        />
                                        <FbInfoItem
                                            label="LR"
                                            value={hire.lr_number}
                                        />
                                        <FbInfoItem
                                            label="Transporter"
                                            value={hire.transporter_name}
                                        />
                                        <FbInfoItem
                                            label="Truck"
                                            value={hire.vehicle_registration}
                                        />
                                        <FbInfoItem
                                            label="TR Freight"
                                            value={hire.freight_total}
                                        />
                                        <FbInfoItem
                                            label="TDS"
                                            value={
                                                hire.tds_percent
                                                    ? `${hire.tds_amount ?? '—'} (${hire.tds_percent}%)`
                                                    : hire.tds_amount
                                            }
                                        />
                                        <FbInfoItem
                                            label="Net payable"
                                            value={hire.net_payable}
                                        />
                                        <FbInfoItem
                                            label="Paid / Outstanding"
                                            value={`${hire.paid_amount ?? '—'} / ${hire.outstanding_amount ?? '—'}`}
                                        />
                                    </FbInfoGrid>
                                </FbDetailCard>
                            ) : null}

                            {hire && hire.adjustments.length > 0 ? (
                                <FbCompactTable
                                    title="Adjustments"
                                    count={hire.adjustments.length}
                                    headers={['Type', 'Amount', 'Reason']}
                                    empty="No adjustments."
                                >
                                    {hire.adjustments.map((row, index) => (
                                        <tr key={`${row.type}-${index}`}>
                                            <td className="px-3 py-2">
                                                {row.type.replaceAll('_', ' ')}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.reason ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </FbCompactTable>
                            ) : null}

                            {hire && hire.voucher_history.length > 0 ? (
                                <FbCompactTable
                                    title="Voucher history on this hire"
                                    count={hire.voucher_history.length}
                                    headers={[
                                        'Voucher',
                                        'Type',
                                        'Amount',
                                        'Status',
                                    ]}
                                    empty="No voucher history."
                                >
                                    {hire.voucher_history.map((row) => (
                                        <tr
                                            key={row.id}
                                            className={
                                                row.is_current
                                                    ? 'bg-emerald-50/60'
                                                    : undefined
                                            }
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {row.voucher_number}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.payment_type}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <WorkspaceStatusBadge
                                                    status={row.status}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </FbCompactTable>
                            ) : null}

                            {permissions.can_status && nextStatus ? (
                                <FbDetailCard title="Status action">
                                    <form
                                        className="flex flex-wrap items-center gap-3 px-4 py-3"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            statusForm.post(urls.status);
                                        }}
                                    >
                                        <input
                                            type="hidden"
                                            name="status"
                                            value={nextStatus}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={statusForm.processing}
                                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                        >
                                            Mark as{' '}
                                            {nextStatus.replaceAll('_', ' ')}
                                        </Button>
                                    </form>
                                </FbDetailCard>
                            ) : null}
                        </>
                    }
                    side={
                        <>
                            <FbFinancialSummary
                                balanceDue={balanceDue}
                                cleared={cleared}
                                trAmount={hire?.freight_total ?? '—'}
                                deductions={hire?.tds_amount ?? '—'}
                                balance={hire?.outstanding_amount ?? '—'}
                                rows={[
                                    {
                                        label: 'Voucher amount',
                                        value: voucher.amount ?? '—',
                                    },
                                    {
                                        label: 'TR Freight',
                                        value: hire?.freight_total ?? '—',
                                    },
                                    {
                                        label: 'TDS',
                                        value: hire?.tds_amount ?? '—',
                                    },
                                    {
                                        label: 'Net payable',
                                        value: hire?.net_payable ?? '—',
                                    },
                                    {
                                        label: 'Outstanding',
                                        value: hire?.outstanding_amount ?? '—',
                                    },
                                ]}
                            />
                            <FbDetailCard title="Record Info">
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Voucher #"
                                        value={voucher.voucher_number}
                                    />
                                    <FbInfoItem
                                        label="Status"
                                        value={voucher.status}
                                    />
                                    <FbInfoItem
                                        label="Created"
                                        value={voucher.created_at}
                                    />
                                </FbInfoGrid>
                            </FbDetailCard>
                        </>
                    }
                />
            </FbDetailPage>
        </>
    );
}
