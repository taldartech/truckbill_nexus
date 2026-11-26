/**
 * Display labels for party roles (governing: truck owner merged into transporter).
 */
export const PARTY_ROLE_LABELS: Partial<Record<string, string>> = {
    transporter: 'Transporter / Truck owner',
    general_vendor: 'General vendor',
    pump_vendor: 'Pump vendor',
};

export function partyRoleDisplayLabel(
    role: string,
    fallbackLabel: string,
): string {
    return PARTY_ROLE_LABELS[role] ?? fallbackLabel;
}
