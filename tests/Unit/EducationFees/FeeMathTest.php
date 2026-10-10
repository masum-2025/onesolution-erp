<?php

use Modules\EducationFees\Services\FeeMath;

/*
 * FEE-1: the arithmetic of fees, integers only.
 */

it('takes a percent half up', function () {
    expect(FeeMath::percentOf(150000, 1000))->toBe(15000)
        ->and(FeeMath::percentOf(333, 5000))->toBe(167)
        ->and(FeeMath::percentOf(0, 2500))->toBe(0);
});

it('picks the most specific structure that fits, head by head', function () {
    $structures = [
        ['id' => 'all', 'unit_id' => null, 'program_id' => null, 'level_id' => null, 'category_id' => null, 'lines' => ['tui' => ['amount_minor' => 150000, 'months' => null], 'lab' => ['amount_minor' => 20000, 'months' => [3, 4]]]],
        ['id' => 'class6', 'unit_id' => null, 'program_id' => null, 'level_id' => 'c6', 'category_id' => null, 'lines' => ['tui' => ['amount_minor' => 120000, 'months' => null]]],
        ['id' => 'staff-children', 'unit_id' => null, 'program_id' => null, 'level_id' => null, 'category_id' => 'staff', 'lines' => ['tui' => ['amount_minor' => 50000, 'months' => null]]],
        ['id' => 'other-campus', 'unit_id' => 'b2', 'program_id' => null, 'level_id' => 'c6', 'category_id' => 'staff', 'lines' => ['tui' => ['amount_minor' => 1, 'months' => null]]],
    ];
    $student = ['unit_id' => 'b1', 'program_id' => 'sec', 'level_id' => 'c6', 'category_id' => null];

    expect(FeeMath::amountsFor($structures, $student))->toBe([
        'tui' => ['amount_minor' => 120000, 'months' => null, 'structure_id' => 'class6'],
        'lab' => ['amount_minor' => 20000, 'months' => [3, 4], 'structure_id' => 'all'],
    ]);
    // A class beats a category; a structure for another campus never fits.
    expect(FeeMath::amountsFor($structures, [...$student, 'category_id' => 'staff'])['tui']['structure_id'])->toBe('class6')
        ->and(FeeMath::amountsFor($structures, [...$student, 'level_id' => 'c7', 'category_id' => 'staff'])['tui']['structure_id'])->toBe('staff-children')
        ->and(FeeMath::amountsFor($structures, ['unit_id' => 'b9', 'program_id' => null, 'level_id' => null, 'category_id' => null])['tui']['structure_id'])->toBe('all');
});

it('works out a line: discounts, never below nothing, tax in the fee or on top', function () {
    expect(FeeMath::line(150000, 1000, 0))->toBe(['discount_minor' => 15000, 'tax_minor' => 0, 'due_minor' => 135000])
        ->and(FeeMath::line(150000, 1000, 20000))->toBe(['discount_minor' => 35000, 'tax_minor' => 0, 'due_minor' => 115000])
        ->and(FeeMath::line(150000, 12000, 0))->toBe(['discount_minor' => 150000, 'tax_minor' => 0, 'due_minor' => 0])
        ->and(FeeMath::line(10000, 0, 50000))->toBe(['discount_minor' => 10000, 'tax_minor' => 0, 'due_minor' => 0]);
    // 15 % VAT inside 1 150.00 is 150.00; on top of 1 000.00 it is 150.00 more.
    expect(FeeMath::line(115000, 0, 0, 1500, true))->toBe(['discount_minor' => 0, 'tax_minor' => 15000, 'due_minor' => 115000])
        ->and(FeeMath::line(100000, 0, 0, 1500, false))->toBe(['discount_minor' => 0, 'tax_minor' => 15000, 'due_minor' => 115000]);
});

it('fines once, per day or per month after the grace days, up to the most', function () {
    $rule = ['enabled' => true, 'mode' => 'once', 'amount_minor' => 5000, 'grace_days' => 5, 'max_minor' => 0];
    expect(FeeMath::fineBy($rule, '2026-01-10', '2026-01-15'))->toBe(0)
        ->and(FeeMath::fineBy($rule, '2026-01-10', '2026-01-16'))->toBe(5000)
        ->and(FeeMath::fineBy($rule, '2026-01-10', '2026-03-01'))->toBe(5000)
        ->and(FeeMath::fineBy([...$rule, 'mode' => 'per_day', 'amount_minor' => 1000], '2026-01-10', '2026-01-18'))->toBe(3000)
        ->and(FeeMath::fineBy([...$rule, 'mode' => 'per_day', 'amount_minor' => 1000, 'max_minor' => 2500], '2026-01-10', '2026-01-18'))->toBe(2500)
        ->and(FeeMath::fineBy([...$rule, 'mode' => 'per_month'], '2026-01-10', '2026-02-14'))->toBe(5000)
        ->and(FeeMath::fineBy([...$rule, 'mode' => 'per_month'], '2026-01-10', '2026-02-15'))->toBe(10000)
        ->and(FeeMath::fineBy([...$rule, 'enabled' => false], '2026-01-10', '2026-03-01'))->toBe(0);
});

it('puts a month\'s due day inside the month', function () {
    expect(FeeMath::dueInMonth('2026-02', 10))->toBe('2026-02-10')
        ->and(FeeMath::dueInMonth('2026-02', 28))->toBe('2026-02-28')
        ->and(FeeMath::dueInMonth('2027-02', 31))->toBe('2027-02-28');
});
