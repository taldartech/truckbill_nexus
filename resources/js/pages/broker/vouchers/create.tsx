import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type HireOption = {
    id: string;
    hire_number: string;
    lr_number: string | null;
    transporter_name: string | null;
    vehicle_registration: string | null;
    outstanding_amount: string | null;
    allocatable_amount: string | null;
};

type Props = {
    eligibleLorryHires: HireOption[];
    paymentTypeOptions: Array<{ value: string; label: string }>;
    paymentModeOptions: Array<{ value: string; label: string }>;
    bankCashAccounts: Array<{ id: string; name: string }>;
    urls: { store: string; index: string };
};

function parseAmount(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value.trim() === '') {
        return null;
    }
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatMoney(value: number | null): string {
    if (value === null) {
        return '—';
    }
    return `₹${value.toFixed(2)}`;
}

export default function VoucherCreate({
    eligibleLorryHires,
    paymentTypeOptions,
    paymentModeOptions,
    bankCashAccounts,
    urls,
}: Props) {
    const form = useForm({
        lorry_hire_id: '',
        amount: '',
        payment_type: paymentTypeOptions[0]?.value ?? 'advance',
        payment_mode: paymentModeOptions[0]?.value ?? 'bank',
        bank_cash_account_id: '',
        cash_reference: '',
        pump_reference: '',
        remark: '',
    });

    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);

    const selectedHire = eligibleLorryHires.find(
        (row) => row.id === form.data.lorry_hire_id,
    );

    const proposedAmount = parseAmount(form.data.amount);
    const outstanding = parseAmount(selectedHire?.outstanding_amount);
    const allocatable = parseAmount(selectedHire?.allocatable_amount);
    const resultingBalance =
        outstanding !== null && proposedAmount !== null
            ? outstanding - proposedAmount
            : null;

    const openConfirmation = () => {
        const paymentTypeLabel =
            paymentTypeOptions.find(
                (option) => option.value === form.data.payment_type,
            )?.label ?? form.data.payment_type;
        const paymentModeLabel =
            paymentModeOptions.find(
                (option) => option.value === form.data.payment_mode,
            )?.label ?? form.data.payment_mode;

        setReviewFields([
            {
                label: 'Lorry Hire',
                value: selectedHire?.hire_number ?? '—',
            },
            {
                label: 'Bilty No.',
                value: selectedHire?.lr_number ?? '—',
            },
            {
                label: 'Transporter',
                value: selectedHire?.transporter_name ?? '—',
            },
            {
                label: 'Outstanding',
                value: formatMoney(outstanding),
            },
            {
                label: 'Available payable',
                value: formatMoney(allocatable),
            },
            {
                label: 'Payment type',
                value: paymentTypeLabel,
            },
            {
                label: 'Payment mode',
                value: paymentModeLabel,
            },
            {
                label: 'Proposed payment',
                value: formatMoney(proposedAmount),
            },
            {
                label: 'Resulting balance',
                value: formatMoney(resultingBalance),
            },
        ]);
        setConfirmOpen(true);
    };

    return (
        <>
            <Head title="Create Transporter Voucher" />
            <PageShell
                title="Create Transporter Voucher"
                actions={
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Back to vouchers
                    </Link>
                }
            >
                <form
                    id="voucher-create-form"
                    className="mx-auto max-w-xl space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!submitAllowed.current) {
                            openConfirmation();
                            return;
                        }
                        submitAllowed.current = false;
                        form.post(urls.store);
                    }}
                >
                    <div className="space-y-1">
                        <Label htmlFor="lorry_hire_id">Lorry Hire</Label>
                        <select
                            id="lorry_hire_id"
                            value={form.data.lorry_hire_id}
                            onChange={(event) =>
                                form.setData(
                                    'lorry_hire_id',
                                    event.target.value,
                                )
                            }
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            required
                        >
                            <option value="">Select Lorry Hire…</option>
                            {eligibleLorryHires.map((hire) => (
                                <option key={hire.id} value={hire.id}>
                                    {hire.hire_number}
                                    {hire.lr_number
                                        ? ` (LR ${hire.lr_number})`
                                        : ''}{' '}
                                    — allocatable {hire.allocatable_amount}
                                </option>
                            ))}
                        </select>
                        {selectedHire && (
                            <div className="rounded-md border border-border bg-muted/10 p-3 text-xs text-muted-foreground">
                                <p>
                                    {selectedHire.transporter_name} ·{' '}
                                    {selectedHire.vehicle_registration}
                                </p>
                                <p>
                                    Outstanding {formatMoney(outstanding)} ·
                                    Available {formatMoney(allocatable)}
                                </p>
                                {proposedAmount !== null ? (
                                    <p>
                                        Proposed {formatMoney(proposedAmount)} ·
                                        Resulting{' '}
                                        {formatMoney(resultingBalance)}
                                    </p>
                                ) : null}
                            </div>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="amount">Amount</Label>
                        <Input
                            id="amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                            required
                        />
                        {form.errors.amount && (
                            <p className="text-xs text-destructive">
                                {form.errors.amount}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="payment_type">Payment type</Label>
                            <select
                                id="payment_type"
                                value={form.data.payment_type}
                                onChange={(event) =>
                                    form.setData(
                                        'payment_type',
                                        event.target.value,
                                    )
                                }
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {paymentTypeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="payment_mode">Payment mode</Label>
                            <select
                                id="payment_mode"
                                value={form.data.payment_mode}
                                onChange={(event) =>
                                    form.setData(
                                        'payment_mode',
                                        event.target.value,
                                    )
                                }
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {paymentModeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {form.data.payment_mode === 'bank' && (
                        <div className="space-y-1">
                            <Label htmlFor="bank_cash_account_id">
                                Bank/Cash account
                            </Label>
                            <select
                                id="bank_cash_account_id"
                                value={form.data.bank_cash_account_id}
                                onChange={(event) =>
                                    form.setData(
                                        'bank_cash_account_id',
                                        event.target.value,
                                    )
                                }
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                required
                            >
                                <option value="">Select account…</option>
                                {bankCashAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {form.data.payment_mode === 'paid_to_pump' && (
                        <div className="space-y-1">
                            <Label htmlFor="pump_reference">
                                Pump reference
                            </Label>
                            <Input
                                id="pump_reference"
                                value={form.data.pump_reference}
                                onChange={(event) =>
                                    form.setData(
                                        'pump_reference',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="remark">Remark</Label>
                        <Input
                            id="remark"
                            value={form.data.remark}
                            onChange={(event) =>
                                form.setData('remark', event.target.value)
                            }
                        />
                    </div>

                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                    >
                        Save voucher
                    </Button>
                </form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Record this voucher payment?"
                    description="Review outstanding, proposed payment, and resulting balance before submitting."
                    fields={reviewFields}
                    onConfirm={() => {
                        submitAllowed.current = true;
                        setConfirmOpen(false);
                        window.setTimeout(() => {
                            const formElement = document.getElementById(
                                'voucher-create-form',
                            ) as HTMLFormElement | null;
                            formElement?.requestSubmit();
                        }, 0);
                    }}
                />
            </PageShell>
        </>
    );
}
