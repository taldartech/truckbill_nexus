import { KG_PER_MT, parseNumeric } from '@/lib/operational-weight';

/** Mirrors `FreightCalculator` / `LorryHireGoodsLineBuilder` for the Lorry Hire form preview only. */
export type HireRateBasis = {
    package_count: string | null;
    actual_weight: string | null;
    charged_weight: string | null;
    /** `weight_mt`: rate is per MT; `weight_kg`: rate is per KG (legacy rows). */
    rate_semantics: string;
};

export type HireFinancials = {
    transporterFreight: number;
    customAmountsTotal: number;
    tdsAmount: number | null;
    netPayable: number;
};

function roundMoney(value: number): number {
    return Math.round((value + Number.EPSILON) * 100) / 100;
}

function weightBasis(
    weightKg: string | null,
    semantics: string,
): number | null {
    const kg = parseNumeric(weightKg);
    if (kg === null || kg <= 0) {
        return null;
    }

    return semantics === 'weight_mt' ? kg / KG_PER_MT : kg;
}

/** Transporter freight for one goods line, or null while the inputs are incomplete. */
export function lineTransporterFreight(
    rateType: string,
    rateInput: string,
    basis: HireRateBasis,
): number | null {
    const rate = parseNumeric(rateInput);
    if (rate === null || rate < 0) {
        return null;
    }

    switch (rateType) {
        case 'fixed_amount':
            return roundMoney(rate);
        case 'per_package_unit': {
            const units = parseNumeric(basis.package_count);
            return units !== null && units >= 1
                ? roundMoney(rate * units)
                : null;
        }
        case 'per_actual_weight': {
            const weight = weightBasis(
                basis.actual_weight,
                basis.rate_semantics,
            );
            return weight !== null ? roundMoney(rate * weight) : null;
        }
        case 'per_charged_weight': {
            const weight = weightBasis(
                basis.charged_weight ?? basis.actual_weight,
                basis.rate_semantics,
            );
            return weight !== null ? roundMoney(rate * weight) : null;
        }
        default:
            return null;
    }
}

export function customAmountsTotal(rows: Array<{ amount: string }>): number {
    return roundMoney(
        rows.reduce((sum, row) => {
            const value = parseNumeric(row.amount);
            return value !== null ? sum + value : sum;
        }, 0),
    );
}

/**
 * Single place for Transporter Freight → Custom Amounts → TDS → Net Payable.
 * `lockedTdsAmount` keeps an existing TDS once the submission lock applies (edit only).
 */
export function summarizeHireFinancials(
    lineFreights: Array<number | null>,
    tdsPercent: string | null,
    lockedTdsAmount: number | null = null,
    customAmounts: number = 0,
): HireFinancials {
    const transporterFreight = roundMoney(
        lineFreights.reduce<number>((sum, value) => sum + (value ?? 0), 0),
    );
    const percent = parseNumeric(tdsPercent);
    const tdsAmount =
        lockedTdsAmount !== null
            ? lockedTdsAmount
            : percent !== null
              ? roundMoney((transporterFreight * percent) / 100)
              : null;

    return {
        transporterFreight,
        customAmountsTotal: customAmounts,
        tdsAmount,
        netPayable: roundMoney(
            transporterFreight + customAmounts - (tdsAmount ?? 0),
        ),
    };
}
