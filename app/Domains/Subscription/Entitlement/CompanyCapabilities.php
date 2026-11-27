<?php

namespace App\Domains\Subscription\Entitlement;

/**
 * Authoritative capability identifiers for Company subscription enforcement.
 */
final class CompanyCapabilities
{
    // Broker module capabilities
    public const BROKER_LR = 'broker.lr';

    public const BROKER_LORRY_HIRE = 'broker.lorry_hire';

    public const BROKER_VOUCHERS = 'broker.vouchers';

    public const BROKER_DAILY_PAYMENTS = 'broker.daily_payments';

    public const BROKER_DELIVERIES = 'broker.deliveries';

    public const BROKER_LINKED_BILTIES = 'broker.linked_bilties';

    public const BROKER_BOOK_BALANCE = 'broker.book_balance';

    public const BROKER_INVOICES = 'broker.invoices';

    // OwnFleet module capabilities
    public const OWNFLEET_LR = 'ownfleet.lr';

    public const OWNFLEET_TRIPS = 'ownfleet.trips';

    public const OWNFLEET_TRIP_EXPENSES = 'ownfleet.trip_expenses';

    public const OWNFLEET_DIESEL = 'ownfleet.diesel';

    public const OWNFLEET_INVOICES = 'ownfleet.invoices';

    public const OWNFLEET_VEHICLE_DRIVERS = 'ownfleet.vehicle_drivers';

    public const OWNFLEET_DRIVER_ATTENDANCE = 'ownfleet.driver_attendance';

    public const OWNFLEET_TRUCK_TYPES = 'ownfleet.truck_types';

    public const OWNFLEET_TYRE_LAYOUTS = 'ownfleet.tyre_layouts';

    public const OWNFLEET_EXPENSE_CATEGORIES = 'ownfleet.expense_categories';

    // Partload module capabilities
    public const PARTLOAD_LR = 'partload.lr';

    public const PARTLOAD_PENDING_LOADING = 'partload.pending_loading';

    public const PARTLOAD_IN_TRANSIT = 'partload.in_transit';

    public const PARTLOAD_PENDING_DELIVERY = 'partload.pending_delivery';

    public const PARTLOAD_CHALLANS = 'partload.challans';

    public const PARTLOAD_MAIN_CHALLANS = 'partload.main_challans';

    public const PARTLOAD_INWARDS = 'partload.inwards';

    public const PARTLOAD_DELIVERY_RECEIPTS = 'partload.delivery_receipts';

    public const PARTLOAD_INVOICES = 'partload.invoices';

    // Vehicle ownership capabilities
    public const VEHICLE_OWNERSHIP_OWN = 'vehicle.ownership.own';

    public const VEHICLE_OWNERSHIP_MARKET = 'vehicle.ownership.market';

    // Shared / Core / Accounting capabilities
    public const LR_UNIFIED = 'lr.unified';

    public const CUSTOMER_INVOICES = 'customer_invoices';

    public const CUSTOMER_PAYMENTS = 'customer_payments';

    public const ENQUIRIES = 'enquiries';

    public const ACCOUNTING = 'accounting';

    public const MASTERS_PARTIES = 'masters.parties';

    public const MASTERS_PARTIES_DRIVER_ROLE = 'masters.parties.driver_role';

    public const MASTERS_VEHICLES = 'masters.vehicles';

    public const MASTERS_BANK_CASH = 'masters.bank_cash';

    public const MASTERS_STATIONS = 'masters.stations';

    public const MASTERS_GOODS_TYPES = 'masters.goods_types';

    public const MASTERS_PACKAGE_TYPES = 'masters.package_types';

    public const MASTERS_DOCUMENT_TYPES = 'masters.document_types';

    public const DOCUMENTS = 'documents';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BROKER_LR,
            self::BROKER_LORRY_HIRE,
            self::BROKER_VOUCHERS,
            self::BROKER_DAILY_PAYMENTS,
            self::BROKER_DELIVERIES,
            self::BROKER_LINKED_BILTIES,
            self::BROKER_BOOK_BALANCE,
            self::BROKER_INVOICES,
            self::OWNFLEET_LR,
            self::OWNFLEET_TRIPS,
            self::OWNFLEET_TRIP_EXPENSES,
            self::OWNFLEET_DIESEL,
            self::OWNFLEET_INVOICES,
            self::OWNFLEET_VEHICLE_DRIVERS,
            self::OWNFLEET_DRIVER_ATTENDANCE,
            self::OWNFLEET_TRUCK_TYPES,
            self::OWNFLEET_TYRE_LAYOUTS,
            self::OWNFLEET_EXPENSE_CATEGORIES,
            self::PARTLOAD_LR,
            self::PARTLOAD_PENDING_LOADING,
            self::PARTLOAD_IN_TRANSIT,
            self::PARTLOAD_PENDING_DELIVERY,
            self::PARTLOAD_CHALLANS,
            self::PARTLOAD_MAIN_CHALLANS,
            self::PARTLOAD_INWARDS,
            self::PARTLOAD_DELIVERY_RECEIPTS,
            self::PARTLOAD_INVOICES,
            self::VEHICLE_OWNERSHIP_OWN,
            self::VEHICLE_OWNERSHIP_MARKET,
            self::LR_UNIFIED,
            self::CUSTOMER_INVOICES,
            self::CUSTOMER_PAYMENTS,
            self::ENQUIRIES,
            self::ACCOUNTING,
            self::MASTERS_PARTIES,
            self::MASTERS_PARTIES_DRIVER_ROLE,
            self::MASTERS_VEHICLES,
            self::MASTERS_BANK_CASH,
            self::MASTERS_STATIONS,
            self::MASTERS_GOODS_TYPES,
            self::MASTERS_PACKAGE_TYPES,
            self::MASTERS_DOCUMENT_TYPES,
            self::DOCUMENTS,
        ];
    }

    public static function moduleLabel(string $capability): string
    {
        if (str_starts_with($capability, 'broker.') || $capability === self::VEHICLE_OWNERSHIP_MARKET) {
            return 'Broker';
        }

        if (
            str_starts_with($capability, 'ownfleet.')
            || $capability === self::VEHICLE_OWNERSHIP_OWN
        ) {
            return 'OwnFleet';
        }

        if (str_starts_with($capability, 'partload.')) {
            return 'Partload';
        }

        if ($capability === self::ACCOUNTING) {
            return 'Accounting';
        }

        return 'this module';
    }
}
