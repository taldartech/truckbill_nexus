import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMtLabel, kgToMt, parseNumeric } from '@/lib/operational-weight';

export type FixedAdjustmentsDraft = {
    detention: string;
    deviation: string;
    misc_expense: string;
    unloading_weight: string;
    shortage: string;
    damage: string;
};

type Props = {
    values: FixedAdjustmentsDraft;
    formErrors: Record<string, string | undefined>;
    chargedWeightKg: string | null;
    onChange: (patch: Partial<FixedAdjustmentsDraft>) => void;
};

const EMPTY: FixedAdjustmentsDraft = {
    detention: '',
    deviation: '',
    misc_expense: '',
    unloading_weight: '',
    shortage: '',
    damage: '',
};

export function emptyFixedAdjustments(): FixedAdjustmentsDraft {
    return { ...EMPTY };
}

function errorFor(
    errors: Record<string, string | undefined>,
    key: keyof FixedAdjustmentsDraft,
): string | undefined {
    return errors[`fixed_adjustments.${key}`];
}

/** Legacy `freight_bookings/edit` additional charges (`lrf-grid--6`). */
export function FixedAdjustmentsSection({
    values,
    formErrors,
    chargedWeightKg,
    onChange,
}: Props) {
    const kg = parseNumeric(chargedWeightKg);
    const chargedMt = kg !== null ? formatMtLabel(kgToMt(kg)) : '—';

    return (
        <section
            className="lrf-section"
            data-testid="lorry-hire-section-fixed-adjustments"
        >
            <div className="lrf-section__head lrf-section__head--plain">
                <div className="lrf-section__head-left">
                    <div>
                        <h2 className="lrf-section__title">
                            Additional Charges
                        </h2>
                        <p className="lrf-section__desc">
                            One value per type; affects Net Payable
                        </p>
                    </div>
                </div>
            </div>
            <div className="lrf-section__body">
                <div className="lrf-grid lrf-grid--6">
                    <div className="lrf-pf">
                        <Label htmlFor="adj_detention" className="lrf-pf__lbl">
                            Detention
                        </Label>
                        <Input
                            id="adj_detention"
                            type="number"
                            min="0"
                            step="0.01"
                            className="h-9"
                            value={values.detention}
                            onChange={(event) =>
                                onChange({ detention: event.target.value })
                            }
                        />
                        <p className="lrf-pf__hint">₹ payable to transporter</p>
                        <InputError
                            message={errorFor(formErrors, 'detention')}
                        />
                    </div>
                    <div className="lrf-pf">
                        <Label htmlFor="adj_deviation" className="lrf-pf__lbl">
                            Deviation
                        </Label>
                        <Input
                            id="adj_deviation"
                            type="number"
                            min="0"
                            step="0.01"
                            className="h-9"
                            value={values.deviation}
                            onChange={(event) =>
                                onChange({ deviation: event.target.value })
                            }
                        />
                        <p className="lrf-pf__hint">₹ payable to transporter</p>
                        <InputError
                            message={errorFor(formErrors, 'deviation')}
                        />
                    </div>
                    <div className="lrf-pf">
                        <Label
                            htmlFor="adj_misc_expense"
                            className="lrf-pf__lbl"
                        >
                            Misc. Expense
                        </Label>
                        <Input
                            id="adj_misc_expense"
                            type="number"
                            min="0"
                            step="0.01"
                            className="h-9"
                            value={values.misc_expense}
                            onChange={(event) =>
                                onChange({ misc_expense: event.target.value })
                            }
                        />
                        <p className="lrf-pf__hint">₹ recoverable</p>
                        <InputError
                            message={errorFor(formErrors, 'misc_expense')}
                        />
                    </div>
                    <div className="lrf-pf">
                        <Label
                            htmlFor="adj_unloading_weight"
                            className="lrf-pf__lbl"
                        >
                            Unloading Wt.
                        </Label>
                        <Input
                            id="adj_unloading_weight"
                            type="number"
                            min="0"
                            step="0.001"
                            className="h-9"
                            value={values.unloading_weight}
                            onChange={(event) =>
                                onChange({
                                    unloading_weight: event.target.value,
                                })
                            }
                        />
                        <p className="lrf-pf__hint">MT (charged {chargedMt})</p>
                        <InputError
                            message={errorFor(formErrors, 'unloading_weight')}
                        />
                    </div>
                    <div className="lrf-pf">
                        <Label htmlFor="adj_shortage" className="lrf-pf__lbl">
                            Shortage Amt.
                        </Label>
                        <Input
                            id="adj_shortage"
                            type="number"
                            min="0"
                            step="0.01"
                            className="h-9"
                            value={values.shortage}
                            onChange={(event) =>
                                onChange({ shortage: event.target.value })
                            }
                        />
                        <p className="lrf-pf__hint">₹ recoverable</p>
                        <InputError
                            message={errorFor(formErrors, 'shortage')}
                        />
                    </div>
                    <div className="lrf-pf">
                        <Label htmlFor="adj_damage" className="lrf-pf__lbl">
                            Damage Amt.
                        </Label>
                        <Input
                            id="adj_damage"
                            type="number"
                            min="0"
                            step="0.01"
                            className="h-9"
                            value={values.damage}
                            onChange={(event) =>
                                onChange({ damage: event.target.value })
                            }
                        />
                        <p className="lrf-pf__hint">₹ recoverable</p>
                        <InputError message={errorFor(formErrors, 'damage')} />
                    </div>
                </div>
            </div>
        </section>
    );
}
