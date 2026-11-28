<?php

namespace App\Domains\Rbac;

/**
 * Stable permission codes.
 *
 * Customer operations & modules + Internal Team functional permissions.
 */
final class PermissionCodes
{
    // Customer Common Operations
    public const PLATFORM_VIEW = 'platform.operations.view';

    public const PLATFORM_CREATE = 'platform.operations.create';

    public const PLATFORM_EDIT = 'platform.operations.edit';

    public const PLATFORM_DELETE = 'platform.operations.delete';

    public const PLATFORM_CANCEL = 'platform.operations.cancel';

    public const PLATFORM_PRINT = 'platform.operations.print';

    public const PLATFORM_EXPORT = 'platform.operations.export';

    public const PLATFORM_MANAGE_SETTINGS = 'platform.operations.manage_settings';

    public const PLATFORM_VIEW_FREIGHT = 'platform.operations.view_freight';

    // Customer Masters & Reports
    public const MASTERS_VIEW = 'masters.view';

    public const MASTERS_CREATE = 'masters.create';

    public const MASTERS_EDIT = 'masters.edit';

    public const MASTERS_MANAGE = 'masters.manage';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    // Customer Enquiries & Quotations
    public const ENQUIRY_VIEW = 'enquiry.view';

    public const ENQUIRY_CREATE = 'enquiry.create';

    public const ENQUIRY_EDIT = 'enquiry.edit';

    public const ENQUIRY_ASSIGN = 'enquiry.assign';

    public const ENQUIRY_CONVERT = 'enquiry.convert';

    public const ENQUIRY_DELETE = 'enquiry.delete';

    public const QUOTATION_VIEW = 'quotation.view';

    public const QUOTATION_CREATE = 'quotation.create';

    public const QUOTATION_EDIT = 'quotation.edit';

    public const QUOTATION_SELECT = 'quotation.select';

    public const QUOTATION_BROADCAST = 'quotation.broadcast';

    // Customer Unified Lorry Receipts
    public const LR_VIEW = 'lr.view';

    public const LR_CREATE = 'lr.create';

    public const LR_EDIT = 'lr.edit';

    public const LR_CANCEL = 'lr.cancel';

    public const LR_DELETE = 'lr.delete';

    public const LR_NUMBER_ADJUST = 'lr.number.adjust';

    // Customer OwnFleet Trips, Invoices & Payments
    public const TRIP_VIEW = 'trip.view';

    public const TRIP_CREATE = 'trip.create';

    public const TRIP_EDIT = 'trip.edit';

    public const TRIP_DISPATCH = 'trip.dispatch';

    public const TRIP_COMPLETE = 'trip.complete';

    public const TRIP_CANCEL = 'trip.cancel';

    public const TRIP_DELETE = 'trip.delete';

    public const INVOICE_VIEW = 'invoice.view';

    public const INVOICE_CREATE = 'invoice.create';

    public const INVOICE_EDIT = 'invoice.edit';

    public const INVOICE_CANCEL = 'invoice.cancel';

    public const INVOICE_DELETE = 'invoice.delete';

    public const INVOICE_PRINT = 'invoice.print';

    public const PAYMENT_VIEW = 'payment.view';

    public const PAYMENT_CREATE = 'payment.create';

    // Customer Broker
    public const BROKER_LORRY_HIRE_VIEW = 'broker.lorry_hire.view';

    public const BROKER_LORRY_HIRE_CREATE = 'broker.lorry_hire.create';

    public const BROKER_LORRY_HIRE_EDIT = 'broker.lorry_hire.edit';

    public const BROKER_LORRY_HIRE_CANCEL = 'broker.lorry_hire.cancel';

    public const BROKER_VOUCHER_VIEW = 'broker.voucher.view';

    public const BROKER_VOUCHER_CREATE = 'broker.voucher.create';

    public const BROKER_VOUCHER_EDIT = 'broker.voucher.edit';

    public const BROKER_VOUCHER_STATUS = 'broker.voucher.status';

