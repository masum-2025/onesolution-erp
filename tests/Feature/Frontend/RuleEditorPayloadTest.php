<?php

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\MembershipType;

/*
 * The rule editor UI needs readable labels and flat form hints from the API,
 * in the user's language, with names instead of raw user ids.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1);
    toggles()->enable($this->w->c1, 'attendance', 'Needed for the editor tests');
    spaSession($this, $this->owner, $this->w->c1);
});

function editorRule(array $groups, string $key): array
{
    return collect($groups)
        ->flatMap(fn ($group) => collect($group['categories'])->flatMap(fn ($category) => $category['rules']))
        ->firstWhere('key', $key);
}

it('labels categories and enum choices in the chosen language', function (string $locale, string $category, string $friday) {
    $groups = $this->withHeader('X-Locale', $locale)->getJson("/api/organizations/{$this->w->c1->id}/rules")->assertOk()->json('data');
    $rule = editorRule($groups, 'attendance.weekend_days');

    expect($rule['category_label'])->toBe($category)
        ->and(collect($rule['options'])->firstWhere('value', 'fri')['label'])->toBe($friday)
        ->and($rule['module_name'])->toBeString()->not->toBe('');
})->with([
    'English' => ['en', 'Calendar', 'Friday'],
    'Bangla' => ['bn', 'ক্যালেন্ডার', 'শুক্রবার'],
]);

it('sends flat form hints instead of the internal schema', function () {
    $rule = $this->getJson("/api/organizations/{$this->w->c1->id}/rules/attendance.late_grace_minutes")->assertOk()->json('data');

    expect($rule['schema'])->not->toHaveKey('allOf')
        ->and($rule['schema']['minimum'])->toBe(0)
        ->and($rule['schema']['maximum'])->toBe(240)
        ->and($rule['nullable'])->toBeFalse()
        ->and($rule['trace'])->toBeArray();
});

it('marks money columns of table rules for display', function () {
    $rule = $this->getJson("/api/organizations/{$this->w->c1->id}/rules/payroll.tax_slabs")->assertOk()->json('data');

    expect($rule['schema']['items']['properties']['upto_minor']['x-format'])->toBe('money_minor');
});

it('shows who asked for a pending change and where', function () {
    toggles()->enable($this->w->c1, 'accounting', 'Needed for the approval test');
    $maker = createMember($this->w->c1, MembershipType::Owner);

    // A sensitive rule: the change waits for a second owner.
    $row = ruleService()->set(
        app(RuleTargets::class)->organization($this->w->c1),
        'accounting.allow_backdated_entries_days',
        RuleMode::Set,
        3,
        'Tighter period close for C1',
        $maker,
    );
    expect($row->status)->toBe(RuleValueStatus::PendingApproval);

    $item = $this->getJson("/api/organizations/{$this->w->c1->id}/rule-approvals")->assertOk()->json('data.0');

    expect($item['requested_by'])->toBe(['id' => $maker->id, 'name' => $maker->name])
        ->and($item['scope']['name'])->toBe('C1')
        ->and($item['created_at'])->toBeString();
});

it('names the people in the history', function () {
    $this->putJson("/api/organizations/{$this->w->c1->id}/rules/attendance.late_grace_minutes", [
        'mode' => 'set', 'value' => 12, 'reason' => 'Twelve minutes for C1',
    ])->assertOk();

    $entry = $this->getJson("/api/organizations/{$this->w->c1->id}/rules/attendance.late_grace_minutes/history")->assertOk()->json('data.0');

    expect($entry['actor_name'])->toBe($this->owner->name)
        ->and($entry)->not->toHaveKey('actor_email');
});

it('tells the UI which terms version an AI consent refers to', function () {
    $modules = collect($this->getJson("/api/organizations/{$this->w->c1->id}/modules")->assertOk()->json('data'))->keyBy('key');

    expect($modules['ai_assistant']['consent_terms_version'])->toBe(config('platform_modules.consent_terms_version'))
        ->and($modules['attendance']['consent_terms_version'])->toBeNull();
});

it('answers in the language the user picked, not only the organization default', function () {
    $this->w->g1->forceFill(['default_locale' => 'en'])->save();

    $this->withHeader('X-Locale', 'bn')
        ->getJson("/api/organizations/{$this->w->c4->id}")
        ->assertNotFound()
        ->assertJsonPath('message', __('tenancy.errors.organization_not_found', [], 'bn'));
});

it('ignores unsupported languages', function () {
    $this->withHeader('X-Locale', 'xx')
        ->getJson("/api/organizations/{$this->w->c4->id}")
        ->assertNotFound()
        ->assertJsonPath('message', __('tenancy.errors.organization_not_found', [], 'en'));
});
