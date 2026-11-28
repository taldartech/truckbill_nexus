const STANDARD = /^[A-Z]{2}[0-9]{1,2}[A-Z]{1,3}[0-9]{4}$/;

const BHARAT = /^[0-9]{2}BH[0-9]{4}[A-Z]{2}$/;

/** Uppercase and remove spaces / hyphens for validation and storage. */
export function normalizeVehicleRegistration(value: string): string {
    return value
        .trim()
        .toUpperCase()
        .replace(/[\s-]+/g, '');
}

export function isValidVehicleRegistration(value: string): boolean {
    const normalized = normalizeVehicleRegistration(value);
    if (normalized === '') {
        return false;
    }

    return STANDARD.test(normalized) || BHARAT.test(normalized);
}

export const vehicleRegistrationInvalidMessage =
    'Enter a valid Indian vehicle registration number (for example MH12AB1234 or 21BH1234AA).';
