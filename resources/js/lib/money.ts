const inrFormatter = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/** Display a server-computed decimal string as Indian Rupees (presentation only). */
export function formatInr(value: string | number | null | undefined): string {
    const amount = Number(value ?? 0);

    return Number.isNaN(amount) ? String(value) : inrFormatter.format(amount);
}
