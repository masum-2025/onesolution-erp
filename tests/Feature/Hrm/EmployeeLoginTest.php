<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Attendance\Export\AttendanceExporter;
use Modules\Hrm\Directory\EmployeeDirectory;

/*
 * ATT-1 in HRM: linking an employee to the login they use (members of the
 * company only, one employee per login), and the directory other modules
 * read employees through.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    $this->employee = hireVia($this, $this->w->token, $this->w->b1)->assertCreated()->json('data');
    $this->other = hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'Karim Hossain'])->assertCreated()->json('data');
    $this->url = fn (array $employee) => "/api/organizations/{$this->w->c1->id}/hrm/employees/{$employee['id']}/login";
});

it('links an employee to a login of the company, one employee per login', function () {
    $member = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['attendance.punch'], 'Worker'));
    $stranger = User::factory()->create();

    $this->asToken($this->w->token)->putJson(($this->url)($this->employee), ['base_version' => $this->employee['version'], 'user_id' => $stranger->id])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $linked = $this->asToken($this->w->token)->putJson(($this->url)($this->employee), ['base_version' => $this->employee['version'], 'user_id' => $member->id])
        ->assertOk()->json('data');
    expect($linked['user_id'])->toBe($member->id);
    $this->asToken($this->w->token)->putJson(($this->url)($this->other), ['base_version' => $this->other['version'], 'user_id' => $member->id])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->asToken($this->w->token)->putJson(($this->url)($this->employee), ['base_version' => $this->employee['version'], 'user_id' => null])
        ->assertConflict()->assertJsonPath('code', 'version_conflict');

    expect(app(EmployeeDirectory::class)->forUser($this->w->c1, $member)?->id)->toBe($this->employee['id'])
        ->and(AuditLog::query()->where('action', 'hrm.login_linked')->sole()->new_values)->toBe(['user_id' => $member->id]);

    $this->asToken($this->w->token)->putJson(($this->url)($this->employee), ['base_version' => $linked['version'], 'user_id' => null])->assertOk()->assertJsonPath('data.user_id', null);
    expect(app(EmployeeDirectory::class)->forUser($this->w->c1, $member))->toBeNull();
});

it('offers the company\'s members as logins to link, not those taken', function () {
    $amina = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['attendance.punch'], 'Worker'));
    $amina->forceFill(['name' => 'Amina Begum'])->save();
    $taken = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker 2'));
    $this->asToken($this->w->token)->putJson(($this->url)($this->other), ['base_version' => $this->other['version'], 'user_id' => $taken->id])->assertOk();
    staffWithRoles($this->w->c2, makeRole($this->w->c2, ['attendance.punch'], 'Elsewhere'));
    $logins = "/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->employee['id']}/logins";

    $ids = collect($this->asToken($this->w->token)->getJson($logins)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($amina->id)->not->toContain($taken->id)
        ->and($this->asToken($this->w->token)->getJson("{$logins}?search=Amina")->json('data'))->toBe([['id' => $amina->id, 'name' => 'Amina Begum', 'email' => $amina->email]]);

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Viewer')), $this->w->c1);
    $this->asToken($viewer)->getJson($logins)->assertForbidden();
    $linked = $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->other['id']}")->json('data.login');
    expect($linked)->toMatchArray(['id' => $taken->id, 'email' => $taken->email]);
});

it('keeps linking to HR managers of the unit', function () {
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Viewer')), $this->w->c1);
    $this->asToken($viewer)->putJson(($this->url)($this->employee), ['base_version' => 1, 'user_id' => null])->assertForbidden();

    $c2Owner = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($c2Owner)->putJson("/api/organizations/{$this->w->c2->id}/hrm/employees/{$this->employee['id']}/login", ['base_version' => 1, 'user_id' => null])->assertNotFound();
});

it('lists employees of a unit and below it on a day, and hands attendance to the data export', function () {
    hireVia($this, $this->w->token, $this->w->d1, ['full_name' => 'Later Joiner', 'joined_on' => '2026-11-01'])->assertCreated();
    $directory = app(EmployeeDirectory::class);
    $units = Organization::query()->subtreeOf($this->w->b1)->pluck('id')->all();

    expect(array_map(fn ($record) => $record->name, $directory->inUnits($this->w->c1, $units, CarbonImmutable::parse('2026-10-15'))))->toBe(['Karim Hossain', 'Rahima Akter'])
        ->and($directory->inUnits($this->w->c1, $units))->toHaveCount(3)
        ->and($directory->find($this->w->c2, $this->employee['id']))->toBeNull();

    app(CurrentContext::class)->clear();
    $datasets = app(AttendanceExporter::class)->export($this->w->c1, Organization::query()->subtreeOf($this->w->c1)->pluck('id')->all());
    expect(array_keys($datasets))->toBe(['shifts', 'holidays', 'rosters', 'punches', 'locations', 'days', 'corrections']);
});
