<?php

namespace App\Domains\Consignment\Dependency;

/**
 * @phpstan-type LrDependencyBlockerShape array{code: string, message: string, source: string}
 */
final readonly class LrDependencyBlocker
{
    public function __construct(
        public string $code,
        public string $message,
        public string $source,
    ) {}

    /**
     * @return LrDependencyBlockerShape
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'source' => $this->source,
        ];
    }
}
