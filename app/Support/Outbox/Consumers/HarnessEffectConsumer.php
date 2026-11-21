<?php

namespace App\Support\Outbox\Consumers;

use App\Support\Outbox\Harness\OutboxHarnessEffect;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenant\TenantContextHolder;
use RuntimeException;

/**
 * Test/foundation consumer that records a side effect at most once per event_id.
 */
class HarnessEffectConsumer implements OutboxConsumer
{
    public function handle(OutboxMessage $message): void
    {
        $tenant = TenantContextHolder::require();

        if ($tenant->companyId !== $message->company_id) {
            throw new RuntimeException('Cross-tenant outbox consumption denied.');
        }

        OutboxHarnessEffect::query()->firstOrCreate(
            ['event_id' => $message->event_id],
            [
                'company_id' => $message->company_id,
                'effect_key' => (string) ($message->payload['effect_key'] ?? 'default'),
                'apply_count' => 1,
            ],
        );
    }
}
