<?php

use App\Platform\Tenancy\Enums\MembershipType;
use Carbon\CarbonImmutable;

/*
 * UI-1b: every module has a dashboard and a settings page; HRM has real widgets.
 */

beforeEach(function () {
    // 15 October in Dhaka (the world's group timezone); before any sign-in token is made.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00', 'UTC'));
    $this->w = hrmWorld($this);
    $this->base = '/api/modules/hrm/dashboard';
});

function dashboardPosition(object $test, string $title): string
{
    return $test->asToken($test->w->token)->postJson("/api/organizations/{$test->w->c1->id}/hrm/positions", ['title' => ['en' => $title, 'bn' => $title]])
        ->assertCreated()->json('data.id');
}

/** Four people: one since April, one since September, two this month. */
function dashboardStaff(object $test): void
{
    $teacher = dashboardPosition($test, 'Teacher');
    $accountant = dashboardPosition($test, 'Accountant');

    foreach ([
        ['Anika Rahman', '2026-04-01', $teacher, '+8801711000001'],
        ['Bilal Hossain', '2026-10-01', $teacher, '+8801711000002'],
        ['Chandni Akter', '2026-10-05', $accountant, '+8801711000003'],
        ['Dipu Saha', '2026-09-10', null, '+8801711000004'],
    ] as [$name, $joined, $position, $phone]) {
        hireVia($test, $test->w->token, $test->w->c1, array_filter([
            'full_name' => $name, 'joined_on' => $joined, 'position_id' => $position, 'phone' => $phone,
        ]))->assertCreated();
    }
}

it('lists the widgets of a module the person may see', function () {
    $this->asToken($this->w->token)->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('module', ['key' => 'hrm', 'name' => 'Human Resources', 'description' => 'Employees, positions and employment records.'])
        ->assertJsonPath('data.0', ['module' => 'hrm', 'module_name' => 'Human Resources', 'key' => 'headcount', 'label' => 'Employees', 'type' => 'stat', 'size' => 1])
        ->assertJsonCount(6, 'data');
});

it('counts employees, joiners and probation from the records', function () {
    dashboardStaff($this);

    $this->asToken($this->w->token)->getJson("{$this->base}/headcount")->assertOk()
        ->assertJsonPath('data.value', 4)
        ->assertJsonPath('data.change', ['value' => 2, 'direction' => 'up', 'tone' => 'neutral'])
        ->assertJsonPath('data.series', [1, 1, 1, 1, 2, 4]);

    $this->asToken($this->w->token)->getJson("{$this->base}/joiners")->assertOk()
        ->assertJsonPath('data.value', 2)
        ->assertJsonPath('data.change.value', 1)
        ->assertJsonPath('data.series', [0, 0, 0, 0, 1, 2]);

    $this->asToken($this->w->token)->getJson("{$this->base}/probation")->assertOk()
        ->assertJsonPath('data.value', 4)
        ->assertJsonPath('data.hint', 'None end in the next 30 days');
});

it('groups employees by position and lists the latest changes', function () {
    dashboardStaff($this);

    $this->asToken($this->w->token)->getJson("{$this->base}/by_position")->assertOk()
        ->assertJsonPath('data.items', [
            ['label' => 'Teacher', 'value' => 2],
            ['label' => 'Accountant', 'value' => 1],
            ['label' => 'No position', 'value' => 1],
        ]);

    $recent = $this->asToken($this->w->token)->getJson("{$this->base}/recent")->assertOk()->json('data.items');

    expect(array_column($recent, 'label'))->toBe(['Chandni Akter', 'Bilal Hossain', 'Dipu Saha', 'Anika Rahman'])
        ->and($recent[0]['meta'])->toBe('Hired')
        ->and($recent[0]['date'])->toBe('2026-10-05')
        ->and($recent[0]['tone'])->toBe('good')
        ->and($recent[0]['path'])->toStartWith('/hrm/employees/');
});

