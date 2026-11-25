import { Head, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import {
    QuickCreateBankCashSheet,
    type QuickCreatedBankCashAccount,
} from '@/components/masters/quick-create-bank-cash-sheet';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
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
    balance_due?: string | null;
    negative_adjusted_amount?: string | null;
    remaining_payable?: string | null;
    reserved_voucher_amount?: string | null;
    book_balance?: string | null;
    allocatable_amount: string | null;
    net_payable?: string | null;
    freight_total?: string | null;
    tds_amount?: string | null;
    billing_customer_name?: string | null;
    consignor_name?: string | null;
    consignee_name?: string | null;
};

type Props = {
    selectedLorryHireId?: string | null;
    eligibleLorryHires: HireOption[];
    paymentTypeOptions: Array<{ value: string; label: string }>;
    paymentModeOptions: Array<{ value: string; label: string }>;
    bankCashAccounts: Array<{ id: string; name: string }>;
    permissions?: {
        can_create_bank_cash?: boolean;
    };
    urls: { store: string; index: string; bank_cash_store?: string };
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
    selectedLorryHireId = null,
    eligibleLorryHires,
    paymentTypeOptions,
    paymentModeOptions,
    bankCashAccounts: initialBankCashAccounts,
    permissions,
    urls,
}: Props) {
    const initialHireId =
        selectedLorryHireId &&
        eligibleLorryHires.some((hire) => hire.id === selectedLorryHireId)
            ? selectedLorryHireId
            : '';

    const form = useForm({
        lorry_hire_id: initialHireId,
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
    const [bankCashAccounts, setBankCashAccounts] = useState(
        initialBankCashAccounts,
    );
    const [bankCashQuickCreateOpen, setBankCashQuickCreateOpen] =
        useState(false);
    const canQuickCreateBankCash =
        Boolean(permissions?.can_create_bank_cash) &&
        Boolean(urls.bank_cash_store);

    const selectedHire = eligibleLorryHires.find(
        (row) => row.id === form.data.lorry_hire_id,
    );

    const hireOptions: ComboboxOption[] = useMemo(
        () =>
            eligibleLorryHires.map((hire) => ({
                id: hire.id,
                label: hire.hire_number,
                description: [
                    hire.lr_number ? `Bilty ${hire.lr_number}` : null,
                    hire.transporter_name,
                    hire.vehicle_registration,
                    hire.allocatable_amount
                        ? `Available ₹${hire.allocatable_amount}`
                        : null,
                ]
                    .filter(Boolean)
                    .join(' · '),
            })),
        [eligibleLorryHires],
    );

    const accountOptions: ComboboxOption[] = useMemo(
        () =>
            bankCashAccounts.map((account) => ({
                id: account.id,
                label: account.name,
            })),
        [bankCashAccounts],
    );

    const proposedAmount = parseAmount(form.data.amount);
    const outstanding = parseAmount(selectedHire?.outstanding_amount);
    const balanceDue = parseAmount(
        selectedHire?.balance_due ?? selectedHire?.outstanding_amount,
    );
    const negativeAdjusted = parseAmount(
        selectedHire?.negative_adjusted_amount,
    );
    const remainingPayable = parseAmount(
        selectedHire?.remaining_payable ?? selectedHire?.allocatable_amount,
    );
    const bookBalance = parseAmount(selectedHire?.book_balance);
    const allocatable = parseAmount(selectedHire?.allocatable_amount);
    const netPayable = parseAmount(selectedHire?.net_payable);
    const freightTotal = parseAmount(selectedHire?.freight_total);
    const tdsAmount = parseAmount(selectedHire?.tds_amount);
    const isAdjustInAdvance = form.data.payment_mode === 'adjust_in_advance';
    const resultingHireOutstanding =
        outstanding !== null && proposedAmount !== null
            ? Math.max(outstanding - proposedAmount, 0)
            : null;
    const resultingBookBalance =
        isAdjustInAdvance && bookBalance !== null && proposedAmount !== null
            ? bookBalance - proposedAmount
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
        const accountLabel =
            bankCashAccounts.find(
                (account) => account.id === form.data.bank_cash_account_id,
            )?.name ?? '—';

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
                label: 'Vehicle',
                value: selectedHire?.vehicle_registration ?? '—',
            },
            {
                label: 'Transporter freight',
                value: formatMoney(freightTotal),
            },
            {
                label: 'TDS',
                value: formatMoney(tdsAmount),
            },
            {
                label: 'Net payable',
                value: formatMoney(netPayable),
            },
            {
                label: 'Balance Due',
                value: formatMoney(balanceDue),
            },
            {
                label: 'Negative Adjusted Amount',
                value: formatMoney(negativeAdjusted),
            },
            {
                label: 'Available payable',
                value: formatMoney(remainingPayable ?? allocatable),
            },
            ...(isAdjustInAdvance
                ? [
                      {
                          label: 'Available Book Balance',
                          value: formatMoney(bookBalance),
                      },
                      {
                          label: 'Resulting Book Balance',
                          value: formatMoney(resultingBookBalance),
                      },
                      {
                          label: 'Resulting Hire Outstanding',
                          value: formatMoney(resultingHireOutstanding),
                      },
                  ]
                : [
                      {
                          label: 'Resulting Outstanding',
                          value: formatMoney(resultingHireOutstanding),
                      },
                  ]),
            {
                label: 'Payment type',
                value: paymentTypeLabel,
            },
            {
                label: 'Payment mode',
                value: paymentModeLabel,
            },
            {
                label: 'Receiving / paying account',
                value: form.data.payment_mode === 'bank' ? accountLabel : '—',
            },
            {
                label: 'Proposed payment',
                value: formatMoney(proposedAmount),
            },
        ]);
        setConfirmOpen(true);
    };

    return (
        <>
            <Head title="Add Voucher Payment" />
            <LrfPage
                title="Add Voucher Payment"
                subtitle="Record transporter payment against an open Lorry Hire."
                backHref={urls.index}
                backLabel="Vouchers"
            >
                {selectedHire ? (
                    <div className="mb-3 overflow-hidden rounded-xl border border-[#bae6fd] bg-[#f0f9ff]">
                        <div className="grid gap-2 px-4 py-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <p>
                                <span className="font-semibold text-slate-700">
                                    LR Number:
                                </span>{' '}
                                {selectedHire.lr_number ?? '—'}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Transporter:
                                </span>{' '}
                                {selectedHire.transporter_name ?? '—'}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Truck:
                                </span>{' '}
                                {selectedHire.vehicle_registration ?? '—'}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Customer:
                                </span>{' '}
                                {selectedHire.billing_customer_name ?? '—'}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Consignor:
                                </span>{' '}
                                {selectedHire.consignor_name ?? '—'}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Consignee:
                                </span>{' '}
                                {selectedHire.consignee_name ?? '—'}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Balance Due:
                                </span>{' '}
                                {formatMoney(balanceDue)}
                            </p>
                            <p>
                                <span className="font-semibold text-slate-700">
                                    Available payable:
                                </span>{' '}
                                {formatMoney(remainingPayable ?? allocatable)}
                            </p>
                            {isAdjustInAdvance ? (
                                <>
                                    <p>
                                        <span className="font-semibold text-slate-700">
                                            Book Balance:
                                        </span>{' '}
                                        {formatMoney(bookBalance)}
                                    </p>
                                    <p>
                                        <span className="font-semibold text-slate-700">
                                            Remaining Book Balance:
                                        </span>{' '}
                                        {formatMoney(resultingBookBalance)}
                                    </p>
                                    <p>
                                        <span className="font-semibold text-slate-700">
                                            Remaining Hire Outstanding:
                                        </span>{' '}
                                        {formatMoney(resultingHireOutstanding)}
                                    </p>
                                </>
                            ) : null}
                            {negativeAdjusted !== null &&
                            negativeAdjusted > 0 ? (
                                <p>
                                    <span className="font-semibold text-slate-700">
                                        Negative Adjusted Amount:
                                    </span>{' '}
                                    {formatMoney(negativeAdjusted)}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                <form
                    id="voucher-create-form"
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
                    <LrfSection
                        step={1}
                        title="Payment details"
                        description="Select hire, amount, type and mode."
                        tone="receipt"
                    >
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="sm:col-span-2 lg:col-span-3">
                                <SearchableCombobox
                                    id="lorry_hire_id"
                                    name="lorry_hire_id"
                                    label="Lorry Hire"
                                    options={hireOptions}
                                    value={form.data.lorry_hire_id}
                                    onValueChange={(next) =>
                                        form.setData('lorry_hire_id', next)
                                    }
                                    required
                                    clearable={!selectedLorryHireId}
                                    placeholder="Search hire, bilty or transporter…"
                                    emptyMessage="No allocatable lorry hires in this branch."
                                    error={form.errors.lorry_hire_id}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="payment_type" required>
                                    Payment Type
                                </Label>
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
                                <Label htmlFor="payment_mode" required>
                                    Payment Mode
                                </Label>
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

                            <div className="space-y-1">
                                <Label htmlFor="amount" required>
                                    Amount
                                </Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={form.data.amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {form.errors.amount ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.amount}
                                    </p>
                                ) : null}
                                {proposedAmount !== null ? (
                                    <p className="text-xs text-slate-500">
                                        {isAdjustInAdvance
                                            ? 'Remaining hire outstanding '
                                            : 'Resulting outstanding '}
                                        {formatMoney(resultingHireOutstanding)}
                                        {isAdjustInAdvance ? (
                                            <>
                                                {' · Remaining book balance '}
                                                {formatMoney(
                                                    resultingBookBalance,
                                                )}
                                            </>
                                        ) : null}
                                    </p>
                                ) : null}
                            </div>

                            {form.data.payment_mode === 'bank' ? (
                                <div className="sm:col-span-2 lg:col-span-3">
                                    <SearchableCombobox
                                        id="bank_cash_account_id"
                                        name="bank_cash_account_id"
                                        label="Bank/Cash account"
                                        options={accountOptions}
                                        value={form.data.bank_cash_account_id}
                                        onValueChange={(next) =>
                                            form.setData(
                                                'bank_cash_account_id',
                                                next,
                                            )
                                        }
                                        required
                                        placeholder="Search account…"
                                        emptyMessage="No bank/cash accounts in this branch."
                                        error={form.errors.bank_cash_account_id}
                                        addAction={
                                            canQuickCreateBankCash
                                                ? {
                                                      label: '+ Add',
                                                      onClick: () =>
                                                          setBankCashQuickCreateOpen(
                                                              true,
                                                          ),
                                                  }
                                                : undefined
                                        }
                                    />
                                </div>
                            ) : null}

                            {form.data.payment_mode === 'paid_to_pump' ? (
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
                            ) : null}

                            <div className="space-y-1 sm:col-span-2 lg:col-span-3">
                                <Label htmlFor="remark">Remark</Label>
                                <Input
                                    id="remark"
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

                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Save voucher
                            </Button>
                        </div>
                    </LrfSection>
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

                {bankCashQuickCreateOpen && urls.bank_cash_store ? (
                    <QuickCreateBankCashSheet
                        open={bankCashQuickCreateOpen}
                        onOpenChange={setBankCashQuickCreateOpen}
                        storeUrl={urls.bank_cash_store}
                        onCreated={(account: QuickCreatedBankCashAccount) => {
                            setBankCashAccounts((current) =>
                                current.some((row) => row.id === account.id)
                                    ? current
                                    : [
                                          ...current,
                                          {
                                              id: account.id,
                                              name: account.name,
                                          },
                                      ],
                            );
                            form.setData('bank_cash_account_id', account.id);
                            setBankCashQuickCreateOpen(false);
                        }}
                    />
                ) : null}
            </LrfPage>
        </>
    );
}
