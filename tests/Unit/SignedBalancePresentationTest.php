<?php

use App\Support\Ui\Presentation\SignedBalancePresentation;

test('positive signed balance becomes balance due only', function () {
    $split = (new SignedBalancePresentation)->split('18000.00');

    expect($split['balance_due'])->toBe('18000.00')
        ->and($split['negative_adjusted_amount'])->toBe('0.00')
        ->and($split['signed_balance'])->toBe('18000.00');
});

test('negative signed balance becomes negative adjusted amount only', function () {
    $split = (new SignedBalancePresentation)->split('-2000.00');

    expect($split['balance_due'])->toBe('0.00')
        ->and($split['negative_adjusted_amount'])->toBe('2000.00')
        ->and($split['signed_balance'])->toBe('-2000.00');
});

test('zero signed balance yields zero on both sides', function () {
    $split = (new SignedBalancePresentation)->split('0');

    expect($split['balance_due'])->toBe('0.00')
        ->and($split['negative_adjusted_amount'])->toBe('0.00');
});
