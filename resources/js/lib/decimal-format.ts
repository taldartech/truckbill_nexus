import { formatInr } from '@/lib/money';
import { parseNumeric } from '@/lib/operational-weight';

/** Presentation-only two-decimal quantity (e.g. units). */
export function formatQuantity2(
    value: string | number | null | undefined,
): string {
    const n = parseNumeric(value);
    if (n === null) {
        return '—';
    }

    return n.toFixed(2);
}

/** Presentation-only weight in MT with two decimals. */
export function formatWeightMt2(
    value: string | number | null | undefined,
): string {
    const n = parseNumeric(value);
    if (n === null) {
        return '—';
    }

    return `${n.toFixed(2)} MT`;
}

/** Presentation-only rate / money-like decimal before currency symbol. */
export function formatRate2(value: string | number | null | undefined): string {
    const n = parseNumeric(value);
    if (n === null) {
        return '—';
    }

    return formatInr(n);
}

/** Format a kg canonical weight column as MT with two decimals. */
export function formatKgAsMt2(kg: string | number | null | undefined): string {
    const n = parseNumeric(kg);
    if (n === null) {
        return '—';
    }

    return `${(n / 1000).toFixed(2)} MT`;
}
