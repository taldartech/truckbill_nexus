import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    FbCompactTable,
    FbDetailCard,
    FbDetailLayout,
    FbDetailPage,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type MemberRow = {
    member_id: string;
    lorry_receipt_id: string;
    lorry_hire_id: string | null;
    lr_number: string | null;
    hire_number: string | null;
    has_hire: boolean;
    allocatable_amount: string | null;
};

type PreviewLine = {
    lorry_hire_id: string;
    member_id: string;
    lr_number: string | null;
    hire_number: string | null;
    eligible_outstanding: string | null;
    allocated_amount: string | null;
};

type Preview = {
    payment_amount: string | null;
    total_eligible: string | null;
    lines: PreviewLine[];
};

type Props = {
    group: {
        id: string;
        group_number: string;
        group_date: string | null;
        status: string;
        driver_name: string | null;
        reference: string | null;
        remarks: string | null;
        vehicle_registration: string | null;
        payee_name: string | null;
        members: MemberRow[];
    };
    paymentTypeOptions: Array<{ value: string; label: string }>;
    paymentModeOptions: Array<{ value: string; label: string }>;
    bankCashAccounts: Array<{
        id: string;
        name: string;
        account_kind: string;
    }>;
    permissions: { can_pay: boolean };
    urls: { index: string; pay_preview: string; pay: string };
};

function getCsrfToken(): string {
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
    return token ?? '';
}

