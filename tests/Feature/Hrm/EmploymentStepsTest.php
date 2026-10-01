<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Event;
use Modules\Hrm\Events\EmploymentChanged;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmploymentEvent;
use Modules\Hrm\Models\Position;
use Modules\Hrm\Services\EmployeeLifecycle;

/*
 * HRM-1: employment steps. Each one checks the employee's state, uses the
 * version seen, leaves an append-only event and tells other modules.
 */

beforeEach(function () {
    $this->w = hrmWorld($this);
    $this->employee = hireVia($this, $this->w->token, $this->w->c1)->json('data');
    $this->base = "/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->employee['id']}";
});

function step(object $test, string $step, array $data, ?string $token = null)
{
    return $test->asToken($token ?? $test->w->token)->postJson("{$test->base}/steps/{$step}", $data);
}

function position(object $test, string $title): string
{
    return $test->asToken($test->w->token)->postJson("/api/organizations/{$test->w->c1->id}/hrm/positions", ['title' => ['en' => $title, 'bn' => $title]])
        ->assertCreated()->json('data.id');
}

it('confirms after probation, once', function () {
    step($this, 'confirm', ['base_version' => 1, 'on' => '2026-12-30'])->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.confirmed_on', '2026-12-30');
    step($this, 'confirm', ['base_version' => 2])->assertUnprocessable()->assertJsonPath('code', 'not_on_probation');
});

it('transfers inside the company only', function () {
    Event::fake([EmploymentChanged::class]);

    step($this, 'transfer', ['base_version' => 1, 'to_organization_id' => $this->w->b1->id, 'reason' => 'New branch'])->assertOk()
        ->assertJsonPath('data.unit.id', $this->w->b1->id);
    // A unit this person cannot see is simply not found (sister company, other partner).
    step($this, 'transfer', ['base_version' => 2, 'to_organization_id' => $this->w->c2->id])->assertNotFound();
    step($this, 'transfer', ['base_version' => 2, 'to_organization_id' => $this->w->c4->id])->assertNotFound();

    // And the step itself never leaves the company (defence in depth).
    actInOrganization($this->w->owner, $this->w->c1);
    $employee = Employee::query()->findOrFail($this->employee['id']);
    expect(fn () => app(EmployeeLifecycle::class)->transfer($employee, 2, $this->w->c2, [], $this->w->owner))
        ->toThrow(HrmException::class, 'other_company');
    step($this, 'transfer', ['base_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('to_organization_id');

    Event::assertDispatched(EmploymentChanged::class, fn ($event) => $event->name() === 'hrm.employee.transferred' && $event->organizationId === $this->w->b1->id);
    expect(AuditLog::query()->where('action', 'hrm.employee_transferred')->sole()->old_values)->toBe(['unit_id' => $this->w->c1->id]);
});

it('promotes to a position of the company, not an inactive one', function () {
    $senior = position($this, 'Senior teacher');
    $old = position($this, 'Old title');
    Position::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->whereKey($old)->update(['is_active' => false]);

    step($this, 'promote', ['base_version' => 1, 'position_id' => $senior])->assertOk()->assertJsonPath('data.position.id', $senior);
    step($this, 'promote', ['base_version' => 2, 'position_id' => $senior])->assertUnprocessable()->assertJsonPath('code', 'same_position');
    step($this, 'promote', ['base_version' => 2, 'position_id' => $old])->assertUnprocessable()->assertJsonValidationErrors('position_id');
});

it('takes the notice period from the rules, and needs the exit permission', function () {
    orgRule($this->w->c1, 'hrm.notice_period_days', 60);

    $manager = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR clerk'));
    step($this, 'notice', ['base_version' => 1], orgToken($manager, $this->w->c1))->assertForbidden();

    step($this, 'notice', ['base_version' => 1, 'on' => '2026-11-01'])->assertOk()
        ->assertJsonPath('data.status', 'on_notice')->assertJsonPath('data.exits_on', '2026-12-31');
});

it('ends employment with a reason and a date not before joining, then rehires', function () {
    step($this, 'exit', ['base_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
    step($this, 'exit', ['base_version' => 1, 'on' => '2026-09-01', 'reason' => 'Resigned'])->assertUnprocessable()->assertJsonValidationErrors('on');

    step($this, 'exit', ['base_version' => 1, 'on' => '2026-11-15', 'reason' => 'Resigned'])->assertOk()
        ->assertJsonPath('data.status', 'exited')->assertJsonPath('data.exits_on', '2026-11-15');
    step($this, 'transfer', ['base_version' => 2, 'to_organization_id' => $this->w->b1->id])->assertUnprocessable()->assertJsonPath('code', 'not_employed');

    step($this, 'rehire', ['base_version' => 2, 'on' => '2027-02-01'])->assertOk()
        ->assertJsonPath('data.status', 'probation')->assertJsonPath('data.joined_on', '2027-02-01')->assertJsonPath('data.exits_on', null);
    step($this, 'rehire', ['base_version' => 3])->assertUnprocessable()->assertJsonPath('code', 'already_employed');
});

it('lists the history newest first, and never changes it', function () {
    step($this, 'confirm', ['base_version' => 1, 'on' => '2026-12-30']);
    step($this, 'transfer', ['base_version' => 2, 'on' => '2027-01-15', 'to_organization_id' => $this->w->b1->id]);

    $history = $this->asToken($this->w->token)->getJson("{$this->base}/history")->assertOk()->json('data');
    expect(array_column($history, 'type'))->toBe(['transferred', 'confirmed', 'hired'])
        ->and($history[0]['label'])->toBe('Transferred');

    $event = EmploymentEvent::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('employee_id', $this->employee['id'])->firstOrFail();
    expect(fn () => $event->forceFill(['reason' => 'rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('refuses a stale version for every step', function () {
    step($this, 'confirm', ['base_version' => 1]);

    step($this, 'transfer', ['base_version' => 1, 'to_organization_id' => $this->w->b1->id])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
});

it('only knows the steps it has', function () {
    $this->asToken($this->w->token)->postJson("{$this->base}/steps/fire", ['base_version' => 1])->assertNotFound()->assertJsonPath('code', 'unknown_step');
});
