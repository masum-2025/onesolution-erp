<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditQuery;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\OrganizationChangeForbidden;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Hrm\Enums\EmploymentEventType;
use Modules\Hrm\Events\EmploymentChanged;
use Modules\Hrm\Models\Employee;

/*
 * HRM-1: hiring and editing employees, with every business number from the
 * rules, masked ids, and the usual walls between companies and partners.
 */

beforeEach(function () {
    $this->w = hrmWorld($this);
});

it('hires with a code and probation from the rules, records the step and tells other modules', function () {
    Event::fake([EmploymentChanged::class]);

    $data = hireVia($this, $this->w->token, $this->w->c1, ['national_id' => '1990 1234 5678'])->assertCreated()->json('data');

    expect($data['employee_code'])->toBe('EMP-2026-0001')
        ->and($data['status'])->toBe('probation')
        ->and($data['probation_ends_on'])->toBe('2026-12-30') // 90 days (rule default)
        ->and($data['national_id'])->toBe('••••5678')
        ->and($data['unit']['id'])->toBe($this->w->c1->id);

    $second = hireVia($this, $this->w->token, $this->w->c1, ['full_name' => 'Karim Uddin'])->json('data');
    expect($second['employee_code'])->toBe('EMP-2026-0002');

    $employee = Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->findOrFail($data['id']);
    expect($employee->events()->sole()->type)->toBe(EmploymentEventType::Hired)
        ->and(AuditLog::query()->where('action', 'hrm.employee_hired')->count())->toBe(2)
        ->and(app(AuditQuery::class)->label('hrm.employee_hired'))->toBe('Employee hired');

    Event::assertDispatched(EmploymentChanged::class, fn (EmploymentChanged $event) => $event->name() === 'hrm.employee.hired' && $event->employeeId === $data['id']);
});

it('follows the company\'s own code format, probation and kinds of employment', function () {
    orgRule($this->w->c1, 'hrm.employee_code_format', 'T{UNIT}-{YY}-{SEQ:3}');
    orgRule($this->w->c1, 'hrm.probation_days', 0);
    orgRule($this->w->c1, 'hrm.employment_types', ['permanent']);

    $data = hireVia($this, $this->w->token, $this->w->c1)->assertCreated()->json('data');
    expect($data['employee_code'])->toBe('TC1-26-001')
        ->and($data['status'])->toBe('active')
        ->and($data['probation_ends_on'])->toBeNull();

    hireVia($this, $this->w->token, $this->w->c1, ['employment_type' => 'daily_wage'])
        ->assertUnprocessable()->assertJsonValidationErrors('employment_type');

    // Codes run per company: C2 starts again at 1.
    $c2Owner = createMember($this->w->c2);
    expect(hireVia($this, orgToken($c2Owner, $this->w->c2), $this->w->c2)->json('data.employee_code'))->toBe('EMP-2026-0001');
});

it('asks for the details the rules require and refuses a national id used in the company', function () {
    orgRule($this->w->c1, 'hrm.required_fields', ['phone', 'national_id']);

    hireVia($this, $this->w->token, $this->w->c1)->assertUnprocessable()->assertJsonValidationErrors('national_id');
    hireVia($this, $this->w->token, $this->w->c1, ['national_id' => '1990-1234-5678'])->assertCreated();

    // Same id, written differently, in another unit of the company.
    hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'Someone Else', 'national_id' => '199012345678'])
        ->assertUnprocessable()->assertJsonValidationErrors('national_id');

    // Unknown fields and wrong shapes are refused before any rule.
    hireVia($this, $this->w->token, $this->w->c1, ['salary' => 50000])->assertUnprocessable()->assertJsonValidationErrors('salary');
    hireVia($this, $this->w->token, $this->w->c1, ['gender' => 'robot'])->assertUnprocessable()->assertJsonValidationErrors('gender');
});

it('keeps manager links inside the company', function () {
    $c2Owner = createMember($this->w->c2);
    $stranger = hireVia($this, orgToken($c2Owner, $this->w->c2), $this->w->c2)->json('data.id');
    $boss = hireVia($this, $this->w->token, $this->w->c1, ['full_name' => 'Boss'])->json('data.id');

    hireVia($this, $this->w->token, $this->w->c1, ['manager_id' => $stranger])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
    hireVia($this, $this->w->token, $this->w->c1, ['manager_id' => $boss])->assertCreated()->assertJsonPath('data.manager.id', $boss);
});

