<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Models\RuleDefinitionRecord;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleCatalog;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RulesSeeder;

beforeEach(function () {
    $this->w = tenancyWorld();
});

it('seeds country values that apply to organizations of that country', function () {
    $this->seed(RulesSeeder::class);
    // Weekend and fiscal year come from the country data file (Phase 6).
    $this->seed(CountriesSeeder::class);

    expect(ruleFor('payroll.overtime_multiplier', $this->w->c1))->toBe('2.0')
        ->and(ruleFor('attendance.weekend_days', $this->w->c1))->toBe(['fri'])
        ->and(ruleFor('accounting.fiscal_year_start', $this->w->c1))->toBe('07-01')
        ->and(ruleFor('payroll.tax_slabs', $this->w->c1))->toHaveCount(7)
        ->and(ruleFor('offline_mode.max_cached_records', $this->w->c1))->toBe(2000);

    setPlan($this->w->g1, 'enterprise');
    expect(ruleFor('offline_mode.max_cached_records', $this->w->c1))->toBe(50000);
});

it('can be run again without duplicating values', function () {
    $this->seed(RulesSeeder::class);
    $count = RuleValue::count();

    $this->seed(RulesSeeder::class);

    expect(RuleValue::count())->toBe($count);
});

it('syncs definitions and marks removed rules as deprecated', function () {
    RuleDefinitionRecord::create([
        'key' => 'legacy.old_rule', 'module_key' => 'legacy', 'type' => 'integer', 'label' => 'x', 'description' => 'x',
        'overridable_levels' => ['platform'], 'edit_permission' => 'rules.edit.legacy', 'category' => 'general',
    ]);

    $this->artisan('rules:sync')->assertSuccessful();

    expect(RuleDefinitionRecord::whereNull('deprecated_at')->count())->toBe(count(app(RuleCatalog::class)->all()))
        ->and(RuleDefinitionRecord::where('key', 'legacy.old_rule')->value('deprecated_at'))->not->toBeNull();
});

it('sets platform values from the command line with an audit trail', function () {
    $this->artisan('rules:set', [
        'key' => 'attendance.late_grace_minutes',
        'value' => '15',
        '--reason' => 'Platform default review',
    ])->assertSuccessful();

    expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(15)
        ->and(AuditLog::where('action', 'rule.changed')->sole()->reason)->toBe('Platform default review');
});

it('refuses invalid command line input', function (array $arguments) {
    $this->artisan('rules:set', ['key' => 'attendance.late_grace_minutes', '--reason' => 'Platform default', ...$arguments])
        ->assertFailed();
})->with([
    'not json' => [['value' => 'fifteen']],
    'out of range' => [['value' => '999']],
    'short reason' => [['value' => '5', '--reason' => 'x']],
]);

it('explains a rule from the command line', function () {
    $this->artisan('rules:explain', ['key' => 'attendance.late_grace_minutes', '--organization' => $this->w->c1->id])
        ->expectsOutputToContain('Effective value: 10')
        ->assertSuccessful();
});
