<?php

namespace App\Support\Outbox\Consumers;

use InvalidArgumentException;

class OutboxConsumerRegistry
{
    /**
     * @param  array<string, OutboxConsumer>  $consumers
     */
    public function __construct(
        private array $consumers = [],
    ) {
        if ($this->consumers === []) {
            $this->consumers = [
                'outbox.harness.effect' => new HarnessEffectConsumer,
            ];
        }
    }

    public function for(string $eventType): OutboxConsumer
    {
        if (! isset($this->consumers[$eventType])) {
            throw new InvalidArgumentException(sprintf('No outbox consumer registered for [%s].', $eventType));
        }

        return $this->consumers[$eventType];
    }

    public function register(string $eventType, OutboxConsumer $consumer): void
    {
        $this->consumers[$eventType] = $consumer;
    }
}
