import { Form, Head } from '@inertiajs/react';
import {
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    lorryReceipt: {
        id: string;
        lr_number: string | null;
        freight_total: string;
        consignee_name: string | null;
    };
    partload_inward_line_id: string | null;
    paymentModeOptions: Array<{ value: string; label: string }>;
    urls: { store: string };
};

export default function PartloadDeliveryReceiptCreate({
    lorryReceipt,
    partload_inward_line_id,
    paymentModeOptions,
    urls,
}: Props) {
    return (
        <>
            <Head title={`DR for ${lorryReceipt.lr_number}`} />
            <AppIndexPage>
                <AppIndexListCard title={`Delivery Receipt · ${lorryReceipt.lr_number}`}>
                    <Form action={urls.store} method="post" className="space-y-4 p-4">
                        {({ errors, processing }) => (
                            <>
                                <input type="hidden" name="lorry_receipt_id" value={lorryReceipt.id} />
                                {partload_inward_line_id ? (
                                    <input
                                        type="hidden"
                                        name="partload_inward_line_id"
                                        value={partload_inward_line_id}
                                    />
                                ) : null}
                                <div className="grid gap-3 md:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="dr_date">Date</Label>
                                        <Input id="dr_date" name="dr_date" type="date" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="payment_mode" required>
                                            Payment Mode
                                        </Label>
                                        <select
                                            id="payment_mode"
                                            name="payment_mode"
                                            required
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                            defaultValue="due"
                                        >
                                            {paymentModeOptions.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.payment_mode ? (
                                            <p className="text-sm text-destructive">{errors.payment_mode}</p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="freight">Freight</Label>
                                        <Input
                                            id="freight"
                                            name="freight"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            defaultValue={lorryReceipt.freight_total}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="handling_charge">Handling</Label>
                                        <Input id="handling_charge" name="handling_charge" type="number" step="0.01" min="0" defaultValue="0" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="delivery_charge">Delivery</Label>
                                        <Input id="delivery_charge" name="delivery_charge" type="number" step="0.01" min="0" defaultValue="0" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="payment_due_from_party_id">
                                            Payment Due From (Due mode)
                                        </Label>
                                        <Input id="payment_due_from_party_id" name="payment_due_from_party_id" />
                                        {errors.payment_due_from_party_id ? (
                                            <p className="text-sm text-destructive">
                                                {errors.payment_due_from_party_id}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="bank_cash_account_id">
                                            Cash/Bank Account
                                        </Label>
                                        <Input id="bank_cash_account_id" name="bank_cash_account_id" />
                                        {errors.bank_cash_account_id ? (
                                            <p className="text-sm text-destructive">
                                                {errors.bank_cash_account_id}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                                {errors.lorry_receipt_id ? (
                                    <p className="text-sm text-destructive">{errors.lorry_receipt_id}</p>
                                ) : null}
                                <Button type="submit" disabled={processing}>
                                    Post Delivery Receipt
                                </Button>
                            </>
                        )}
                    </Form>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
