import type { ChangeReview } from './types';

export type ScalarFieldDef<T extends Record<string, unknown>> = {
    key: keyof T & string;
    label: string;
    format?: (value: unknown) => string;
};

export function normalizeComparable(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }
    if (Array.isArray(value)) {
        return value.map((item) => normalizeComparable(item)).join(', ');
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }
    if (typeof value === 'string') {
        return value.trim();
    }
    if (typeof value === 'number' || typeof value === 'bigint') {
        return value.toString();
    }
    if (typeof value === 'symbol') {
        return value.toString();
    }

    return '';
}

export function diffScalarFields<T extends Record<string, unknown>>(
    original: T,
    draft: T,
    fieldDefs: ScalarFieldDef<T>[],
): ChangeReview[] {
    const changes: ChangeReview[] = [];

    for (const field of fieldDefs) {
        const previousRaw = original[field.key];
        const nextRaw = draft[field.key];
        const previous = field.format
            ? field.format(previousRaw)
            : normalizeComparable(previousRaw);
        const next = field.format
            ? field.format(nextRaw)
            : normalizeComparable(nextRaw);

        if (previous === next) {
            continue;
        }

        if (previous === '' && next !== '') {
            changes.push({
                label: field.label,
                previous: '—',
                next,
                changeType: 'added',
            });
            continue;
        }

        if (previous !== '' && next === '') {
            changes.push({
                label: field.label,
                previous,
                next: '—',
                changeType: 'removed',
            });
            continue;
        }

        changes.push({
            label: field.label,
            previous,
            next,
            changeType: 'modified',
        });
    }

    return changes;
}

export function summarizeChanges(changes: ChangeReview[]): string {
    if (changes.length === 0) {
        return 'No field changes detected.';
    }

    const counts = changes.reduce(
        (accumulator, change) => {
            accumulator[change.changeType] += 1;
            return accumulator;
        },
        { modified: 0, added: 0, removed: 0 },
    );

    const parts: string[] = [];
    if (counts.modified > 0) {
        parts.push(`${counts.modified} modified`);
    }
    if (counts.added > 0) {
        parts.push(`${counts.added} added`);
    }
    if (counts.removed > 0) {
        parts.push(`${counts.removed} removed`);
    }

    return parts.join(', ');
}