it('speaks Bangla', function () {
    $this->w->g1->update(['default_locale' => 'bn']);
    $token = orgToken(withoutOwnLanguage($this->w->owner), $this->w->c1);

    $this->asToken($token)->getJson($this->base)->assertJsonPath('data.0.label', 'কর্মী');
    $this->asToken($token)->getJson("{$this->base}/probation")->assertJsonPath('data.hint', 'আগামী ৩০ দিনে কারও শেষ হচ্ছে না');
});

it('shows nothing to people without the permission', function () {
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['audit.view'], 'Clerk'));
    $token = orgToken($clerk, $this->w->c1);

    $this->asToken($token)->getJson($this->base)->assertOk()->assertJsonCount(0, 'data');
    $this->asToken($token)->getJson("{$this->base}/headcount")->assertForbidden();
    $this->asToken($token)->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses a module that is off', function (string $path) {
    toggles()->disable($this->w->g1, 'hrm', 'Stop HR');

    $this->asToken($this->w->token)->getJson($path)->assertForbidden()->assertJsonPath('code', 'module_disabled');
})->with(['/api/modules/hrm/dashboard', '/api/modules/hrm/dashboard/headcount', '/api/modules/hrm/settings']);

it('does not know modules or widgets that do not exist', function (string $path) {
    $this->asToken($this->w->token)->getJson($path)->assertNotFound();
})->with(['/api/modules/ghost/dashboard', '/api/modules/hrm/dashboard/ghost', '/api/modules/ghost/settings', '/api/modules/HRM/dashboard']);

it('counts only the units the person works in', function () {
    dashboardStaff($this);
    hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'Esha Branch', 'joined_on' => '2026-10-02', 'phone' => '+8801711000005'])->assertCreated();
    $branchHr = createMember($this->w->b1, MembershipType::Owner);

    $this->asToken(orgToken($branchHr, $this->w->b1))->getJson("{$this->base}/headcount")->assertJsonPath('data.value', 1);
    $this->asToken($this->w->token)->getJson("{$this->base}/headcount")->assertJsonPath('data.value', 5);
});

it('never counts another organization or partner', function () {
    dashboardStaff($this);

    foreach (['c2', 'c4'] as $company) {
        $token = orgToken(createMember($this->w->{$company}, MembershipType::Owner), $this->w->{$company});

        $this->asToken($token)->getJson("{$this->base}/headcount")->assertJsonPath('data.value', 0);
        $this->asToken($token)->getJson("{$this->base}/recent")->assertJsonPath('data.items', []);
    }
});

it('gathers the overview widgets of modules that are on', function () {
    $this->asToken($this->w->token)->getJson('/api/dashboard')->assertOk()
        ->assertJsonPath('data.*.key', ['headcount', 'joiners', 'probation', 'expiring']);

    toggles()->disable($this->w->g1, 'hrm', 'Stop HR');

    $this->asToken($this->w->token)->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'data');
});

it('shows a module settings page with the setup screens the person may open', function () {
    $this->asToken($this->w->token)->getJson('/api/modules/hrm/settings')->assertOk()
        ->assertJsonPath('data.pages.*.key', ['positions', 'fields'])
        ->assertJsonPath('data.rule_count', 13)
        ->assertJsonPath('data.can_manage_modules', true);

    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'HR viewer'));
    $this->asToken(orgToken($viewer, $this->w->c1))->getJson('/api/modules/hrm/settings')->assertOk()
        ->assertJsonPath('data.pages.*.key', ['positions'])
        ->assertJsonPath('data.can_manage_modules', false);
});

it('gives every module a dashboard and a settings page, even before it has widgets', function () {
    toggles()->enable($this->w->g1, 'api_integration', 'Integrations');

    $this->asToken($this->w->token)->getJson('/api/modules/api_integration/dashboard')->assertOk()->assertJsonPath('data', []);
    $this->asToken($this->w->token)->getJson('/api/modules/api_integration/settings')->assertOk()->assertJsonPath('data.pages', []);
});