    public const BROKER_VOUCHER_BULK = 'broker.voucher.bulk';

    public const BROKER_DELIVERY_VIEW = 'broker.delivery.view';

    public const BROKER_DELIVERY_MANAGE = 'broker.delivery.manage';

    public const BROKER_ADJUSTMENT_VIEW = 'broker.adjustment.view';

    public const BROKER_ADJUSTMENT_CREATE = 'broker.adjustment.create';

    public const BROKER_INVOICE_VIEW = 'broker.invoice.view';

    public const BROKER_INVOICE_CREATE = 'broker.invoice.create';

    public const BROKER_INVOICE_CANCEL = 'broker.invoice.cancel';

    public const BROKER_INVOICE_PRINT = 'broker.invoice.print';

    public const BROKER_LINKED_BILTY_VIEW = 'broker.linked_bilty.view';

    public const BROKER_LINKED_BILTY_CREATE = 'broker.linked_bilty.create';

    public const BROKER_LINKED_BILTY_PAY = 'broker.linked_bilty.pay';

    public const BROKER_BOOK_BALANCE_VIEW = 'broker.book_balance.view';

    public const BROKER_BOOK_BALANCE_MANAGE = 'broker.book_balance.manage';

    // Customer Partload
    public const PARTLOAD_CHALLAN_VIEW = 'partload.challan.view';

    public const PARTLOAD_CHALLAN_CREATE = 'partload.challan.create';

    public const PARTLOAD_CHALLAN_DISPATCH = 'partload.challan.dispatch';

    public const PARTLOAD_CHALLAN_CANCEL = 'partload.challan.cancel';

    public const PARTLOAD_INWARD_VIEW = 'partload.inward.view';

    public const PARTLOAD_INWARD_CREATE = 'partload.inward.create';

    public const PARTLOAD_DR_VIEW = 'partload.delivery_receipt.view';

    public const PARTLOAD_DR_CREATE = 'partload.delivery_receipt.create';

    // Customer Accounting Add-on & Financial Visibility
    public const BRANCH_ACCOUNTING_VIEW = 'branch.accounting.view';

    public const BRANCH_ACCOUNTING_VOUCHER_CREATE = 'branch.accounting.voucher.create';

    public const BRANCH_ACCOUNTING_REPORT_VIEW = 'branch.accounting.report.view';

    public const BRANCH_ACCOUNTING_APPROVE = 'branch.accounting.approve';

    public const BRANCH_ACCOUNTING_REVERSE = 'branch.accounting.reverse';

    public const BRANCH_ACCOUNTING_MASTER_MANAGE = 'branch.accounting.master.manage';

    public const BRANCH_ACCOUNTING_VIEW_FINANCIAL = 'branch.accounting.view_financial';

    // Internal Team Functional Permissions
    public const INTERNAL_CRM_VIEW = 'internal.crm.view';

    public const INTERNAL_CRM_CREATE = 'internal.crm.create';

    public const INTERNAL_CRM_EDIT = 'internal.crm.edit';

    public const INTERNAL_CRM_MANAGE = 'internal.crm.manage';

    public const INTERNAL_USERS_VIEW = 'internal.users.view';

    public const INTERNAL_USERS_CREATE = 'internal.users.create';

    public const INTERNAL_USERS_EDIT = 'internal.users.edit';

    public const INTERNAL_USERS_MANAGE = 'internal.users.manage';

    public const INTERNAL_ONBOARDING_VIEW = 'internal.onboarding.view';

    public const INTERNAL_ONBOARDING_MANAGE = 'internal.onboarding.manage';

    public const INTERNAL_MASTERS_VIEW = 'internal.masters.view';

    public const INTERNAL_MASTERS_CREATE = 'internal.masters.create';

    public const INTERNAL_MASTERS_EDIT = 'internal.masters.edit';

    public const INTERNAL_MASTERS_MANAGE = 'internal.masters.manage';

