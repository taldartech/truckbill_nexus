import { useForm, useHttp } from '@inertiajs/react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import { QuickCreateBankCashSheet } from '@/components/masters/quick-create-bank-cash-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
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
import { formatInr, formatInrPlain } from '@/lib/money';

type Option = { value: string; label: string };

type ContextHire = {
    id: string;
    hire_number: string;
    lr_number: string | null;
    freight_total: string | null;
    paid_amount: string | null;
    reserved_amount: string | null;
    remaining_payable: string | null;
    shortage_damage_amount: string | null;
};

type PaymentContext = {
    payee: {
        name: string | null;
        mobile: string | null;
        short_name: string | null;
        beneficiary_id: string | null;
        tds_percent: string | null;
    };
    vehicle_registration: string | null;
    book_balance: string | null;
    hires: ContextHire[];
    previous_payments: Array<{
        id: string;
        voucher_number: string;
        hire_number: string;
        voucher_date: string | null;
        payment_type: string;
        payment_mode: string | null;
        amount: string | null;
        status: string;
    }>;
    payment_type_options: Option[];
    payment_mode_options: Option[];
    bank_cash_accounts: Array<{ id: string; name: string }>;
    today: string;
    bank_cash_store_url: string;
    permissions: { can_create_bank_cash: boolean };
};

type PaymentForm = {
    lines: Array<{ lorry_hire_id: string; amount: string }>;
    payment_type: string;
    payment_mode: string;
    voucher_date: string;
    bank_cash_account_id: string;
    pump_reference: string;
    remark: string;
    confirm_duplicate: boolean;
};

export type VoucherPaymentUrls = {
    payment_context: string;
    voucher_store: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hireIds: string[];
    urls: VoucherPaymentUrls;
    onRecorded?: (hireIds: string[]) => void;
};

const ADJUST_IN_ADVANCE = 'adjust_in_advance';

function toNumber(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value.trim() === '') {
        return null;
    }
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function money(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '—' : formatInr(value);
}

function sum(values: Array<number | null>): number {
    return values.reduce<number>((total, value) => total + (value ?? 0), 0);
}

/**
 * Legacy rule: Final when every hire is paid in full, otherwise Advance.
 */
function derivePaymentType(
    hires: ContextHire[],
    lines: PaymentForm['lines'],
): string {
    const settlesAll = lines.every((line) => {
        const remaining = toNumber(
            hires.find((hire) => hire.id === line.lorry_hire_id)
                ?.remaining_payable ?? null,
        );
        const amount = toNumber(line.amount);

        return (
            remaining !== null &&
            amount !== null &&
            Math.abs(remaining - amount) < 0.005
        );
    });

    return settlesAll ? 'final' : 'advance';
}

