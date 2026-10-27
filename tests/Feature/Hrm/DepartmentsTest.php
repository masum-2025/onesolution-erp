<?php

use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;

/*
 * HRM-4: departments and designations. A department is a unit of the
 * organization tree under a branch (or the company); HR sees them per
 * branch with head counts, chooses branch then department when hiring or
 * moving someone, and filters people by them. Closed units take nobody in.
 * Tenancy world: C1 > B1 > D1; C2 > B2 in the same group.
 */

beforeEach(function () {
    $this->w = hrmWorld($this);
    $this->hrm = fn (string $path, ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->c1)->id."/hrm/{$path}";
});

function departmentCsv(array $rows): UploadedFile
{
    $stream = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '');
    }
    rewind($stream);

    return UploadedFile::fake()->createWithContent('staff.csv', "\xEF\xBB\xBF".stream_get_contents($stream));
}

it('lists departments per branch with how many people work in each, and tells employees branch and department apart', function () {
    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $this->w->d1->id, 'full_name' => 'In D1'])->assertCreated();
    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $this->w->b1->id, 'full_name' => 'At B1', 'phone' => '+8801711000009'])->assertCreated();
    $accounts = createChild($this->w->c1, OrganizationType::Department, 'Accounts');

    $places = collect($this->asToken($this->w->token)->getJson(($this->hrm)('departments'))->assertOk()->json('data'))->keyBy('id');
    expect($places->keys()->all())->toBe([$this->w->c1->id, $this->w->b1->id])
        ->and($places[$this->w->b1->id])->toMatchArray(['type' => 'branch', 'employees' => 1, 'can_manage' => true])
        ->and($places[$this->w->b1->id]['departments'])->toHaveCount(1)
        ->and($places[$this->w->b1->id]['departments'][0])->toMatchArray(['id' => $this->w->d1->id, 'employees' => 1, 'status' => 'active'])
        ->and(array_column($places[$this->w->c1->id]['departments'], 'id'))->toBe([$accounts->id]);

    $people = collect($this->asToken($this->w->token)->getJson(($this->hrm)('employees'))->assertOk()->json('data'))->keyBy('full_name');
    expect($people['In D1']['branch'])->toBe(['id' => $this->w->b1->id, 'name' => 'B1'])
        ->and($people['In D1']['department'])->toBe(['id' => $this->w->d1->id, 'name' => 'D1'])
        ->and($people['At B1']['branch']['id'])->toBe($this->w->b1->id)
        ->and($people['At B1']['department'])->toBeNull();

    // Filtering: the department alone, the branch with its departments.
    $names = fn (string $unit) => collect($this->asToken($this->w->token)->getJson(($this->hrm)("employees?unit_id={$unit}"))->assertOk()->json('data'))->pluck('full_name')->sort()->values()->all();
    expect($names($this->w->d1->id))->toBe(['In D1'])
        ->and($names($this->w->b1->id))->toBe(['At B1', 'In D1']);
});

it('makes, renames and closes departments only for people who may change the organization tree', function () {
    $hr = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR'));
    $hrToken = orgToken($hr, $this->w->c1);

    // HR alone sees departments but cannot make them.
    expect($this->asToken($hrToken)->getJson(($this->hrm)('departments'))->assertOk()->json('data.0.can_manage'))->toBeFalse();
    $this->asToken($hrToken)->postJson('/api/organizations', ['parent_id' => $this->w->b1->id, 'type' => 'department', 'name' => ['en' => 'Accounts']])->assertForbidden();

    $made = $this->asToken($this->w->token)->postJson('/api/organizations', ['parent_id' => $this->w->b1->id, 'type' => 'department', 'name' => ['en' => 'Accounts', 'bn' => 'হিসাব']])
        ->assertCreated()->json('data');
    $this->asToken($this->w->token)->patchJson("/api/organizations/{$made['id']}", ['name' => ['en' => 'Finance', 'bn' => 'অর্থ']])->assertOk();
    $this->asToken($this->w->token)->patchJson("/api/organizations/{$made['id']}", ['status' => 'archived'])->assertOk();

    $b1 = collect($this->asToken($this->w->token)->getJson(($this->hrm)('departments'))->json('data'))->firstWhere('id', $this->w->b1->id);
    expect(collect($b1['departments'])->firstWhere('id', $made['id']))->toMatchArray(['name' => 'Finance', 'names' => ['en' => 'Finance', 'bn' => 'অর্থ'], 'status' => 'archived']);
});

