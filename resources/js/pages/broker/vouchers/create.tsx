import { Head, Link, useForm } from '@inertiajs/react';
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

    const selectedHire = eligibleLorryHires.find(
        (row) => row.id === form.data.lorry_hire_id,
    );

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
                    className="mx-auto max-w-xl space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
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
                            <p className="text-xs text-muted-foreground">
                                {selectedHire.transporter_name} ·{' '}
                                {selectedHire.vehicle_registration} ·
                                outstanding {selectedHire.outstanding_amount}
                            </p>
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
            </PageShell>
        </>
    );
}
