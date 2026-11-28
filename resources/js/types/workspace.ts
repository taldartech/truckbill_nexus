export type WorkspaceCompany = {
    id: string;
    name: string;
    status: string;
    relationship: 'Owner' | 'Member';
    is_owner: boolean;
};

export type WorkspaceBranch = {
    id: string;
    name: string;
    status: string;
};

export type WorkspaceCapabilities = {
    lifecycle_status: string;
    mutations_allowed: boolean;
    is_read_only_grace_or_restricted: boolean;
    active_modules: string[];
    historical_modules: string[];
    allowed_vehicle_ownerships: string[];
    allowed_party_roles: string[];
    ownfleet_drivers_enabled: boolean;
};

export type WorkspaceNavigation = {
    bilty_register: boolean;
    create_bilty: boolean;
    partload_bilty_shortcut: boolean;
    broker_lorry_hires: boolean;
    broker_vouchers: boolean;
    broker_daily_payments: boolean;
    broker_deliveries: boolean;
    broker_linked_bilties: boolean;
    broker_transporter_balances: boolean;
    customer_invoices: boolean;
    customer_payments: boolean;
    enquiries: boolean;
    ownfleet_vehicle_drivers: boolean;
    partload_pending_loading: boolean;
    partload_in_transit: boolean;
    partload_pending_delivery: boolean;
    partload_challans: boolean;
    partload_main_challans: boolean;
    partload_invoices?: boolean;
    accounting: boolean;
    masters_parties: boolean;
    masters_drivers?: boolean;
    masters_vehicles: boolean;
    masters_vehicle_drivers: boolean;
    masters_bank_cash: boolean;
    masters_stations: boolean;
    masters_goods_types: boolean;
    masters_truck_types: boolean;
    masters_tyre_layouts: boolean;
    masters_document_types: boolean;
    masters_expense_categories: boolean;
    masters_package_types: boolean;
};

export type WorkspaceShare = {
    companies: WorkspaceCompany[];
    active_company_id: string | null;
    active_branch_id: string | null;
    active_company_name?: string | null;
    active_company_status?: string | null;
    active_branch_name?: string | null;
    active_branch_status?: string | null;
    context_version?: number;
    can_create_company?: boolean;
    create_company_url?: string | null;
    capabilities?: WorkspaceCapabilities | null;
    navigation?: WorkspaceNavigation | null;
} | null;
