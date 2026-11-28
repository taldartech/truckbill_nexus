import { Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatInr } from '@/lib/money';

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

/** Legacy `freight_bookings/edit` custom amounts (`lrf-custom-table`). */
export function CustomAmountsSection({
    rows,
    total,
    formError,
    onAdd,
    onChange,
    onRemove,
}: Props) {
    return (
        <section
            className="lrf-section"
            id="custom-amounts-section"
            data-testid="lorry-hire-section-custom-amounts"
        >
            <div className="lrf-section__head lrf-section__head--plain">
                <div className="lrf-section__head-left">
                    <div>
                        <h2 className="lrf-section__title">Custom Amounts</h2>
                        <p className="lrf-section__desc">
                            Positive or negative adjustments that affect balance
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    size="sm"
                    className="lrf-btn lrf-btn--brand"
                    onClick={onAdd}
                >
                    Add amount
                </Button>
            </div>
            <div className="lrf-section__body lrf-section__body--flush">
                <div className="lrf-custom-table-wrap">
                    <table className="lrf-custom-table">
                        <thead>
                            <tr>
                                <th className="lrf-custom-table__desc">
                                    Description
                                </th>
                                <th className="lrf-custom-table__amt">
                                    Amount (+/−)
                                </th>
                                <th className="lrf-custom-table__act">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => (
                                <tr key={`custom-${index}`}>
                                    <td>
                                        <Label
                                            htmlFor={`custom_description_${index}`}
                                            className="sr-only"
                                        >
                                            Particulars
                                        </Label>
                                        <Input
                                            id={`custom_description_${index}`}
                                            className="form-control"
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
                                    <td>
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
                                            className="form-control"
                                            value={row.amount}
                                            onChange={(event) =>
                                                onChange(index, {
                                                    amount: event.target.value,
                                                })
                                            }
                                            placeholder="0.00"
                                            required
                                        />
                                    </td>
                                    <td className="lrf-custom-table__act-cell">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="lrf-custom-remove size-8"
                                            aria-label="Remove custom amount row"
                                            onClick={() => onRemove(index)}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 ? (
                        <div
                            className="lrf-custom-empty"
                            id="custom-amounts-empty"
                        >
                            <span>
                                No custom amounts yet. Click{' '}
                                <strong>Add amount</strong> to create one.
                            </span>
                        </div>
                    ) : null}
                </div>
                <div className="lrf-custom-total">
                    <span>Custom amounts total</span>
                    <strong>{formatInr(total)}</strong>
                </div>
                <InputError message={formError} className="px-3 pb-3" />
            </div>
        </section>
    );
}
