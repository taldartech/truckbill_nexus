import type {
    ChangeReview,
    FieldReviewSection,
} from '@/components/form-confirmation/types';
import { derivePartyFieldModel } from '@/components/masters/party-master/party-role-field-model';
import {
    PARTY_BANK_FIELDS,
    type DriverAttrs,
    type PartyBasicValues,
    type TransporterAttrs,
} from '@/components/masters/party-master/party-master-types';

export type PartyReviewInput = {
    selectedRoles: string[];
    roleLabel: (role: string) => string;
    basic: PartyBasicValues;
    state: string;
    city: string;
    pan: string;
    transporter: TransporterAttrs;
    driver: DriverAttrs;
    /** Masked stored account number shown when edit leaves the number blank. */
    maskedAccountNumber?: string | null;
};

const ROLES_SECTION = 'Selected Roles';

function yesNo(value: string): string {
    return value === '1' ? 'Yes' : value === '0' ? 'No' : '';
}

function maskAccount(number: string): string {
    const trimmed = number.trim();
    return trimmed.length <= 4
        ? '*'.repeat(trimmed.length)
        : '*'.repeat(trimmed.length - 4) + trimmed.slice(-4);
}

function maskAadhaar(value: string): string {
    const digits = value.replace(/\D/g, '');
    return digits.length >= 4 ? `XXXX XXXX ${digits.slice(-4)}` : '';
}

function titleCase(value: string): string {
    return value === '' ? '' : value.charAt(0).toUpperCase() + value.slice(1);
}

/**
 * Business-readable Party values grouped by section, limited to the selected roles.
 * Empty values are kept so edit diffs can show cleared fields; create review drops them.
 */
function partyReviewSections(input: PartyReviewInput): FieldReviewSection[] {
    const { basic, transporter, driver } = input;
    const model = derivePartyFieldModel(input.selectedRoles);
    const sections: FieldReviewSection[] = [
        {
            title: 'General Information',
            fields: [
                { label: 'Party Name', value: basic.name.trim() },
                {
                    label: 'Party Short Name',
                    value: basic.short_name.trim() || basic.name.trim(),
                },
            ],
        },
        {
            title: ROLES_SECTION,
            fields: [
                {
                    label: 'Roles',
                    value: input.selectedRoles.map(input.roleLabel).join(', '),
                },
            ],
        },
        {
            title: 'Contact Information',
            fields: [
                ...(model.showBusinessContact
                    ? [
                          {
                              label: 'Contact Person',
                              value: basic.contact_person.trim(),
                          },
                      ]
                    : []),
                {
                    label: model.driverOnly
                        ? 'Mobile Number'
                        : 'Contact Number',
                    value: basic.contact_number.trim(),
                },
                ...(model.showBusinessContact
                    ? [{ label: 'Email', value: basic.email.trim() }]
                    : []),
            ],
        },
        {
            title: 'Address',
            fields: [
                { label: 'State', value: input.state.trim() },
                { label: 'City', value: input.city.trim() },
                { label: 'Address', value: basic.address.trim() },
            ],
        },
    ];

    if (model.showGstBusinessTax || model.needsTransporter) {
        const pan = model.needsTransporter ? input.pan : basic.pan;
        sections.push({
            title: 'Business and Tax Information',
            fields: [
                ...(model.showPartyPanField || model.needsTransporter
                    ? [{ label: 'PAN', value: pan.trim().toUpperCase() }]
                    : []),
                ...(model.showGstBusinessTax
                    ? [
                          { label: 'GSTIN', value: basic.gstin.trim() },
                          {
                              label: 'GST Applicable',
                              value:
                                  yesNo(basic.is_gst_applicable) ||
                                  'Not specified',
                          },
                      ]
                    : []),
                ...(model.needsTransporter
                    ? [
                          {
                              label: 'PAN Status',
                              value: titleCase(transporter.pan_status),
                          },
                          {
                              label: 'TDS Declaration Received',
                              value: yesNo(transporter.declaration),
                          },
                          {
                              label: 'Goods Vehicle Count',
                              value: transporter.goods_vehicles_count.trim(),
                          },
                          ...(transporter.tds_override
                              ? [
                                    {
                                        label: 'TDS Override %',
                                        value: transporter.tds_percentage.trim(),
                                    },
                                    {
                                        label: 'TDS Adjustment Remark',
                                        value: transporter.tds_adjustment_remark.trim(),
                                    },
                                ]
                              : []),
                      ]
                    : []),
            ],
        });
    }

    if (model.needsDriver) {
        const permanent = driver.driver_type === 'permanent';
        sections.push({
            title: 'Driver Information',
            fields: [
                { label: 'Driver Type', value: titleCase(driver.driver_type) },
                {
                    label: 'Licence Number',
                    value: driver.license_number.trim(),
                },
                { label: 'Licence Expiry', value: driver.license_expiry },
                ...(permanent
                    ? [
                          { label: 'Joining Date', value: driver.joining_date },
                          {
                              label: 'Aadhaar',
                              value: maskAadhaar(driver.aadhaar),
                          },
                          { label: 'Driver PAN', value: driver.pan.trim() },
                          {
                              label: 'Emergency Contact',
                              value: driver.emergency_contact_name.trim(),
                          },
                          {
                              label: 'Emergency Contact Mobile',
                              value: driver.emergency_contact_mobile.trim(),
                          },
                          {
                              label: 'Monthly Payment Base',
                              value: driver.monthly_payment_base.trim(),
                          },
                      ]
                    : []),
            ],
        });
    }

    if (model.showBankDetails) {
        sections.push({
            title: 'Bank Details',
            fields: PARTY_BANK_FIELDS.map(([key, label]) => ({
                label,
                value:
                    key === 'bank_account_number'
                        ? basic.bank_account_number.trim() !== ''
                            ? maskAccount(basic.bank_account_number)
                            : (input.maskedAccountNumber ?? '')
                        : basic[key].trim(),
            })),
        });
    }

    return sections;
}

