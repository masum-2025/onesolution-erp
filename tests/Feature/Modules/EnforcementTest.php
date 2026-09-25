<?php

use App\Platform\Modules\ModuleScheduler;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\RunPayrollFixtureJob;

beforeEach(function () {
    // A route wired exactly like a real module route.
    Route::middleware(['api', 'auth:sanctum', 'org', 'module:payroll'])
        ->get('api/test-payroll', fn () => ['ok' => true]);

    RunPayrollFixtureJob::$ranFor = [];
    $this->w = tenancyWorld();
    $this->user = createMember($this->w->c1, MembershipType::Staff);
    $this->token = orgToken($this->user, $this->w->c1);
});

it('returns 403 with a clear message when the module is off', function () {
    $this->asToken($this->token)->getJson('/api/test-payroll')
        ->assertForbidden()
        ->assertJsonPath('code', 'module_disabled')
        ->assertJsonPath('message', 'Payroll is turned off for your organization. Ask an administrator to turn it on.');
});

it('lets the request through once the module is on', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');

    $this->asToken($this->token)->getJson('/api/test-payroll')->assertOk();
});

it('blocks again as soon as the module is turned off', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');
    $this->asToken($this->token)->getJson('/api/test-payroll')->assertOk();

    toggles()->disable($this->w->c1, 'payroll', 'Stop payroll');
    $this->asToken($this->token)->getJson('/api/test-payroll')->assertForbidden();
});

it('blocks a branch user when the group locked the module off', function () {
    toggles()->enable($this->w->c1, 'payroll', 'Start running payroll');
    toggles()->disable($this->w->g1, 'payroll', 'Group payroll freeze', lock: true);

    $this->asToken($this->token)->getJson('/api/test-payroll')->assertForbidden();
});

it('skips queued module jobs for organizations where the module is off', function () {
    toggles()->enable($this->w->c2, 'payroll', 'C2 runs payroll');

    RunPayrollFixtureJob::dispatch($this->w->c1->id);
    RunPayrollFixtureJob::dispatch($this->w->c2->id);

    expect(RunPayrollFixtureJob::$ranFor)->toBe([$this->w->c2->id]);
});

it('schedules module work only for organizations where it is on', function () {
    toggles()->enable($this->w->c2, 'payroll', 'C2 runs payroll');
    toggles()->enable($this->w->c4, 'payroll', 'C4 runs payroll');

    $companies = app(ModuleScheduler::class)->organizationsWithModule('payroll')->pluck('id')->all();

    expect($companies)->toEqualCanonicalizing([$this->w->c2->id, $this->w->c4->id]);
});
