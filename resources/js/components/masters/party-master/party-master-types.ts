export type RolePayload = {
    id?: string;
    role: string;
    attributes: Record<string, unknown>;
    is_active?: boolean;
};

export type PanStatusValue = '' | 'active' | 'inactive';

/** TDS declaration received: '' until the user answers Yes ('1') or No ('0'). */
export type DeclarationValue = '' | '1' | '0';

export type TransporterAttrs = {
    pan_status: PanStatusValue;
    declaration: DeclarationValue;
    tds_percentage: string;
    tds_override: boolean;
    tds_adjustment_remark: string;
    goods_vehicles_count: string;
};

export type DriverAttrs = {
    driver_type: 'permanent' | 'market';
    license_number: string;
    license_expiry: string;
    joining_date: string;
    aadhaar: string;
    pan: string;
    emergency_contact_name: string;
    emergency_contact_mobile: string;
    monthly_payment_base: string;
};

import type { PartyFieldModel } from '@/components/masters/party-master/party-role-field-model';

export type PartyBasicValues = {
    name: string;
    short_name: string;
    contact_person: string;
    contact_number: string;
    email: string;
    address: string;
    gstin: string;
    pan: string;
    is_gst_applicable: '' | '0' | '1';
    bank_name: string;
    bank_account_holder_name: string;
    /** Edit mode leaves this blank to keep the stored (masked) account number. */
    bank_account_number: string;
    bank_ifsc: string;
    bank_short_name: string;
    bank_beneficiary_id: string;
};

export const EMPTY_PARTY_BASIC: PartyBasicValues = {
    name: '',
    short_name: '',
    contact_person: '',
    contact_number: '',
    email: '',
    address: '',
    gstin: '',
    pan: '',
    is_gst_applicable: '',
    bank_name: '',
    bank_account_holder_name: '',
    bank_account_number: '',
    bank_ifsc: '',
    bank_short_name: '',
    bank_beneficiary_id: '',
};

export const PARTY_BANK_FIELDS = [
    ['bank_name', 'Bank Name'],
    ['bank_account_holder_name', 'Account Holder Name'],
    ['bank_account_number', 'Account Number'],
    ['bank_ifsc', 'IFSC Code'],
    ['bank_short_name', 'Bank Short Name'],
    ['bank_beneficiary_id', 'Beneficiary ID'],
] as const satisfies ReadonlyArray<readonly [keyof PartyBasicValues, string]>;

/**
 * Short name follows the name until the user customizes it (Rules §25).
 */
export function nameChangePatch(
    current: PartyBasicValues,
    nextName: string,
): Partial<PartyBasicValues> {
    const follows =
        current.short_name === '' || current.short_name === current.name;

    return follows
        ? { name: nextName, short_name: nextName }
        : { name: nextName };
}

export function attrString(
    attrs: Record<string, unknown> | undefined,
    key: string,
    fallback = '',
): string {
    const value = attrs?.[key];
    if (value === null || value === undefined) {
        return fallback;
    }
    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        return String(value);
    }

    return fallback;
}

export function attrBool(
    attrs: Record<string, unknown> | undefined,
    key: string,
    fallback = false,
): boolean {
    const value = attrs?.[key];
    if (typeof value === 'boolean') {
        return value;
    }
    if (typeof value === 'string') {
        const lower = value.toLowerCase();
        if (['1', 'true', 'yes', 'on'].includes(lower)) {
            return true;
        }
        if (['0', 'false', 'no', 'off', ''].includes(lower)) {
            return false;
        }

        return value.trim() !== '';
    }

    return fallback;
}

export function initialTransporter(
    roles: RolePayload[] | undefined,
): TransporterAttrs {
    const attrs = roles?.find(
        (role) => role.role === 'transporter',
    )?.attributes;

    const panStatus = attrString(attrs, 'pan_status');
    const hasDeclaration =
        attrs !== undefined &&
        attrs.declaration !== undefined &&
        attrs.declaration !== null;

    return {
        pan_status:
            panStatus === 'active' || panStatus === 'inactive' ? panStatus : '',
        declaration: hasDeclaration
            ? attrBool(attrs, 'declaration')
                ? '1'
                : '0'
            : '',
        tds_percentage: attrString(attrs, 'tds_percentage'),
        tds_override: attrBool(attrs, 'tds_override', false),
        tds_adjustment_remark: attrString(attrs, 'tds_adjustment_remark'),
        goods_vehicles_count: attrString(attrs, 'goods_vehicles_count'),
    };
}

