<?php

namespace App\Domains\Master\Party;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocks removal of a Party role while operational or financial records still rely on it.
 * A reference is satisfied by any role listed for it, so removing one of several valid roles is allowed.
 */
final class PartyRoleDependencyGuard
{
    /**
     * @var list<array{table: string, column: string, roles: list<string>, label: string}>
     */
    private const REFERENCES = [
        ['table' => 'lorry_receipts', 'column' => 'billing_customer_party_id', 'roles' => ['customer'], 'label' => 'lorry receipts'],
        ['table' => 'broker_invoices', 'column' => 'billing_customer_party_id', 'roles' => ['customer'], 'label' => 'broker invoices'],
        ['table' => 'partload_invoices', 'column' => 'billing_customer_party_id', 'roles' => ['customer'], 'label' => 'partload invoices'],
        ['table' => 'trip_invoices', 'column' => 'billing_customer_party_id', 'roles' => ['customer'], 'label' => 'trip invoices'],
        ['table' => 'enquiries', 'column' => 'customer_party_id', 'roles' => ['customer'], 'label' => 'enquiries'],
        ['table' => 'lorry_receipts', 'column' => 'consignor_party_id', 'roles' => ['consignor'], 'label' => 'lorry receipts'],
        ['table' => 'trip_pickups', 'column' => 'consignor_party_id', 'roles' => ['consignor'], 'label' => 'trip pickups'],
        ['table' => 'lorry_receipts', 'column' => 'consignee_party_id', 'roles' => ['consignee'], 'label' => 'lorry receipts'],
        ['table' => 'trip_drops', 'column' => 'consignee_party_id', 'roles' => ['consignee'], 'label' => 'trip drops'],
        ['table' => 'partload_delivery_receipts', 'column' => 'consignee_party_id', 'roles' => ['consignee'], 'label' => 'delivery receipts'],
        ['table' => 'lorry_hires', 'column' => 'transporter_party_id', 'roles' => ['transporter'], 'label' => 'lorry hires'],
        ['table' => 'transporter_vouchers', 'column' => 'transporter_party_id', 'roles' => ['transporter'], 'label' => 'transporter vouchers'],
        ['table' => 'transporter_book_balance_entries', 'column' => 'transporter_party_id', 'roles' => ['transporter'], 'label' => 'book balance entries'],
        ['table' => 'enquiry_quotations', 'column' => 'transporter_party_id', 'roles' => ['transporter'], 'label' => 'enquiry quotations'],
        ['table' => 'station_default_transporters', 'column' => 'default_transporter_party_id', 'roles' => ['transporter'], 'label' => 'station default transporters'],
        ['table' => 'vehicles', 'column' => 'owner_party_id', 'roles' => ['transporter'], 'label' => 'vehicles (owner)'],
        ['table' => 'transporter_vouchers', 'column' => 'pump_vendor_party_id', 'roles' => ['pump_vendor'], 'label' => 'transporter vouchers'],
        ['table' => 'trip_expenses', 'column' => 'vendor_party_id', 'roles' => ['general_vendor', 'pump_vendor', 'transporter'], 'label' => 'trip expenses'],
        ['table' => 'vendor_expense_payments', 'column' => 'vendor_party_id', 'roles' => ['general_vendor', 'pump_vendor', 'transporter'], 'label' => 'vendor payments'],
        ['table' => 'trips', 'column' => 'driver_party_id', 'roles' => ['driver'], 'label' => 'trips'],
        ['table' => 'trips', 'column' => 'primary_driver_party_id', 'roles' => ['driver'], 'label' => 'trips'],
        ['table' => 'trips', 'column' => 'substitute_driver_party_id', 'roles' => ['driver'], 'label' => 'trips'],
        ['table' => 'vehicles', 'column' => 'primary_driver_party_id', 'roles' => ['driver'], 'label' => 'vehicles (driver)'],
        ['table' => 'vehicle_driver_assignments', 'column' => 'driver_party_id', 'roles' => ['driver'], 'label' => 'vehicle driver assignments'],
        ['table' => 'vehicle_driver_attendances', 'column' => 'primary_driver_party_id', 'roles' => ['driver'], 'label' => 'driver attendance'],
        ['table' => 'vehicle_driver_attendances', 'column' => 'temporary_driver_party_id', 'roles' => ['driver'], 'label' => 'driver attendance'],
        ['table' => 'driver_advance_entries', 'column' => 'driver_party_id', 'roles' => ['driver'], 'label' => 'driver advances'],
        ['table' => 'partload_challans', 'column' => 'driver_party_id', 'roles' => ['driver'], 'label' => 'partload challans'],
        ['table' => 'partload_main_challans', 'column' => 'driver_party_id', 'roles' => ['driver'], 'label' => 'main challans'],
        ['table' => 'partload_inward_registers', 'column' => 'driver_party_id', 'roles' => ['driver'], 'label' => 'inward registers'],
    ];

    /**
     * @param  list<string>  $removedRoles
     * @param  list<string>  $remainingRoles
     */
    public function assertRemovable(Party $party, array $removedRoles, array $remainingRoles): void
    {
        $messages = [];
        foreach ($removedRoles as $role) {
            $usages = [];
            foreach (self::REFERENCES as $reference) {
                if (! in_array($role, $reference['roles'], true)
                    || array_intersect($reference['roles'], $remainingRoles) !== []) {
                    continue;
                }

                if (DB::table($reference['table'])->where($reference['column'], $party->id)->exists()) {
                    $usages[$reference['label']] = true;
                }
            }

            if ($usages !== []) {
                $messages[] = sprintf(
                    'The %s role cannot be removed because it is used by existing %s.',
                    PartyRoleType::from($role)->label(),
                    implode(', ', array_keys($usages)),
                );
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['roles' => implode(' ', $messages)]);
        }
    }
}
