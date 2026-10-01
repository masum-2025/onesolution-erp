<?php

use App\Platform\Rules\Enums\RuleType;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleDefinition;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleValueValidator;

/*
 * Phase 11: bounds, comparisons and clamping of rule values (critical-path
 * coverage of the rule engine), type by type.
 */

function ruleOf(RuleType $type, array $schema = []): RuleDefinition
{
    return new RuleDefinition(
        key: 'test.'.$type->value, moduleKey: 'test', type: $type, schema: $schema, default: null, nullable: false,
        label: 'Test', description: '', overridableLevels: [], editPermission: 'rules.edit', requiresApproval: false,
        sensitive: false, countrySpecific: false, category: 'test', sortOrder: 0,
    );
}

function ruleValidator(): RuleValueValidator
{
    return app(RuleValueValidator::class);
}

it('refuses malformed bounds, with a reason', function (RuleType $type, mixed $bounds, string $reason) {
    expect(ruleValidator()->validateBounds(ruleOf($type), $bounds))->toContain($reason);
})->with([
    'not an object' => [RuleType::Integer, [1, 2], 'must be an object'],
    'empty' => [RuleType::Integer, [], 'must be an object'],
    'unknown key' => [RuleType::Integer, ['least' => 1], 'Only min, max and allowed'],
    'allowed on a boolean' => [RuleType::Boolean, ['allowed' => [true]], 'allowed list is not supported'],
    'min of the wrong type' => [RuleType::Integer, ['min' => 'ten'], 'min:'],
    'min above max' => [RuleType::Integer, ['min' => 10, 'max' => 5], 'not be greater'],
    'allowed not a list' => [RuleType::Enum, ['allowed' => ['a' => 'x']], 'non-empty list'],
    'allowed empty' => [RuleType::Enum, ['allowed' => []], 'non-empty list'],
    'allowed item of the wrong type' => [RuleType::Integer, ['allowed' => [1, 'two']], 'allowed:'],
]);

it('accepts well-formed bounds, including allowed lists of each kind', function () {
    expect(ruleValidator()->validateBounds(ruleOf(RuleType::Integer), ['min' => 1, 'max' => 9, 'allowed' => [1, 5, 9]]))->toBeNull()
        ->and(ruleValidator()->validateBounds(ruleOf(RuleType::MultiEnum), ['allowed' => ['sat', 'sun']]))->toBeNull();
});

it('checks values against allowed lists and money of another currency', function () {
    $multi = ruleOf(RuleType::MultiEnum);
    $money = ruleOf(RuleType::Money);

    expect(ruleValidator()->satisfies($multi, ['sat'], ['allowed' => ['sat', 'sun']]))->toBeTrue()
        ->and(ruleValidator()->satisfies($multi, ['sat', 'fri'], ['allowed' => ['sat', 'sun']]))->toBeFalse()
        ->and(ruleValidator()->satisfies(ruleOf(RuleType::Enum), 'fifo', ['allowed' => ['fifo']]))->toBeTrue()
        ->and(ruleValidator()->satisfies($money, ['amount' => 500, 'currency' => 'USD'], ['min' => ['amount' => 100, 'currency' => 'BDT']]))->toBeFalse()
        ->and(ruleValidator()->satisfies($money, ['amount' => 500, 'currency' => 'BDT'], ['min' => ['amount' => 100, 'currency' => 'BDT']]))->toBeTrue();
});

it('combines allowed lists to what every parent allows', function () {
    expect(ruleValidator()->combine(ruleOf(RuleType::Enum), [['allowed' => ['a', 'b', 'c']], ['allowed' => ['b', 'c', 'd']]]))
        ->toBe(['allowed' => ['b', 'c']]);
});

