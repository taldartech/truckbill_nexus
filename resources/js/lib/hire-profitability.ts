export type HireProfitability = {
    customerFreight: number;
    transporterNetPayable: number;
    estimatedProfit: number;
    marginPercent: number | null;
};

/**
 * Estimated freight profitability: customer freight minus transporter net payable.
 * TDS withheld from the transporter is not treated as profit.
 */
export function estimateHireProfitability(
    customerFreight: number | null,
    transporterNetPayable: number | null,
): HireProfitability | null {
    if (customerFreight === null || transporterNetPayable === null) {
        return null;
    }

    const estimatedProfit = customerFreight - transporterNetPayable;
    const marginPercent =
        customerFreight > 0.01
            ? (estimatedProfit / customerFreight) * 100
            : null;

    return {
        customerFreight,
        transporterNetPayable,
        estimatedProfit,
        marginPercent,
    };
}
