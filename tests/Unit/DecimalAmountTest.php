<?php

use App\Platform\Payments\DecimalAmount;

/*
 * Gateways speak decimal text; we keep integer minor units. String math only.
 */

it('writes minor units as gateway decimal text', function (int $minor, string $currency, string $text) {
    expect(DecimalAmount::fromMinor($minor, $currency))->toBe($text);
})->with([
    [29900, 'BDT', '299.00'],
    [5, 'BDT', '0.05'],
    [0, 'BDT', '0.00'],
    [1500, 'JPY', '1500'],
    [123456789012, 'USD', '1234567890.12'],
]);

it('reads gateway decimal text exactly, or not at all', function (?string $text, string $currency, ?int $minor) {
    expect(DecimalAmount::toMinor($text, $currency))->toBe($minor);
})->with([
    ['299.00', 'BDT', 29900],
    ['299', 'BDT', 29900],
    ['299.5', 'BDT', 29950],
    ['299.0000', 'BDT', 29900],
    // More precision than the currency has is not rounded away silently.
    ['299.001', 'BDT', null],
    ['-1.00', 'BDT', null],
    ['1e3', 'BDT', null],
    ['', 'BDT', null],
    [null, 'BDT', null],
    ['12,000.00', 'BDT', null],
]);
