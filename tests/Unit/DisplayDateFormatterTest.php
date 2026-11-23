<?php

use App\Support\Formatting\DisplayDateFormatter;

test('display date formats date-only values as DD-MM-YYYY', function () {
    expect(DisplayDateFormatter::date('2026-12-08'))->toBe('08-12-2026')
        ->and(DisplayDateFormatter::date('2026-11-23T05:58:26+00:00'))->toBe('23-11-2026')
        ->and(DisplayDateFormatter::date(null))->toBe('—');
});

test('display datetime uses company timezone', function () {
    $formatted = DisplayDateFormatter::dateTime('2026-12-08T05:58:26+00:00', 'Asia/Kolkata');
    expect($formatted)->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2} (AM|PM)$/');
});