    public const INTERNAL_GST_VIEW = 'internal.gst.view';

    public const INTERNAL_GST_CREATE = 'internal.gst.create';

    public const INTERNAL_GST_EDIT = 'internal.gst.edit';

    public const INTERNAL_GST_MANAGE = 'internal.gst.manage';

    public const INTERNAL_TDS_VIEW = 'internal.tds.view';

    public const INTERNAL_TDS_CREATE = 'internal.tds.create';

    public const INTERNAL_TDS_EDIT = 'internal.tds.edit';

    public const INTERNAL_TDS_MANAGE = 'internal.tds.manage';

    public const INTERNAL_SUBSCRIPTION_BILLING_VIEW = 'internal.subscription_billing.view';

    public const INTERNAL_SUBSCRIPTION_BILLING_MANAGE = 'internal.subscription_billing.manage';

    public const INTERNAL_SETTINGS_VIEW = 'internal.settings.view';

    public const INTERNAL_SETTINGS_MANAGE = 'internal.settings.manage';

    public const INTERNAL_AUDIT_VIEW = 'internal.audit.view';

    public const INTERNAL_AUDIT_EXPORT = 'internal.audit.export';

    /**
     * All Customer-domain permission codes (never includes Internal permissions).
     *
     * @return list<string>
     */
    public static function customerAllCodes(): array
    {
        return array_values(array_unique(array_merge(
            self::customerPlatformCatalogue(),
            self::customerMastersAndReportsCatalogue(),
            self::customerPhase4Catalogue(),
            self::customerPhase5Catalogue(),
            self::customerPhase6Catalogue(),
            self::customerPartloadCatalogue(),
            self::customerAccountingCatalogue(),
        )));
    }

