/**
 * Centralized user-facing date formatting (PO: DD-MM-YYYY).
 * Persist UTC / ISO on the server; format only for display.
 * Date-only values must not be shifted by timezone conversion.
 */

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

export type DateInput = string | Date | null | undefined;

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * Format a calendar date (YYYY-MM-DD or ISO date prefix) as DD-MM-YYYY
 * without applying timezone shifts.
 */
export function formatDisplayDate(value: DateInput): string {
    if (value == null || value === '') {
        return '—';
    }

    if (typeof value === 'string') {
        const dateOnly = value.slice(0, 10);
        const match = DATE_ONLY.exec(dateOnly);
        if (match) {
            const [, year, month, day] = match;
            return `${day}-${month}-${year}`;
        }
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return `${pad(date.getUTCDate())}-${pad(date.getUTCMonth() + 1)}-${date.getUTCFullYear()}`;
}

/**
 * Format an instant in the given IANA timezone as DD-MM-YYYY hh:mm A.
 * Defaults to Asia/Kolkata.
 */
export function formatDisplayDateTime(
    value: DateInput,
    timeZone = 'Asia/Kolkata',
): string {
    if (value == null || value === '') {
        return '—';
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    }).formatToParts(date);

    const get = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    const day = get('day');
    const month = get('month');
    const year = get('year');
    const hour = get('hour');
    const minute = get('minute');
    const dayPeriod = get('dayPeriod').toUpperCase();

    return `${day}-${month}-${year} ${hour}:${minute} ${dayPeriod}`;
}

/**
 * Prefer date-only formatting when the value has no meaningful time
 * (midnight UTC or date-only string); otherwise date-time.
 */
export function formatDisplayDateSmart(
    value: DateInput,
    timeZone = 'Asia/Kolkata',
): string {
    if (value == null || value === '') {
        return '—';
    }

    if (
        typeof value === 'string' &&
        DATE_ONLY.test(value.slice(0, 10)) &&
        !value.includes('T')
    ) {
        return formatDisplayDate(value);
    }

    if (
        typeof value === 'string' &&
        /T00:00:00(\.0+)?(Z|[+-]00:00)?$/.test(value)
    ) {
        return formatDisplayDate(value);
    }

    return formatDisplayDateTime(value, timeZone);
}

/**
 * Convert DD-MM-YYYY user input to YYYY-MM-DD for server payloads.
 */
export function parseDisplayDateToIso(value: string): string | null {
    const match = /^(\d{2})-(\d{2})-(\d{4})$/.exec(value.trim());
    if (!match) {
        return null;
    }
    const [, day, month, year] = match;
    const iso = `${year}-${month}-${day}`;
    const probe = new Date(`${iso}T00:00:00Z`);
    if (
        Number.isNaN(probe.getTime()) ||
        probe.getUTCFullYear() !== Number(year) ||
        probe.getUTCMonth() + 1 !== Number(month) ||
        probe.getUTCDate() !== Number(day)
    ) {
        return null;
    }
    return iso;
}