it('lets children only narrow their parent\'s bounds', function () {
    $integer = ruleOf(RuleType::Integer);

    expect(ruleValidator()->within($integer, ['min' => 3], ['min' => 5]))->toBeFalse()
        ->and(ruleValidator()->within($integer, ['min' => 6], ['min' => 5]))->toBeTrue()
        ->and(ruleValidator()->within($integer, ['allowed' => [6, 7]], ['min' => 5]))->toBeTrue()
        ->and(ruleValidator()->within(ruleOf(RuleType::Enum), ['allowed' => ['a']], ['allowed' => ['a', 'b']]))->toBeTrue()
        ->and(ruleValidator()->within(ruleOf(RuleType::Enum), ['allowed' => ['a', 'z']], ['allowed' => ['a', 'b']]))->toBeFalse()
        ->and(ruleValidator()->within(ruleOf(RuleType::Enum), [], ['allowed' => ['a', 'b']]))->toBeFalse();
});

it('pulls stored values back inside newer minimums and maximums', function () {
    $integer = ruleOf(RuleType::Integer);

    expect(ruleValidator()->clamp($integer, 3, ['min' => 5, 'max' => 9]))->toBe(5)
        ->and(ruleValidator()->clamp($integer, 12, ['min' => 5, 'max' => 9]))->toBe(9)
        ->and(ruleValidator()->clamp($integer, 7, ['min' => 5, 'max' => 9]))->toBe(7);
});

it('pulls stored values back inside newer allowed lists', function () {
    expect(ruleValidator()->clamp(ruleOf(RuleType::MultiEnum), ['sat', 'fri'], ['allowed' => ['sat', 'sun']]))->toBe(['sat'])
        ->and(ruleValidator()->clamp(ruleOf(RuleType::Enum), 'lifo', ['allowed' => ['fifo', 'average']]))->toBe('fifo');
});

it('compares every ordered type exactly', function (RuleType $type, mixed $smaller, mixed $larger) {
    expect(ruleValidator()->compare(ruleOf($type), $smaller, $larger))->toBe(-1)
        ->and(ruleValidator()->compare(ruleOf($type), $larger, $smaller))->toBe(1)
        ->and(ruleValidator()->compare(ruleOf($type), $larger, $larger))->toBe(0);
})->with([
    'decimal' => [RuleType::Decimal, '1.25', '1.3'],
    'decimal across signs' => [RuleType::Decimal, '-2.5', '0.1'],
    'negative decimals' => [RuleType::Decimal, '-10', '-9.99'],
    'money' => [RuleType::Money, ['amount' => 100, 'currency' => 'BDT'], ['amount' => 250, 'currency' => 'BDT']],
    'duration' => [RuleType::Duration, 'PT45M', 'P1DT1H'],
    'time' => [RuleType::Time, '09:00', '17:30'],
    'date' => [RuleType::Date, '01-01', '07-01'],
]);

it('treats zero the same with or without a sign or decimals', function () {
    expect(RuleValueValidator::compareDecimal('-0', '0.000'))->toBe(0)
        ->and(RuleValueValidator::compareDecimal('+012.50', '12.5'))->toBe(0);
});

it('reads every rule of a module at once', function () {
    $w = tenancyWorld();
    $values = app(RuleResolver::class)->getMany('attendance.', app(RuleContextFactory::class)->forOrganization($w->c1));

    expect(array_keys($values))->toContain('attendance.late_grace_minutes')
        ->and(collect(array_keys($values))->every(fn (string $key) => str_starts_with($key, 'attendance.')))->toBeTrue();
});

it('ignores a stored value that no longer fits its rule, and says so', function () {
    $w = tenancyWorld();
    RuleValue::create([
        'rule_key' => 'attendance.late_grace_minutes', 'scope_type' => 'company', 'scope_id' => $w->c1->id,
        'mode' => 'set', 'value' => 'ten minutes', 'version' => 1, 'status' => 'active',
        'effective_from' => now()->subDay(), 'reason' => 'Old data',
    ]);

    $resolved = app(RuleResolver::class)->explain('attendance.late_grace_minutes', app(RuleContextFactory::class)->forOrganization($w->c1->fresh()));

    expect($resolved->value)->toBeInt()
        ->and(collect($resolved->trace)->pluck('note'))->toContain('ignored_invalid_value');
});