export default function LinkedBiltyShow({
    group,
    paymentTypeOptions,
    paymentModeOptions,
    bankCashAccounts,
    permissions,
    urls,
}: Props) {
    const flash = (usePage().props.flash as { success?: string }) ?? {};
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [paymentMode, setPaymentMode] = useState(
        paymentModeOptions[0]?.value ?? 'bank',
    );
    const paySubmitAllowed = useRef(false);
    const [payConfirmOpen, setPayConfirmOpen] = useState(false);
    const [payReviewFields, setPayReviewFields] = useState<FieldReview[]>([]);

    const runPreview = useCallback(
        async (form: HTMLFormElement) => {
            setPreviewing(true);
            setPreviewError(null);
            const data = new FormData(form);
            try {
                const response = await fetch(urls.pay_preview, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: data,
                });
                if (!response.ok) {
                    const payload = (await response.json()) as {
                        message?: string;
                        errors?: Record<string, string[]>;
                    };
                    const firstError = payload.errors
                        ? Object.values(payload.errors)[0]?.[0]
                        : payload.message;
                    setPreview(null);
                    setPreviewError(
                        firstError ?? 'Could not preview group payment.',
                    );
                    return;
                }
                const payload = (await response.json()) as Preview;
                setPreview(payload);
            } catch {
                setPreviewError('Could not preview group payment.');
                setPreview(null);
            } finally {
                setPreviewing(false);
            }
        },
        [urls.pay_preview],
    );

    const allMembersHaveHire = group.members.every((row) => row.has_hire);

    return (
        <>
            <Head title={`Linked Bilty ${group.group_number}`} />
            <FbDetailPage>
                {flash.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-md border border-[#2FB36F]/30 bg-[#2FB36F]/10 px-3 py-2 text-sm text-[#1f7a4d]"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FbDetailLayout
                    main={
                        <>
                            <FbDetailCard
                                title={`Linked Bilty Group (${group.group_number})`}
                                badges={
                                    <WorkspaceStatusBadge
                                        status={group.status}
                                    />
                                }
                                actions={
                                    <AppIconAction
                                        icon={ArrowLeft}
                                        label="All groups"
                                        href={urls.index}
                                        variant="outline"
                                        size="sm"
                                    />
                                }
                            >
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Date"
                                        value={group.group_date}
                                    />
                                    <FbInfoItem
                                        label="Vehicle"
                                        value={group.vehicle_registration}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="Payee"
                                        value={group.payee_name}
                                    />
                                    <FbInfoItem
                                        label="Driver"
                                        value={group.driver_name}
                                    />
                                    <FbInfoItem
                                        label="Reference"
                                        value={group.reference}
                                        span2
                                    />
                                </FbInfoGrid>
                            </FbDetailCard>

                            <FbCompactTable
                                title="Group members"
                                count={group.members.length}
                                headers={[
                                    'LR No',
                                    'Hire No',
                                    'Hire ready',
                                    'Allocatable',
                                ]}
                                empty="No members in this group."
                            >
                                {group.members.map((member) => (
                                    <tr key={member.member_id}>
                                        <td className="px-3 py-2">
                                            {member.lr_number ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {member.hire_number ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {member.has_hire ? 'Yes' : 'No'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {member.allocatable_amount ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </FbCompactTable>

                            {permissions.can_pay && allMembersHaveHire ? (
                                <FbDetailCard title="Group payment">
                                    <div className="space-y-4 px-4 py-3">
                                        <Form
                                            action={urls.pay}
                                            method="post"
                                            id="linked-bilty-pay-form"
                                            className="grid gap-4 md:grid-cols-2"
                                            onBefore={() => {
                                                if (!paySubmitAllowed.current) {
                                                    const data = new FormData(
                                                        document.getElementById(
                                                            'linked-bilty-pay-form',
                                                        ) as HTMLFormElement,
                                                    );
                                                    const amountValue =
                                                        data.get('amount');
                                                    const amount =
                                                        typeof amountValue ===
                                                        'string'
                                                            ? amountValue
                                                            : '—';
                                                    const accountId = data.get(
                                                        'bank_cash_account_id',
                                                    );
                                                    const accountLabel =
                                                        typeof accountId ===
                                                            'string' &&
                                                        accountId !== ''
                                                            ? (bankCashAccounts.find(
                                                                  (account) =>
                                                                      account.id ===
                                                                      accountId,
                                                              )?.name ??
                                                              accountId)
                                                            : '—';
                                                    const paymentTypeValue =
                                                        data.get(
                                                            'payment_type',
                                                        );
                                                    const paymentTypeLabel =
                                                        typeof paymentTypeValue ===
                                                        'string'
                                                            ? (paymentTypeOptions.find(
                                                                  (option) =>
                                                                      option.value ===
                                                                      paymentTypeValue,
                                                              )?.label ??
                                                              paymentTypeValue)
                                                            : '—';
                                                    const fields: FieldReview[] =
                                                        [
                                                            {
                                                                label: 'Group',
                                                                value: group.group_number,
                                                            },
                                                            {
                                                                label: 'Payee',
                                                                value:
                                                                    group.payee_name ??
                                                                    '—',
                                                            },
                                                            {
                                                                label: 'Payment amount',
                                                                value: amount,
                                                            },
                                                            {
                                                                label: 'Payment type',
                                                                value: paymentTypeLabel,
                                                            },
                                                            {
                                                                label: 'Payment mode',
                                                                value:
                                                                    paymentModeOptions.find(
                                                                        (
                                                                            option,
                                                                        ) =>
                                                                            option.value ===
                                                                            paymentMode,
                                                                    )?.label ??
                                                                    paymentMode,
                                                            },
                                                            {
                                                                label: 'Receiving / paying account',
                                                                value:
                                                                    paymentMode ===
                                                                    'bank'
                                                                        ? accountLabel
                                                                        : 'Cash',
                                                            },
                                                            {
                                                                label: 'Total eligible',
                                                                value:
                                                                    preview?.total_eligible ??
                                                                    'Preview first',
                                                            },
                                                        ];
                                                    if (
                                                        preview &&
                                                        preview.lines.length > 0
                                                    ) {
                                                        for (const line of preview.lines) {
                                                            const lr =
                                                                line.lr_number ??
                                                                line.hire_number ??
                                                                line.member_id;
                                                            fields.push({
                                                                label: `Allocation · ${lr}`,
                                                                value: `Eligible ${line.eligible_outstanding ?? '—'} → Allocated ${line.allocated_amount ?? '—'}`,
                                                            });
                                                        }
                                                    } else {
                                                        fields.push({
                                                            label: 'Allocations',
                                                            value: 'Not previewed — run Preview allocation first',
                                                        });
                                                    }
                                                    setPayReviewFields(fields);
                                                    setPayConfirmOpen(true);
                                                    return false;
                                                }
                                                paySubmitAllowed.current = false;
                                                return true;
                                            }}
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <div className="space-y-1">
                                                        <Label htmlFor="amount">
                                                            Payment amount
                                                        </Label>
                                                        <Input
                                                            id="amount"
                                                            name="amount"
                                                            type="number"
                                                            min="0.01"
                                                            step="0.01"
                                                            required
                                                        />
                                                        {errors.amount && (
                                                            <p className="text-xs text-destructive">
                                                                {errors.amount}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label htmlFor="voucher_date">
                                                            Voucher date
                                                        </Label>
                                                        <Input
                                                            id="voucher_date"
                                                            name="voucher_date"
                                                            type="date"
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label htmlFor="payment_type">
                                                            Payment type
                                                        </Label>
                                                        <select
                                                            id="payment_type"
                                                            name="payment_type"
                                                            required
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        >
                                                            {paymentTypeOptions.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label htmlFor="payment_mode">
                                                            Payment mode
                                                        </Label>
                                                        <select
                                                            id="payment_mode"
                                                            name="payment_mode"
                                                            required
                                                            value={paymentMode}
                                                            onChange={(event) =>
                                                                setPaymentMode(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        >
                                                            {paymentModeOptions.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    {paymentMode === 'bank' ? (
                                                        <div className="space-y-1 md:col-span-2">
                                                            <Label htmlFor="bank_cash_account_id">
                                                                Bank/Cash
                                                                account
                                                            </Label>
                                                            <select
                                                                id="bank_cash_account_id"
                                                                name="bank_cash_account_id"
                                                                required
                                                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            >
                                                                <option value="">
                                                                    Select
                                                                    account…
                                                                </option>
                                                                {bankCashAccounts.map(
                                                                    (
                                                                        account,
                                                                    ) => (
                                                                        <option
                                                                            key={
                                                                                account.id
                                                                            }
                                                                            value={
                                                                                account.id
                                                                            }
                                                                        >
                                                                            {
                                                                                account.name
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </div>
                                                    ) : null}
                                                    <div className="space-y-1 md:col-span-2">
                                                        <Label htmlFor="remark">
                                                            Remark
                                                        </Label>
                                                        <Input
                                                            id="remark"
                                                            name="remark"
                                                        />
                                                    </div>
                                                    <input
                                                        type="hidden"
                                                        name="idempotency_key"
                                                        value={group.id}
                                                    />
                                                    <div className="flex flex-wrap gap-2 md:col-span-2">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            disabled={
                                                                previewing
                                                            }
                                                            onClick={() => {
                                                                const form =
                                                                    document.getElementById(
                                                                        'linked-bilty-pay-form',
                                                                    ) as HTMLFormElement | null;
                                                                if (form) {
                                                                    void runPreview(
                                                                        form,
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            {previewing
                                                                ? 'Previewing…'
                                                                : 'Preview allocation'}
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                                        >
                                                            Record group payment
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </Form>
                                        {previewError ? (
                                            <p className="text-sm text-destructive">
                                                {previewError}
                                            </p>
                                        ) : null}
                                        {preview ? (
                                            <div className="overflow-x-auto rounded-md border border-border/80">
                                                <table className="w-full min-w-[640px] text-sm">
                                                    <thead className="border-b text-left text-muted-foreground">
                                                        <tr>
                                                            <th className="px-3 py-2">
                                                                LR
                                                            </th>
                                                            <th className="px-3 py-2">
                                                                Hire
                                                            </th>
                                                            <th className="px-3 py-2">
                                                                Eligible
                                                            </th>
                                                            <th className="px-3 py-2">
                                                                Allocated
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {preview.lines.map(
                                                            (line) => (
                                                                <tr
                                                                    key={
                                                                        line.member_id
                                                                    }
                                                                    className="border-b border-border/50"
                                                                >
                                                                    <td className="px-3 py-2">
                                                                        {line.lr_number ??
                                                                            '—'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {line.hire_number ??
                                                                            '—'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {line.eligible_outstanding ??
                                                                            '—'}
                                                                    </td>
                                                                    <td className="px-3 py-2 font-medium text-[#2FB36F]">
                                                                        {line.allocated_amount ??
                                                                            '—'}
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                    <tfoot>
                                                        <tr>
                                                            <td
                                                                colSpan={3}
                                                                className="px-3 py-2 text-right text-muted-foreground"
                                                            >
                                                                Payment total
                                                            </td>
                                                            <td className="px-3 py-2 font-semibold">
                                                                {preview.payment_amount ??
                                                                    '—'}
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        ) : null}
                                    </div>
                                </FbDetailCard>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Each member must have a Lorry Hire before
                                    group payment.
                                </p>
                            )}
                        </>
                    }
                    side={
                        group.remarks ? (
                            <FbDetailCard title="Remarks">
                                <p className="px-4 py-3 text-sm text-slate-700">
                                    {group.remarks}
                                </p>
                            </FbDetailCard>
                        ) : (
                            <div />
                        )
                    }
                />
            </FbDetailPage>

            <FormConfirmationDialog
                open={payConfirmOpen}
                onOpenChange={setPayConfirmOpen}
                mode="create"
                title="Record this Linked Bilty group payment?"
                description="Review amount, mode, and allocation preview before submitting."
                fields={payReviewFields}
                onConfirm={() => {
                    paySubmitAllowed.current = true;
                    setPayConfirmOpen(false);
                    window.setTimeout(() => {
                        const formElement = document.getElementById(
                            'linked-bilty-pay-form',
                        ) as HTMLFormElement | null;
                        formElement?.requestSubmit();
                    }, 0);
                }}
            />
        </>
    );
}