export function initialDriver(roles: RolePayload[] | undefined): DriverAttrs {
    const attrs = roles?.find((role) => role.role === 'driver')?.attributes;
    const type = attrString(attrs, 'driver_type', 'permanent');

    return {
        driver_type: type === 'market' ? 'market' : 'permanent',
        license_number: attrString(attrs, 'license_number'),
        license_expiry: attrString(attrs, 'license_expiry'),
        joining_date: attrString(attrs, 'joining_date'),
        aadhaar: attrString(attrs, 'aadhaar'),
        pan: attrString(attrs, 'pan'),
        emergency_contact_name: attrString(attrs, 'emergency_contact_name'),
        emergency_contact_mobile: attrString(attrs, 'emergency_contact_mobile'),
        monthly_payment_base: attrString(attrs, 'monthly_payment_base'),
    };
}

export function roleIndexMap(selectedRoles: string[]): Record<string, number> {
    const map: Record<string, number> = {};
    selectedRoles.forEach((role, index) => {
        map[role] = index;
    });

    return map;
}

export function buildRolesPayload(
    selectedRoles: string[],
    transporter: TransporterAttrs,
    driver: DriverAttrs,
    partyPan: string,
): Array<{ role: string; attributes: Record<string, unknown> }> {
    return selectedRoles.map((role) => {
        if (role === 'transporter') {
            return {
                role,
                attributes: {
                    pan_status: transporter.pan_status,
                    ...(transporter.declaration !== ''
                        ? { declaration: transporter.declaration === '1' }
                        : {}),
                    ...(transporter.tds_percentage
                        ? { tds_percentage: transporter.tds_percentage }
                        : {}),
                    tds_override: transporter.tds_override,
                    ...(transporter.tds_adjustment_remark
                        ? {
                              tds_adjustment_remark:
                                  transporter.tds_adjustment_remark,
                          }
                        : {}),
                    ...(transporter.goods_vehicles_count
                        ? {
                              goods_vehicles_count:
                                  transporter.goods_vehicles_count,
                          }
                        : {}),
                    ...(partyPan ? { pan: partyPan } : {}),
                },
            };
        }

        if (role === 'driver') {
            return {
                role,
                attributes: {
                    driver_type: driver.driver_type,
                    ...(driver.license_number
                        ? { license_number: driver.license_number }
                        : {}),
                    ...(driver.license_expiry
                        ? { license_expiry: driver.license_expiry }
                        : {}),
                    ...(driver.driver_type === 'permanent'
                        ? {
                              ...(driver.joining_date
                                  ? { joining_date: driver.joining_date }
                                  : {}),
                              ...(driver.aadhaar
                                  ? { aadhaar: driver.aadhaar }
                                  : {}),
                              ...(driver.pan ? { pan: driver.pan } : {}),
                              ...(driver.emergency_contact_name
                                  ? {
                                        emergency_contact_name:
                                            driver.emergency_contact_name,
                                    }
                                  : {}),
                              ...(driver.emergency_contact_mobile
                                  ? {
                                        emergency_contact_mobile:
                                            driver.emergency_contact_mobile,
                                    }
                                  : {}),
                              ...(driver.monthly_payment_base
                                  ? {
                                        monthly_payment_base:
                                            driver.monthly_payment_base,
                                    }
                                  : {}),
                          }
                        : {}),
                },
            };
        }

        return { role, attributes: {} as Record<string, unknown> };
    });
}

export type PartyGeographyValues = {
    state: string;
    city: string;
    state_id: string;
    city_id: string;
};

/**
 * Authoritative JSON payload for party store (quick create and API clients).
 */
export function buildPartyStorePayload(
    selectedRoles: string[],
    basic: PartyBasicValues,
    transporter: TransporterAttrs,
    driver: DriverAttrs,
    pan: string,
    geography: PartyGeographyValues,
    fieldModel: PartyFieldModel,
): Record<string, unknown> {
    const roles = buildRolesPayload(selectedRoles, transporter, driver, pan);

    const payload: Record<string, unknown> = {
        name: basic.name.trim(),
        short_name: basic.short_name.trim() || basic.name.trim(),
        contact_number: basic.contact_number.trim(),
        email: basic.email.trim() || null,
        state: geography.state.trim(),
        city: geography.city.trim(),
        state_id: geography.state_id.trim() || null,
        city_id: geography.city_id.trim() || null,
        address: basic.address.trim() || null,
        roles,
    };

    const contactPerson = basic.contact_person.trim();
    if (contactPerson !== '') {
        payload.contact_person = contactPerson;
    }

    if (fieldModel.showBankDetails) {
        for (const [key] of PARTY_BANK_FIELDS) {
            const value = basic[key].trim();
            if (value !== '') {
                payload[key] = value;
            }
        }
    }

    if (fieldModel.showGstBusinessTax) {
        payload.gstin = basic.gstin.trim() || null;
        if (basic.is_gst_applicable !== '') {
            payload.is_gst_applicable = basic.is_gst_applicable === '1';
        }
    }

    if (fieldModel.needsTransporter || pan.trim() !== '') {
        payload.pan = pan.trim() || null;
    } else if (fieldModel.showPartyPanField && basic.pan.trim() !== '') {
        payload.pan = basic.pan.trim().toUpperCase();
    }

    return payload;
}
