<?php

use App\Platform\Support\MoneyText;

/*
 * Money in words people read: messages and documents keep the currency code
 * ("BDT ১,২৩৪.৫০"); screens use the language's own way, as the app does
 * ("১,২৩৪.৫০৳"), from integer minor units, never floats.
 */

it('writes money for messages with the currency code', function () {
    expect(MoneyText::format(123450, 'BDT', 'bn'))->toBe('BDT ১,২৩৪.৫০')
        ->and(MoneyText::format(-500, 'BDT', 'en'))->toBe('-BDT 5.00');
});

it('writes money for screens the way the language does', function (int $minor, string $currency, string $locale, string $shown) {
    expect(MoneyText::shown($minor, $currency, $locale))->toBe($shown);
})->with([
    'Bangla taka' => [123450, 'BDT', 'bn', '১,২৩৪.৫০৳'],
    'zero' => [0, 'BDT', 'bn', '০.০০৳'],
    'owed back' => [-500, 'BDT', 'bn', '-৫.০০৳'],
    // A non-breaking space keeps the code and the number together, as the browser writes it.
    'English taka' => [123450, 'BDT', 'en', "BDT\u{00A0}1,234.50"],
    'no minor unit' => [1500, 'JPY', 'en', '¥1,500'],
    // Beyond what a float holds exactly: still exact.
    'very large' => [900719925474099312, 'BDT', 'en', "BDT\u{00A0}9,007,199,254,740,993.12"],
]);