    /**
     * @return list<string>
     */
    public static function customerPlatformCatalogue(): array
    {
        return [
            self::PLATFORM_VIEW,
            self::PLATFORM_CREATE,
            self::PLATFORM_EDIT,
            self::PLATFORM_DELETE,
            self::PLATFORM_CANCEL,
            self::PLATFORM_PRINT,
            self::PLATFORM_EXPORT,
            self::PLATFORM_MANAGE_SETTINGS,
            self::PLATFORM_VIEW_FREIGHT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function customerMastersAndReportsCatalogue(): array
    {
        return [
            self::MASTERS_VIEW,
            self::MASTERS_CREATE,
            self::MASTERS_EDIT,
            self::MASTERS_MANAGE,
            self::REPORTS_VIEW,
            self::REPORTS_EXPORT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function customerPhase4Catalogue(): array
    {
        return [
            self::ENQUIRY_VIEW,
            self::ENQUIRY_CREATE,
            self::ENQUIRY_EDIT,
            self::ENQUIRY_ASSIGN,
            self::ENQUIRY_CONVERT,
            self::ENQUIRY_DELETE,
            self::QUOTATION_VIEW,
            self::QUOTATION_CREATE,
            self::QUOTATION_EDIT,
            self::QUOTATION_SELECT,
            self::QUOTATION_BROADCAST,
            self::LR_VIEW,
            self::LR_CREATE,
            self::LR_EDIT,
            self::LR_CANCEL,
            self::LR_DELETE,
            self::LR_NUMBER_ADJUST,
        ];
    }

    /**
     * @return list<string>
     */
    public static function customerPhase5Catalogue(): array
    {
        return [
            self::TRIP_VIEW,
            self::TRIP_CREATE,
            self::TRIP_EDIT,
            self::TRIP_DISPATCH,
            self::TRIP_COMPLETE,
            self::TRIP_CANCEL,
            self::TRIP_DELETE,
            self::INVOICE_VIEW,
            self::INVOICE_CREATE,
            self::INVOICE_EDIT,
            self::INVOICE_CANCEL,
            self::INVOICE_DELETE,
            self::INVOICE_PRINT,
            self::PAYMENT_VIEW,
            self::PAYMENT_CREATE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function customerPhase6Catalogue(): array
    {
        return [
            self::BROKER_LORRY_HIRE_VIEW,
            self::BROKER_LORRY_HIRE_CREATE,
            self::BROKER_LORRY_HIRE_EDIT,
            self::BROKER_LORRY_HIRE_CANCEL,
            self::BROKER_VOUCHER_VIEW,
            self::BROKER_VOUCHER_CREATE,
            self::BROKER_VOUCHER_EDIT,
            self::BROKER_VOUCHER_STATUS,
            self::BROKER_VOUCHER_BULK,
            self::BROKER_DELIVERY_VIEW,
            self::BROKER_DELIVERY_MANAGE,
            self::BROKER_ADJUSTMENT_VIEW,
            self::BROKER_ADJUSTMENT_CREATE,
            self::BROKER_INVOICE_VIEW,
            self::BROKER_INVOICE_CREATE,
            self::BROKER_INVOICE_CANCEL,
            self::BROKER_INVOICE_PRINT,
            self::BROKER_LINKED_BILTY_VIEW,
            self::BROKER_LINKED_BILTY_CREATE,
            self::BROKER_LINKED_BILTY_PAY,
            self::BROKER_BOOK_BALANCE_VIEW,
            self::BROKER_BOOK_BALANCE_MANAGE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function customerPartloadCatalogue(): array
    {
        return [
            self::PARTLOAD_CHALLAN_VIEW,
            self::PARTLOAD_CHALLAN_CREATE,
            self::PARTLOAD_CHALLAN_DISPATCH,
            self::PARTLOAD_CHALLAN_CANCEL,
            self::PARTLOAD_INWARD_VIEW,
            self::PARTLOAD_INWARD_CREATE,
            self::PARTLOAD_DR_VIEW,
            self::PARTLOAD_DR_CREATE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function customerAccountingCatalogue(): array
    {
        return [
            self::BRANCH_ACCOUNTING_VIEW,
            self::BRANCH_ACCOUNTING_VOUCHER_CREATE,
            self::BRANCH_ACCOUNTING_REPORT_VIEW,
            self::BRANCH_ACCOUNTING_APPROVE,
            self::BRANCH_ACCOUNTING_REVERSE,
            self::BRANCH_ACCOUNTING_MASTER_MANAGE,
            self::BRANCH_ACCOUNTING_VIEW_FINANCIAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function internalTeamCatalogue(): array
    {
        return [
            self::INTERNAL_CRM_VIEW,
            self::INTERNAL_CRM_CREATE,
            self::INTERNAL_CRM_EDIT,
            self::INTERNAL_CRM_MANAGE,
            self::INTERNAL_USERS_VIEW,
            self::INTERNAL_USERS_CREATE,
            self::INTERNAL_USERS_EDIT,
            self::INTERNAL_USERS_MANAGE,
            self::INTERNAL_ONBOARDING_VIEW,
            self::INTERNAL_ONBOARDING_MANAGE,
            self::INTERNAL_MASTERS_VIEW,
            self::INTERNAL_MASTERS_CREATE,
            self::INTERNAL_MASTERS_EDIT,
            self::INTERNAL_MASTERS_MANAGE,
            self::INTERNAL_GST_VIEW,
            self::INTERNAL_GST_CREATE,
            self::INTERNAL_GST_EDIT,
            self::INTERNAL_GST_MANAGE,
            self::INTERNAL_TDS_VIEW,
            self::INTERNAL_TDS_CREATE,
            self::INTERNAL_TDS_EDIT,
            self::INTERNAL_TDS_MANAGE,
            self::INTERNAL_SUBSCRIPTION_BILLING_VIEW,
            self::INTERNAL_SUBSCRIPTION_BILLING_MANAGE,
            self::INTERNAL_SETTINGS_VIEW,
            self::INTERNAL_SETTINGS_MANAGE,
            self::INTERNAL_AUDIT_VIEW,
            self::INTERNAL_AUDIT_EXPORT,
        ];
    }
}
