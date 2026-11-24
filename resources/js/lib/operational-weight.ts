/** Canonical storage: KG. User-facing operational weights: MT. */

export const KG_PER_MT = 1000;

export function parseNumeric(
    value: string | number | null | undefined,
): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const n = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(n) ? n : null;
}

export function formatFixed(value: number, scale = 3): string {
    return value.toFixed(scale);
}

/** Convert MT (user entry) → KG (canonical storage). */
export function mtToKg(mt: string | number | null | undefined): string {
    const n = parseNumeric(mt);
    if (n === null) {
        return '';
    }
    return formatFixed(n * KG_PER_MT, 3);
}

/** Convert KG (canonical) → MT (presentation). */
export function kgToMt(kg: string | number | null | undefined): string {
    const n = parseNumeric(kg);
    if (n === null) {
        return '';
    }
    return formatFixed(n / KG_PER_MT, 3);
}

export function optionalMtToKg(
    mt: string | number | null | undefined,
): string | null {
    if (mt === null || mt === undefined || mt === '') {
        return null;
    }
    return mtToKg(mt);
}

export function optionalKgToMt(
    kg: string | number | null | undefined,
): string | null {
    if (kg === null || kg === undefined || kg === '') {
        return null;
    }
    return kgToMt(kg);
}

/** Actual Weight (MT) = Units × Wt./Unit (KG) ÷ 1000 */
export function actualMtFromUnitsAndWpuKg(
    units: string | number | null | undefined,
    wpuKg: string | number | null | undefined,
): string {
    const u = parseNumeric(units);
    const w = parseNumeric(wpuKg);
    if (u === null || w === null) {
        return '';
    }
    return formatFixed((u * w) / KG_PER_MT, 3);
}

/** Wt./Unit (KG) = Actual Weight (MT) × 1000 ÷ Units */
export function wpuKgFromActualMt(
    actualMt: string | number | null | undefined,
    units: string | number | null | undefined,
): string {
    const a = parseNumeric(actualMt);
    const u = parseNumeric(units);
    if (a === null || u === null || u === 0) {
        return '';
    }
    return formatFixed((a * KG_PER_MT) / u, 3);
}

export function formatMtLabel(mt: string | number | null | undefined): string {
    const n = parseNumeric(mt);
    if (n === null) {
        return '—';
    }
    return `${formatFixed(n, 3)} MT`;
}
