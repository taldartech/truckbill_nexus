import { Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatInr } from '@/lib/money';
import { LrfSection } from '@/pages/branch/lorry-receipts/lr-form-sections';

export type CustomAmountDraft = {
    id?: string;
    description: string;
    amount: string;
};

type Props = {
    rows: CustomAmountDraft[];
    total: number;
    formError?: string;
    onAdd: () => void;
    onChange: (index: number, patch: Partial<CustomAmountDraft>) => void;
    onRemove: (index: number) => void;
};

/** Legacy `freight_bookings/edit` custom amounts table (edit workflow only). */
export function CustomAmountsSection({
    rows,
    total,
    formError,
    onAdd,
    onChange,
    onRemove,
}: Props) {
    return (
        <div data-testid="lorry-hire-section-custom-amounts">
            <LrfSection
                step={3}
                title="Custom Amounts"
                description="Positive or negative adjustments that affect balance — not part of TDS basis"
                tone="goods"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">
                        Additional payable (+) or recoverable (−) per legacy
                        freight booking.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onAdd}
                    >
                        Add amount
                    </Button>
                </div>

                <div className="mt-3 overflow-x-auto rounded-lg border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40 text-left text-xs font-semibold text-muted-foreground uppercase">
                            <tr>
                                <th className="px-3 py-2">Description</th>
                                <th className="w-40 px-3 py-2">Amount (+/−)</th>
                                <th className="w-16 px-3 py-2 text-center">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="px-3 py-4 text-center text-xs text-muted-foreground"
                                    >
                                        No custom amounts yet. Click{' '}
                                        <strong>Add amount</strong> to create
                                        one.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row, index) => (
                                    <tr
                                        key={`custom-${index}`}
                                        className="border-t border-border align-top"
                                    >
                                        <td className="px-3 py-2">
                                            <Label
                                                htmlFor={`custom_description_${index}`}
                                                className="sr-only"
                                            >
                                                Particulars
                                            </Label>
                                            <Input
                                                id={`custom_description_${index}`}
                                                value={row.description}
                                                onChange={(event) =>
                                                    onChange(index, {
                                                        description:
                                                            event.target.value,
                                                    })
                                                }
                                                maxLength={255}
                                                placeholder="e.g. Loading charge"
                                                required
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <Label
                                                htmlFor={`custom_amount_row_${index}`}
                                                className="sr-only"
                                            >
                                                Amount
                                            </Label>
                                            <Input
                                                id={`custom_amount_row_${index}`}
                                                type="number"
                                                step="0.01"
                                                className="h-9"
                                                value={row.amount}
                                                onChange={(event) =>
                                                    onChange(index, {
                                                        amount: event.target
                                                            .value,
                                                    })
                                                }
                                                placeholder="0.00"
                                                required
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-center">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                aria-label="Remove custom amount row"
                                                onClick={() => onRemove(index)}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="mt-2 flex justify-end gap-2 text-sm font-semibold tabular-nums">
                    <span className="text-muted-foreground">
                        Custom amounts total
                    </span>
                    <span>{formatInr(total)}</span>
                </div>

                <InputError message={formError} className="mt-2" />
            </LrfSection>
        </div>
    );
}
