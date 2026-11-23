<?php

namespace App\Domains\OwnFleet\Invoice;

use App\Models\Party;

/**
 * Customer invoice TDS rate (Rules §10) — distinct from transporter TDS engine.
 */
final class TripInvoiceTdsResolver
{
    public function resolvePercent(Party $billingCustomer): string
    {
        $pan = strtoupper(trim((string) $billingCustomer->pan));
        if (strlen($pan) >= 4) {
            $fourth = $pan[3];
            if (in_array($fourth, ['P', 'H'], true)) {
                return '1.00';
            }
            if (in_array($fourth, ['C', 'F', 'A', 'B', 'J', 'L', 'T'], true)) {
                return '2.00';
            }
        }

        return '2.00';
    }
}
