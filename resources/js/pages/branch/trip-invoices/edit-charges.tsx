import { Form, Head, Link } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useState } from 'react';

type ChargeRow = {
    charge_type: string;
    description: string;
    amount: string;
};

type Props = {
    invoice: {
        id: string;
        invoice_number: string;
        charges: ChargeRow[];
    };
    chargeTypeOptions: Array<{ value: string; label: string }>;
    permissions: { can_edit_charges: boolean; can_view_freight: boolean };
    sampleBanner: string;
};

export default function TripInvoiceEditCharges({
    invoice,
    chargeTypeOptions,
    permissions,
    sampleBanner,
}: Props) {
    const [charges, setCharges] = useState<ChargeRow[]>(
        invoice.charges.length > 0
            ? invoice.charges.map((c) => ({
                  charge_type: c.charge_type,
                  description: c.description,
                  amount: c.amount ?? '',
              }))
            : [
                  {
                      charge_type: chargeTypeOptions[0]?.value ?? 'other',
                      description: '',
                      amount: '',
                  },
              ],
    );

    return (
        <>
            <Head title={`Edit charges — ${invoice.invoice_number}`} />
            <PageShell
                width="form"
                title={`Edit additional charges — ${invoice.invoice_number}`}
                description="Adjust supplemental charge lines before settlement."
            >
                <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {sampleBanner}
                </p>
                {!permissions.can_edit_charges ? (
                    <p className="text-sm text-muted-foreground">
                        Additional charges are locked or not editable.
                    </p>
                ) : (
                    <Form
                        method="patch"
                        action={`/trip-invoices/${invoice.id}/charges`}
                        className="flex w-full min-w-0 flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="edit_remark" required>
                                        Edit reason
                                    </Label>
                                    <textarea
                                        id="edit_remark"
                                        name="edit_remark"
                                        required
                                        rows={3}
                                        className="rounded-md border px-2 py-1 text-sm"
                                        placeholder="Reason for changing additional charges"
                                    />
                                    {errors.edit_remark && (
                                        <span className="text-sm text-destructive">
                                            {errors.edit_remark}
                                        </span>
                                    )}
                                </div>
                                {charges.map((charge, index) => (
                                    <div
                                        key={index}
                                        className="grid gap-3 rounded border p-3 md:grid-cols-3"
                                    >
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor={`charge_type_${index}`}
                                            >
                                                Charge Type
                                            </Label>
                                            <select
                                                id={`charge_type_${index}`}
                                                name={`charges[${index}][charge_type]`}
                                                defaultValue={
                                                    charge.charge_type
                                                }
                                                className="h-9 w-full rounded-md border px-2 py-1 text-sm"
                                            >
                                                {chargeTypeOptions.map(
                                                    (opt) => (
                                                        <option
                                                            key={opt.value}
                                                            value={opt.value}
                                                        >
                                                            {opt.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor={`charge_description_${index}`}
                                                required
                                            >
                                                Description
                                            </Label>
                                            <input
                                                id={`charge_description_${index}`}
                                                name={`charges[${index}][description]`}
                                                placeholder="Description"
                                                defaultValue={
                                                    charge.description
                                                }
                                                required
                                                className="h-9 w-full rounded-md border px-2 py-1 text-sm"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor={`charge_amount_${index}`}
                                                required
                                            >
                                                Amount
                                            </Label>
                                            <input
                                                id={`charge_amount_${index}`}
                                                name={`charges[${index}][amount]`}
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                defaultValue={charge.amount}
                                                required
                                                disabled={
                                                    !permissions.can_view_freight
                                                }
                                                className="h-9 w-full rounded-md border px-2 py-1 text-sm"
                                            />
                                        </div>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setCharges((prev) => [
                                            ...prev,
                                            {
                                                charge_type:
                                                    chargeTypeOptions[0]
                                                        ?.value ?? 'other',
                                                description: '',
                                                amount: '',
                                            },
                                        ])
                                    }
                                >
                                    Add charge
                                </Button>
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Save charges
                                    </Button>
                                    <Button asChild variant="ghost">
                                        <Link
                                            href={`/trip-invoices/${invoice.id}`}
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </PageShell>
        </>
    );
}
