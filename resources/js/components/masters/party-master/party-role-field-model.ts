export const CUSTOMER_FAMILY = new Set(['customer', 'consignor', 'consignee']);

export const VENDOR_ROLES = new Set(['general_vendor', 'pump_vendor']);

export type PartyFieldModel = {
    needsCustomerFamily: boolean;
    needsTransporter: boolean;
    needsDriver: boolean;
    needsVendor: boolean;
    driverOnly: boolean;
    contactNumberRequired: boolean;
    stateCityRequired: boolean;
    contactPersonRequired: boolean;
    showBusinessContact: boolean;
    showGstBusinessTax: boolean;
    /** Party-level PAN in business section (transporters use the TDS panel). */
    showPartyPanField: boolean;
    partyPanRequired: boolean;
};

export function derivePartyFieldModel(
    selectedRoles: string[],
): PartyFieldModel {
    const needsCustomerFamily = selectedRoles.some((role) =>
        CUSTOMER_FAMILY.has(role),
    );
    const needsTransporter = selectedRoles.includes('transporter');
    const needsDriver = selectedRoles.includes('driver');
    const needsVendor = selectedRoles.some((role) => VENDOR_ROLES.has(role));
    const driverOnly =
        needsDriver &&
        !needsCustomerFamily &&
        !needsTransporter &&
        !needsVendor;

    return {
        needsCustomerFamily,
        needsTransporter,
        needsDriver,
        needsVendor,
        driverOnly,
        contactNumberRequired:
            needsCustomerFamily ||
            needsTransporter ||
            needsDriver ||
            needsVendor,
        stateCityRequired: needsCustomerFamily || needsTransporter,
        contactPersonRequired: needsVendor,
        showBusinessContact:
            needsCustomerFamily || needsTransporter || needsVendor,
        showGstBusinessTax: needsCustomerFamily || needsVendor,
        showPartyPanField:
            (needsCustomerFamily || needsVendor) && !needsTransporter,
        partyPanRequired: needsTransporter,
    };
}