/**
 * Create confirmation: entered business values only, without empty fields or sections.
 */
export function buildPartyCreateReview(
    input: PartyReviewInput,
): FieldReviewSection[] {
    return partyReviewSections(input)
        .map((section) => ({
            ...section,
            fields: (section.fields ?? []).filter(
                (field) => field.value !== '',
            ),
        }))
        .filter((section) => (section.fields ?? []).length > 0);
}

/**
 * Edit confirmation: changed fields with previous and new values, plus added/removed roles.
 */
export function buildPartyEditReview(
    original: PartyReviewInput,
    next: PartyReviewInput,
): ChangeReview[] {
    const changes: ChangeReview[] = [];

    for (const role of next.selectedRoles) {
        if (!original.selectedRoles.includes(role)) {
            changes.push({
                group: ROLES_SECTION,
                label: next.roleLabel(role),
                previous: '—',
                next: 'Role added',
                changeType: 'added',
            });
        }
    }
    for (const role of original.selectedRoles) {
        if (!next.selectedRoles.includes(role)) {
            changes.push({
                group: ROLES_SECTION,
                label: original.roleLabel(role),
                previous: 'Role active',
                next: '—',
                changeType: 'removed',
            });
        }
    }

    const previousValues = new Map<string, string>();
    for (const section of partyReviewSections(original)) {
        for (const field of section.fields ?? []) {
            previousValues.set(`${section.title}|${field.label}`, field.value);
        }
    }

    for (const section of partyReviewSections(next)) {
        if (section.title === ROLES_SECTION) {
            continue;
        }
        for (const field of section.fields ?? []) {
            const previous =
                previousValues.get(`${section.title}|${field.label}`) ?? '';
            if (previous === field.value) {
                continue;
            }
            changes.push({
                group: section.title,
                label: field.label,
                previous: previous || '—',
                next: field.value || '—',
                changeType:
                    previous === ''
                        ? 'added'
                        : field.value === ''
                          ? 'removed'
                          : 'modified',
            });
        }
    }

    return changes;
}
