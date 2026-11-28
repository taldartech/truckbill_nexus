import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

const EMPTY = {
    type: '',
    amount: '',
    new_weight: '',
    quantity: '',
    rate: '',
    reason: '',
    occurred_at: '',
};

type TypeOption = { value: string; label: string };

type Props = {
    storeUrl: string;
    typeOptions: TypeOption[];
};

/** Post-hire freight adjustment draft — clears only after successful save. */
export function FreightAdjustmentEntry({ storeUrl, typeOptions }: Props) {
    const form = useForm({ ...EMPTY });
    const submitting = useRef(false);

    const needsWeight = form.data.type === 'unloading_weight';
    const needsQtyRate =
        form.data.type === 'shortage' || form.data.type === 'damage';

    return (
        <form
            className="space-y-3 border-t border-slate-100 px-4 py-3"
            data-testid="freight-adjustment-entry"
            onSubmit={(event) => {
                event.preventDefault();
                if (submitting.current) {
                    return;
                }
                submitting.current = true;
                form.post(storeUrl, {
                    preserveScroll: true,
                    onFinish: () => {
                        submitting.current = false;
                    },
                    onSuccess: () => {
                        form.clearErrors();
                        form.setData({ ...EMPTY });
                    },
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="space-y-1">
                    <Label htmlFor="adjustment_type" required>
                        Adjustment type
                    </Label>
                    <select
                        id="adjustment_type"
                        className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData('type', event.target.value)
                        }
                        required
                    >
                        <option value="">Select type</option>
                        {typeOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.type} />
                </div>
                {needsWeight ? (
                    <div className="space-y-1">
                        <Label htmlFor="adjustment_new_weight" required>
                            Unloading weight (kg)
                        </Label>
                        <Input
                            id="adjustment_new_weight"
                            inputMode="decimal"
                            value={form.data.new_weight}
                            onChange={(event) =>
                                form.setData('new_weight', event.target.value)
                            }
                        />
                        <InputError message={form.errors.new_weight} />
                    </div>
                ) : needsQtyRate ? (
                    <>
                        <div className="space-y-1">
                            <Label htmlFor="adjustment_quantity">
                                Quantity
                            </Label>
                            <Input
                                id="adjustment_quantity"
                                inputMode="decimal"
                                value={form.data.quantity}
                                onChange={(event) =>
                                    form.setData('quantity', event.target.value)
                                }
                            />
                            <InputError message={form.errors.quantity} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="adjustment_rate">Rate</Label>
                            <Input
                                id="adjustment_rate"
                                inputMode="decimal"
                                value={form.data.rate}
                                onChange={(event) =>
                                    form.setData('rate', event.target.value)
                                }
                            />
                            <InputError message={form.errors.rate} />
                        </div>
                    </>
                ) : (
                    <div className="space-y-1">
                        <Label htmlFor="adjustment_amount" required>
                            Amount
                        </Label>
                        <Input
                            id="adjustment_amount"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                        <InputError message={form.errors.amount} />
                    </div>
                )}
                <div className="space-y-1 sm:col-span-2">
                    <Label htmlFor="adjustment_reason">Description</Label>
                    <Input
                        id="adjustment_reason"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                    />
                    <InputError message={form.errors.reason} />
                </div>
            </div>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing}
                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                >
                    Save adjustment
                </Button>
            </div>
        </form>
    );
}
