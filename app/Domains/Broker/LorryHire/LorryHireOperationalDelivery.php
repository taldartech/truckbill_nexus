<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Broker\Enums\BrokerDeliveryStatus;
use App\Domains\Consignment\Enums\LrStatus;
use App\Models\LorryHire;

/**
 * Authoritative delivery state for post-delivery Lorry Hire adjustments (Memo/POD marks delivered).
 */
final class LorryHireOperationalDelivery
{
    public static function isDelivered(LorryHire $hire): bool
    {
        $hire->loadMissing('lorryReceipt.delivery');
        $receipt = $hire->lorryReceipt;
        if ($receipt === null) {
            return false;
        }

        if ($receipt->status === LrStatus::Delivered) {
            return true;
        }

        $delivery = $receipt->delivery;
        if ($delivery === null) {
            return false;
        }

        return $delivery->status === BrokerDeliveryStatus::Delivered;
    }
}