it('stores ids encrypted and shows them in full only with the permission, audited', function () {
    $id = hireVia($this, $this->w->token, $this->w->c1, ['national_id' => '1990123456789', 'tax_id' => 'TIN-554433'])->json('data.id');

    $raw = DB::table('hrm_employees')->where('id', $id)->first();
    expect($raw->national_id)->not->toContain('1990123456789')
        ->and($raw->tax_id)->not->toContain('554433');

    // A clerk who may read HRM but not the full ids.
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Clerk'));
    $clerkToken = orgToken($clerk, $this->w->c1);
    $this->asToken($clerkToken)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$id}")->assertOk()->assertJsonPath('data.tax_id', '••••4433');
    $this->asToken($clerkToken)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$id}/sensitive")->assertForbidden();

    $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$id}/sensitive")
        ->assertOk()->assertJsonPath('data.national_id', '1990123456789')->assertHeader('Cache-Control', 'no-store, private');
    expect(AuditLog::query()->where('action', 'hrm.sensitive_viewed')->sole()->actor_user_id)->toBe($this->w->owner->id);
});

it('never shows or touches employees of another company or partner (IDOR)', function () {
    $mine = hireVia($this, $this->w->token, $this->w->c1)->json('data.id');

    foreach ([$this->w->c2, $this->w->c3, $this->w->c4] as $other) {
        $token = orgToken(createMember($other), $other);
        $this->asToken($token)->getJson("/api/organizations/{$other->id}/hrm/employees/{$mine}")->assertNotFound();
        $this->asToken($token)->patchJson("/api/organizations/{$other->id}/hrm/employees/{$mine}", ['base_version' => 1, 'full_name' => 'Hacked'])->assertNotFound();
        $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$mine}")->assertNotFound();
        expect($this->asToken($token)->getJson("/api/organizations/{$other->id}/hrm/employees")->json('data'))->toBe([]);
    }

    expect(Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->findOrFail($mine)->full_name)->toBe('Rahima Akter');
});

it('shows a branch admin their branch only and keeps group admins read-only', function () {
    hireVia($this, $this->w->token, $this->w->c1, ['full_name' => 'At head office']);
    hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'At the branch']);

    $branchAdmin = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['hrm.view', 'hrm.manage'], 'Branch HR'));
    $names = $this->asToken(orgToken($branchAdmin, $this->w->b1))->getJson("/api/organizations/{$this->w->b1->id}/hrm/employees")->json('data.*.full_name');
    expect($names)->toBe(['At the branch']);

    $groupAdmin = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);
    $token = orgToken($groupAdmin, $this->w->g1);
    expect($this->asToken($token)->getJson("/api/organizations/{$this->w->g1->id}/hrm/employees")->json('meta.total'))->toBe(2);
    hireVia($this, $token, $this->w->c1, ['full_name' => 'From the group'])->assertForbidden();
});

it('answers 403 while HRM is off, and keeps the data', function () {
    $id = hireVia($this, $this->w->token, $this->w->c1)->json('data.id');
    toggles()->disable($this->w->g1, 'hrm', 'Test setup', confirm: true);

    $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees")->assertForbidden();
    expect(Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->whereKey($id)->exists())->toBeTrue();
});

it('edits details with the version seen, and audits field names only', function () {
    $employee = hireVia($this, $this->w->token, $this->w->c1)->json('data');
    $url = "/api/organizations/{$this->w->c1->id}/hrm/employees/{$employee['id']}";

    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'phone' => '+8801999000000', 'national_id' => '9988776655'])
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.national_id', '••••6655');
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'phone' => '+8801888000000'])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict')->assertJsonPath('current.version', 2);

    // Unit, position and status change only through steps.
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 2, 'organization_id' => $this->w->b1->id])->assertUnprocessable();

    $entry = AuditLog::query()->where('action', 'hrm.employee_updated')->sole();
    expect($entry->new_values)->toBe(['fields' => ['phone', 'national_id']])
        ->and(json_encode($entry->toArray()))->not->toContain('9988776655')->not->toContain('+8801999000000');
});

it('still refuses moving a record to another unit outside an HR step', function () {
    $id = hireVia($this, $this->w->token, $this->w->c1)->json('data.id');
    actInOrganization($this->w->owner, $this->w->c1);

    Employee::query()->findOrFail($id)->forceFill(['organization_id' => $this->w->b1->id])->save();
})->throws(OrganizationChangeForbidden::class);

it('places employees only in a company or its units, never a group', function () {
    $groupOwner = createMember($this->w->g1);
    hireVia($this, orgToken($groupOwner, $this->w->g1), $this->w->g1)->assertUnprocessable()->assertJsonPath('code', 'not_company_unit');

    $department = createChild($this->w->b1, OrganizationType::Department, 'Accounts');
    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $department->id])->assertCreated()
        ->assertJsonPath('data.unit.id', $department->id);
});