export function VoucherPaymentDialog({
    open,
    onOpenChange,
    hireIds,
    urls,
    onRecorded,
}: Props) {
    const [context, setContext] = useState<PaymentContext | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [typeTouched, setTypeTouched] = useState(false);
    const [showClientErrors, setShowClientErrors] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [quickBankOpen, setQuickBankOpen] = useState(false);
    const [bankAccounts, setBankAccounts] = useState<
        Array<{ id: string; name: string }>
    >([]);
    const contextRequest = useHttp<Record<string, never>, PaymentContext>({});

    const form = useForm<PaymentForm>({
        lines: [],
        payment_type: 'advance',
        payment_mode: '',
        voucher_date: '',
        bank_cash_account_id: '',
        pump_reference: '',
        remark: '',
        confirm_duplicate: false,
    });

    const hireKey = hireIds.join(',');

    useEffect(() => {
        if (!open) {
            setContext(null);
            setLoadError(null);
            setTypeTouched(false);
            setShowClientErrors(false);
            setConfirmOpen(false);
            form.reset();
            form.clearErrors();
            return;
        }
        if (hireKey === '') {
            return;
        }
        setContext(null);
        setLoadError(null);
        setTypeTouched(false);
        setShowClientErrors(false);
        form.clearErrors();
        const query = new URLSearchParams();
        for (const id of hireKey.split(',')) {
            query.append('hire_ids[]', id);
        }
        void contextRequest.get(`${urls.payment_context}?${query}`, {
            onSuccess: (payload) => {
                setContext(payload);
                setBankAccounts(payload.bank_cash_accounts);
                form.setData({
                    lines: payload.hires.map((hire) => ({
                        lorry_hire_id: hire.id,
                        amount: '',
                    })),
                    payment_type: 'advance',
                    payment_mode: '',
                    voucher_date: payload.today,
                    bank_cash_account_id: '',
                    pump_reference: '',
                    remark: '',
                    confirm_duplicate: false,
                });
            },
            onError: (errors) => {
                setLoadError(
                    Object.values(errors)[0] ??
                        'These Lorry Hires cannot be paid together.',
                );
            },
            onHttpException: () => {
                setLoadError('Unable to load payment details. Try again.');

                return false;
            },
            onNetworkError: () => {
                setLoadError(
                    'Network error while loading payment details. Try again.',
                );

                return false;
            },
        });
        // Reload only when the dialog opens for a different hire selection.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, hireKey]);

    const hires = useMemo(() => context?.hires ?? [], [context]);
    const isBulk = hires.length > 1;
    const isAdjustment = form.data.payment_mode === ADJUST_IN_ADVANCE;
    const bookBalance = toNumber(context?.book_balance ?? null);
    const totalRemaining = sum(
        hires.map((hire) => toNumber(hire.remaining_payable)),
    );
    const totalPayment = sum(
        form.data.lines.map((line) => toNumber(line.amount)),
    );
    const maxAdjustment =
        bookBalance === null ? null : Math.min(bookBalance, totalRemaining);

    const clientErrors = useMemo(() => {
        const errors: Record<string, string> = {};
        form.data.lines.forEach((line, index) => {
            const hire = hires.find((row) => row.id === line.lorry_hire_id);
            const amount = toNumber(line.amount);
            const remaining = toNumber(hire?.remaining_payable ?? null);
            if (amount === null) {
                errors[`lines.${index}.amount`] = 'Enter the payment amount.';
            } else if (amount <= 0) {
                errors[`lines.${index}.amount`] =
                    'Payment amount must be greater than zero.';
            } else if (remaining !== null && amount > remaining + 0.0001) {
                errors[`lines.${index}.amount`] =
                    `Payment amount cannot exceed the remaining payable of ${formatInrPlain(remaining)}.`;
            } else if (
                isAdjustment &&
                !isBulk &&
                bookBalance !== null &&
                amount > bookBalance + 0.0001
            ) {
                errors[`lines.${index}.amount`] =
                    `Adjustment amount cannot exceed the available Book Balance of ${formatInrPlain(bookBalance)}.`;
            }
        });
        if (
            isAdjustment &&
            isBulk &&
            bookBalance !== null &&
            totalPayment > bookBalance + 0.0001
        ) {
            errors.lines = `Total adjustment of ${formatInrPlain(totalPayment)} cannot exceed the available Book Balance of ${formatInrPlain(bookBalance)}.`;
        }
        if (form.data.payment_mode === '') {
            errors.payment_mode = 'Select the payment mode.';
        }
        if (
            form.data.payment_mode === 'paid_to_pump' &&
            form.data.pump_reference.trim() === ''
        ) {
            errors.pump_reference = 'Enter the Pump Name for Paid to Pump.';
        }
        if (form.data.voucher_date === '') {
            errors.voucher_date = 'Select the payment date.';
        }

        return errors;
    }, [form.data, hires, isAdjustment, isBulk, bookBalance, totalPayment]);

    const isImmediate = (key: string): boolean => {
        if (key === 'lines') {
            return true;
        }
        const match = /^lines\.(\d+)\.amount$/.exec(key);

        return (
            match !== null && form.data.lines[Number(match[1])]?.amount !== ''
        );
    };
    const errorFor = (key: string): string | undefined => {
        const serverErrors = form.errors as Record<string, string | undefined>;

        return (
            serverErrors[key] ??
            (showClientErrors || isImmediate(key)
                ? clientErrors[key]
                : undefined)
        );
    };
    const hasClientErrors = Object.keys(clientErrors).length > 0;

    const setLineAmount = (index: number, amount: string) => {
        const lines = form.data.lines.map((line, position) =>
            position === index ? { ...line, amount } : line,
        );
        form.setData((current) => ({
            ...current,
            lines,
            confirm_duplicate: false,
            payment_type: typeTouched
                ? current.payment_type
                : derivePaymentType(hires, lines),
        }));
        form.clearErrors(`lines.${index}.amount` as keyof PaymentForm);
    };

    const label = (options: Option[] | undefined, value: string): string =>
        options?.find((option) => option.value === value)?.label ?? '—';

    const review = () => {
        setShowClientErrors(true);
        if (!hasClientErrors) {
            setConfirmOpen(true);
        }
    };

    const submit = () => {
        form.post(urls.voucher_store, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setConfirmOpen(false);
                onOpenChange(false);
                onRecorded?.(hires.map((hire) => hire.id));
            },
            onError: () => setConfirmOpen(false),
        });
    };

    const accountOptions: ComboboxOption[] = bankAccounts.map((account) => ({
        id: account.id,
        label: account.name,
    }));

    const title = isBulk
        ? `Voucher Payment — ${hires.length} Lorry Hires`
        : `Voucher Payment — ${hires[0]?.hire_number ?? ''}`;

    return (
        <>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!form.processing) {
                        onOpenChange(next);
                    }
                }}
            >
                <DialogContent
                    className="max-h-[88vh] overflow-y-auto p-5 sm:max-w-4xl"
                    data-testid="voucher-payment-modal"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {context ? title : 'Voucher Payment'}
                        </DialogTitle>
                        <DialogDescription>
                            Same Payee and Truck only. Payment Type, Mode, Date
                            and Remarks apply to every selected Lorry Hire.
                        </DialogDescription>
                    </DialogHeader>

                    {loadError ? (
                        <p
                            role="alert"
                            className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                        >
                            {loadError}
                        </p>
                    ) : null}

                    {!context && !loadError ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            Loading payment details…
                        </p>
                    ) : null}

                    {context ? (
                        <form
                            id="voucher-payment-form"
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                review();
                            }}
                        >
                            <LegacyPayPanel
                                title="Previous Payments"
                                testId="voucher-panel-previous-payments"
                            >
                                {context.previous_payments.length === 0 ? (
                                    <p className="text-sm text-slate-500">
                                        No previous payments for the selected
                                        Lorry Hire(s).
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead className="bg-slate-50 text-left text-xs text-slate-600">
                                                <tr>
                                                    <th className="px-2 py-1">
                                                        Voucher
                                                    </th>
                                                    <th className="px-2 py-1">
                                                        FR No
                                                    </th>
                                                    <th className="px-2 py-1">
                                                        Date
                                                    </th>
                                                    <th className="px-2 py-1">
                                                        Type / Mode
                                                    </th>
                                                    <th className="px-2 py-1">
                                                        Status
                                                    </th>
                                                    <th className="px-2 py-1 text-end">
                                                        Amount
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {context.previous_payments.map(
                                                    (payment) => (
                                                        <tr
                                                            key={payment.id}
                                                            className="border-t border-slate-100"
                                                        >
                                                            <td className="px-2 py-1">
                                                                {
                                                                    payment.voucher_number
                                                                }
                                                            </td>
                                                            <td className="px-2 py-1">
                                                                {
                                                                    payment.hire_number
                                                                }
                                                            </td>
                                                            <td className="px-2 py-1">
                                                                {payment.voucher_date ??
                                                                    '—'}
                                                            </td>
                                                            <td className="px-2 py-1">
                                                                {
                                                                    payment.payment_type
                                                                }{' '}
                                                                /{' '}
                                                                {payment.payment_mode ??
                                                                    '—'}
                                                            </td>
                                                            <td className="px-2 py-1 capitalize">
                                                                {payment.status.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </td>
                                                            <td className="px-2 py-1 text-end tabular-nums">
                                                                {money(
                                                                    payment.amount,
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </LegacyPayPanel>

                            <LegacyPayPanel
                                title="Transporter Details"
                                testId="voucher-panel-transporter"
                            >
                                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    <Fact
                                        label="Transporter"
                                        value={context.payee.name}
                                    />
                                    <Fact
                                        label="Mobile"
                                        value={context.payee.mobile}
                                    />
                                    <Fact
                                        label="Short Name"
                                        value={context.payee.short_name}
                                    />
                                    <Fact
                                        label="Beneficiary ID"
                                        value={context.payee.beneficiary_id}
                                    />
                                    <Fact
                                        label="TDS Percentage"
                                        value={context.payee.tds_percent}
                                    />
                                    <Fact
                                        label="Truck Number"
                                        value={context.vehicle_registration}
                                    />
                                    <Fact
                                        label="Book Balance"
                                        value={money(context.book_balance)}
                                    />
                                </dl>
                            </LegacyPayPanel>

                            <LegacyPayPanel
                                title="Payment Allocation"
                                testId="voucher-panel-allocation"
                            >
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="bg-slate-50 text-left text-xs font-semibold text-slate-600 uppercase">
                                            <tr>
                                                <th className="px-3 py-2">
                                                    FR No.
                                                </th>
                                                <th className="px-3 py-2">
                                                    LR Number
                                                </th>
                                                <th className="px-3 py-2 text-end">
                                                    Balance Amount
                                                </th>
                                                <th className="w-44 px-3 py-2 text-end">
                                                    Payment Amount{' '}
                                                    <span aria-hidden="true">
                                                        *
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {form.data.lines.map(
                                                (line, index) => {
                                                    const hire = hires.find(
                                                        (row) =>
                                                            row.id ===
                                                            line.lorry_hire_id,
                                                    );
                                                    const error = errorFor(
                                                        `lines.${index}.amount`,
                                                    );

                                                    return (
                                                        <tr
                                                            key={
                                                                line.lorry_hire_id
                                                            }
                                                            className="border-t border-slate-100 align-top"
                                                        >
                                                            <td className="px-3 py-2 font-medium">
                                                                {
                                                                    hire?.hire_number
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {hire?.lr_number ??
                                                                    '—'}
                                                            </td>
                                                            <td className="px-3 py-2 text-end tabular-nums">
                                                                {money(
                                                                    hire?.remaining_payable,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Input
                                                                    type="number"
                                                                    min="0.01"
                                                                    step="0.01"
                                                                    inputMode="decimal"
                                                                    className="text-end tabular-nums"
                                                                    aria-label={`Payment amount for ${hire?.hire_number ?? 'Lorry Hire'}`}
                                                                    aria-invalid={
                                                                        error
                                                                            ? true
                                                                            : undefined
                                                                    }
                                                                    value={
                                                                        line.amount
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setLineAmount(
                                                                            index,
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                                {error ? (
                                                                    <p
                                                                        role="alert"
                                                                        className="mt-1 text-xs text-destructive"
                                                                    >
                                                                        {error}
                                                                    </p>
                                                                ) : null}
                                                            </td>
                                                        </tr>
                                                    );
                                                },
                                            )}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t border-slate-200 bg-slate-50 font-semibold">
                                                <td
                                                    className="px-3 py-2 text-end"
                                                    colSpan={3}
                                                >
                                                    Total Payment Amount
                                                </td>
                                                <td className="px-3 py-2 text-end tabular-nums">
                                                    {formatInr(totalPayment)}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                {errorFor('lines') ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errorFor('lines')}
                                    </p>
                                ) : null}
                            </LegacyPayPanel>

                            <LegacyPayPanel
                                title="Payment Details"
                                testId="voucher-panel-payment-details"
                            >
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor="voucher_payment_type"
                                            required
                                        >
                                            Payment Type
                                        </Label>
                                        <select
                                            id="voucher_payment_type"
                                            value={form.data.payment_type}
                                            onChange={(event) => {
                                                setTypeTouched(true);
                                                form.setData(
                                                    'payment_type',
                                                    event.target.value,
                                                );
                                            }}
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            {context.payment_type_options.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <FieldError
                                            message={errorFor('payment_type')}
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label
                                            htmlFor="voucher_payment_mode"
                                            required
                                        >
                                            Payment Mode
                                        </Label>
                                        <select
                                            id="voucher_payment_mode"
                                            value={form.data.payment_mode}
                                            onChange={(event) => {
                                                form.setData((current) => ({
                                                    ...current,
                                                    payment_mode:
                                                        event.target.value,
                                                    confirm_duplicate: false,
                                                }));
                                                form.clearErrors();
                                            }}
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="">
                                                Select mode
                                            </option>
                                            {context.payment_mode_options.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <FieldError
                                            message={errorFor('payment_mode')}
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label
                                            htmlFor="voucher_payment_date"
                                            required
                                        >
                                            Payment Date
                                        </Label>
                                        <Input
                                            id="voucher_payment_date"
                                            type="date"
                                            value={form.data.voucher_date}
                                            onChange={(event) =>
                                                form.setData(
                                                    'voucher_date',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <FieldError
                                            message={errorFor('voucher_date')}
                                        />
                                    </div>

                                    {isAdjustment ? (
                                        <p
                                            className="rounded-md border border-[#bae6fd] bg-[#f0f9ff] px-3 py-2 text-sm text-slate-700 sm:col-span-2 lg:col-span-3"
                                            data-testid="adjustment-limit"
                                        >
                                            Available Book Balance{' '}
                                            <strong>
                                                {money(context.book_balance)}
                                            </strong>{' '}
                                            · Maximum adjustment{' '}
                                            <strong>
                                                {money(maxAdjustment)}
                                            </strong>
                                            . Posts Dr Transporter Payable / Cr
                                            Transporter Advance; no Bank or Cash
                                            payment.
                                        </p>
                                    ) : null}

                                    {form.data.payment_mode === 'bank' ? (
                                        <div className="sm:col-span-2">
                                            <SearchableCombobox
                                                id="voucher_bank_cash_account_id"
                                                name="bank_cash_account_id"
                                                label="Bank Account"
                                                options={accountOptions}
                                                value={
                                                    form.data
                                                        .bank_cash_account_id
                                                }
                                                onValueChange={(next) =>
                                                    form.setData(
                                                        'bank_cash_account_id',
                                                        next,
                                                    )
                                                }
                                                placeholder="Search account…"
                                                emptyMessage="No bank accounts in this branch."
                                                error={errorFor(
                                                    'bank_cash_account_id',
                                                )}
                                                addAction={
                                                    context.permissions
                                                        .can_create_bank_cash
                                                        ? {
                                                              label: '+ Add',
                                                              onClick: () =>
                                                                  setQuickBankOpen(
                                                                      true,
                                                                  ),
                                                          }
                                                        : undefined
                                                }
                                            />
                                        </div>
                                    ) : null}

                                    {form.data.payment_mode ===
                                    'paid_to_pump' ? (
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor="voucher_pump_reference"
                                                required
                                            >
                                                Pump Name
                                            </Label>
                                            <Input
                                                id="voucher_pump_reference"
                                                value={form.data.pump_reference}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'pump_reference',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <FieldError
                                                message={errorFor(
                                                    'pump_reference',
                                                )}
                                            />
                                        </div>
                                    ) : null}

                                    <div className="space-y-1 sm:col-span-2">
                                        <Label htmlFor="voucher_remark">
                                            Remarks
                                        </Label>
                                        <Input
                                            id="voucher_remark"
                                            value={form.data.remark}
                                            onChange={(event) =>
                                                form.setData(
                                                    'remark',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                {form.errors.confirm_duplicate ? (
                                    <div
                                        role="alert"
                                        className="space-y-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                                    >
                                        <p>{form.errors.confirm_duplicate}</p>
                                        <label className="flex items-center gap-2 font-medium">
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={
                                                    form.data.confirm_duplicate
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'confirm_duplicate',
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                            This is not a duplicate — record it
                                        </label>
                                    </div>
                                ) : null}
                            </LegacyPayPanel>
                        </form>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        {context ? (
                            <Button
                                type="submit"
                                form="voucher-payment-form"
                                disabled={form.processing}
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Review payment
                            </Button>
                        ) : null}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {context ? (
                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Record this voucher payment?"
                    description="Review all payment details carefully. Once a voucher is marked Paid it cannot be edited."
                    processing={form.processing}
                    confirmLabel="Record payment"
                    onConfirm={submit}
                    sections={[
                        {
                            title: 'Payment',
                            fields: [
                                {
                                    label: 'Payee',
                                    value: context.payee.name ?? '—',
                                },
                                {
                                    label: 'Truck',
                                    value: context.vehicle_registration ?? '—',
                                },
                                {
                                    label: 'Payment Type',
                                    value: label(
                                        context.payment_type_options,
                                        form.data.payment_type,
                                    ),
                                },
                                {
                                    label: 'Payment Mode',
                                    value: label(
                                        context.payment_mode_options,
                                        form.data.payment_mode,
                                    ),
                                },
                                {
                                    label: 'Payment Date',
                                    value: form.data.voucher_date,
                                },
                                ...(form.data.payment_mode === 'bank'
                                    ? [
                                          {
                                              label: 'Bank Account',
                                              value:
                                                  bankAccounts.find(
                                                      (account) =>
                                                          account.id ===
                                                          form.data
                                                              .bank_cash_account_id,
                                                  )?.name ?? '—',
                                          },
                                      ]
                                    : []),
                                ...(form.data.payment_mode === 'paid_to_pump'
                                    ? [
                                          {
                                              label: 'Pump Name',
                                              value: form.data.pump_reference,
                                          },
                                      ]
                                    : []),
                                ...(isAdjustment && bookBalance !== null
                                    ? [
                                          {
                                              label: 'Book Balance after adjustment',
                                              value: formatInr(
                                                  bookBalance - totalPayment,
                                              ),
                                          },
                                      ]
                                    : []),
                                ...(form.data.remark
                                    ? [
                                          {
                                              label: 'Remarks',
                                              value: form.data.remark,
                                          },
                                      ]
                                    : []),
                            ],
                        },
                        {
                            title: 'Allocation',
                            layout: 'full',
                            table: {
                                headers: [
                                    'Hire No',
                                    'Outstanding',
                                    'Payment Amount',
                                    'Outstanding after',
                                ],
                                rows: form.data.lines.map((line) => {
                                    const hire = hires.find(
                                        (row) => row.id === line.lorry_hire_id,
                                    );
                                    const remaining = toNumber(
                                        hire?.remaining_payable ?? null,
                                    );
                                    const amount = toNumber(line.amount) ?? 0;

                                    return [
                                        hire?.hire_number ?? '—',
                                        money(remaining),
                                        formatInr(amount),
                                        remaining === null
                                            ? '—'
                                            : formatInr(remaining - amount),
                                    ];
                                }),
                            },
                            fields: [
                                {
                                    label: 'Total Payment Amount',
                                    value: formatInr(totalPayment),
                                    emphasis: 'total',
                                },
                            ],
                        },
                    ]}
                />
            ) : null}

            {quickBankOpen && context ? (
                <QuickCreateBankCashSheet
                    open={quickBankOpen}
                    onOpenChange={setQuickBankOpen}
                    storeUrl={context.bank_cash_store_url}
                    onCreated={(account) => {
                        setBankAccounts((current) =>
                            current.some((row) => row.id === account.id)
                                ? current
                                : [
                                      ...current,
                                      { id: account.id, name: account.name },
                                  ],
                        );
                        form.setData('bank_cash_account_id', account.id);
                        setQuickBankOpen(false);
                    }}
                />
            ) : null}
        </>
    );
}

function LegacyPayPanel({
    title,
    testId,
    children,
}: {
    title: string;
    testId: string;
    children: ReactNode;
}) {
    return (
        <section
            data-testid={testId}
            className="overflow-hidden rounded-lg border border-slate-200"
        >
            <h3 className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">
                {title}
            </h3>
            <div className="p-3">{children}</div>
        </section>
    );
}

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex justify-between gap-2 sm:block">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900 tabular-nums">
                {value ?? '—'}
            </dd>
        </div>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p role="alert" className="text-xs text-destructive">
            {message}
        </p>
    ) : null;
}
