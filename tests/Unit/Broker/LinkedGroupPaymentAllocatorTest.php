<?php

use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LinkedBilty\LinkedGroupPaymentAllocator;

test('proportional group payment allocates 2500 across 6000 and 4000', function () {
    $allocator = new LinkedGroupPaymentAllocator;

    $result = $allocator->allocate([
        'hire-a' => '6000.00',
        'hire-b' => '4000.00',
    ], '2500.00');

    expect($result)->toBe([
        'hire-a' => '1500.00',
        'hire-b' => '1000.00',
    ]);
});

test('residual rounding is deterministic and sums to payment', function () {
    $allocator = new LinkedGroupPaymentAllocator;

    $result = $allocator->allocate([
        'a' => '100.00',
        'b' => '100.00',
        'c' => '100.00',
    ], '10.00');

    $sum = '0.00';
    foreach ($result as $amount) {
        $sum = bcadd($sum, $amount, 2);
    }

    expect($sum)->toBe('10.00')
        ->and(array_sum(array_map('floatval', $result)))->toEqual(10.0);
});

test('over-allocation is rejected', function () {
    $allocator = new LinkedGroupPaymentAllocator;

    expect(fn () => $allocator->allocate([
        'a' => '100.00',
    ], '100.01'))->toThrow(BrokerDomainException::class);
});