it('takes nobody into a closed department, by hiring or transfer', function () {
    $employee = hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $this->w->b1->id])->assertCreated()->json('data');
    $this->asToken($this->w->token)->patchJson("/api/organizations/{$this->w->d1->id}", ['status' => 'archived'])->assertOk();

    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $this->w->d1->id, 'full_name' => 'Late', 'phone' => '+8801711000008'])
        ->assertStatus(422)->assertJsonPath('code', 'unit_closed');
    $this->asToken($this->w->token)->postJson(($this->hrm)("employees/{$employee['id']}/steps/transfer"), [
        'base_version' => $employee['version'], 'to_organization_id' => $this->w->d1->id, 'on' => '2026-10-20',
    ])->assertStatus(422)->assertJsonPath('code', 'unit_closed');

    // Open again: the move goes through, and the history keeps it.
    $this->asToken($this->w->token)->patchJson("/api/organizations/{$this->w->d1->id}", ['status' => 'active'])->assertOk();
    $moved = $this->asToken($this->w->token)->postJson(($this->hrm)("employees/{$employee['id']}/steps/transfer"), [
        'base_version' => $employee['version'], 'to_organization_id' => $this->w->d1->id, 'on' => '2026-10-20',
    ])->assertOk()->json('data');
    expect($moved['department']['id'])->toBe($this->w->d1->id)->and($moved['branch']['id'])->toBe($this->w->b1->id);
});

it('places imported people in a department by its name in either language, within their branch', function () {
    $this->w->d1->putTexts('name', ['en' => 'Accounts', 'bn' => 'হিসাব'])->save();
    $file = departmentCsv([
        ['full_name', 'employment_type', 'joined_on', 'phone', 'unit_code', 'department'],
        ['Rahima Akter', 'permanent', '2026-10-01', '+8801711000001', 'B1', 'accounts'],
        ['Karim Uddin', 'permanent', '2026-10-01', '+8801711000002', 'B1', 'হিসাব'],
        ['Salma Begum', 'permanent', '2026-10-01', '+8801711000003', 'B1', 'Kitchen'],
        // The company has no department of that name: departments belong to their branch.
        ['Jamal Hossain', 'permanent', '2026-10-01', '+8801711000004', '', 'Accounts'],
    ]);

    $checked = $this->asToken($this->w->token)->post(($this->hrm)('imports'), ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data');
    $problems = collect($checked['problems'])->mapWithKeys(fn ($row) => [$row['name'] => array_keys($row['errors'])])->all();
    expect($checked['valid_rows'])->toBe(2)
        ->and($problems)->toBe(['Salma Begum' => ['department'], 'Jamal Hossain' => ['department']]);

    $this->asToken($this->w->token)->postJson(($this->hrm)("imports/{$checked['id']}/start"), ['skip_invalid' => true])->assertStatus(202);
    $people = collect($this->asToken($this->w->token)->getJson(($this->hrm)('employees'))->json('data'))->keyBy('full_name');
    expect($people['Rahima Akter']['department']['id'])->toBe($this->w->d1->id)
        ->and($people['Karim Uddin']['department']['id'])->toBe($this->w->d1->id);
});

it('counts the people of each designation, and by department on the dashboard', function () {
    $teacher = $this->asToken($this->w->token)->postJson(($this->hrm)('positions'), ['title' => ['en' => 'Teacher', 'bn' => 'শিক্ষক']])->assertCreated()->json('data');
    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $this->w->d1->id, 'position_id' => $teacher['id']])->assertCreated();
    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $this->w->b1->id, 'full_name' => 'No Department', 'phone' => '+8801711000007'])->assertCreated();

    expect(collect($this->asToken($this->w->token)->getJson(($this->hrm)('positions'))->json('data'))->firstWhere('id', $teacher['id'])['employees'])->toBe(1);

    $bars = $this->asToken($this->w->token)->getJson("/api/modules/hrm/dashboard/by_department?lang=en")->assertOk()->json('data.items');
    expect($bars)->toBe([['label' => 'D1', 'value' => 1], ['label' => 'In no department', 'value' => 1]]);
});

it('keeps departments to their company and to the module being on', function () {
    $other = createChild($this->w->b2, OrganizationType::Department, 'Other D');
    $places = collect($this->asToken($this->w->token)->getJson(($this->hrm)('departments'))->json('data'));
    expect($places->pluck('departments')->flatten(1)->pluck('id')->all())->not->toContain($other->id);

    hireVia($this, $this->w->token, $this->w->c1, ['organization_id' => $other->id])->assertNotFound();
    $this->asToken($this->w->token)->getJson(($this->hrm)('departments', $this->w->c2))->assertNotFound();

    toggles()->disable($this->w->g1, 'hrm', 'Test');
    $this->asToken($this->w->token)->getJson(($this->hrm)('departments'))->assertForbidden();
});
