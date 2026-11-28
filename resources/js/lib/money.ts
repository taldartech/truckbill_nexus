const inrFormatter = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const inrWholeFormatter = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

/** Rupees for validation messages: whole amounts omit paise (₹10,000), matching server messages. */
export function formatInrPlain(value: string | number): string {
    const amount = Number(value);

    return Number.isNaN(amount)
        ? String(value)
        : inrWholeFormatter.format(amount);
}

/** Display a server-computed decimal string as Indian Rupees (presentation only). */
export function formatInr(value: string | number | null | undefined): string {
    const amount = Number(value ?? 0);

    return Number.isNaN(amount) ? String(value) : inrFormatter.format(amount);
}

/** Presentation-only percent (server retains full calculation precision). */
export function formatPercent(
    value: string | number | null | undefined,
    fractionDigits = 2,
): string {
    const amount = Number(value ?? 0);

    return Number.isNaN(amount) ? '—' : `${amount.toFixed(fractionDigits)}%`;
}
