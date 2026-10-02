<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditQuery;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Testing\TestResponse;
use Modules\Hrm\Models\Employee;

/*
 * HRM-3a: extra employee fields. Set up at a company, branch or department,
 * filled in for that unit and below, checked the same way everywhere.
 */

beforeEach(function () {
    $this->w = hrmWorld($this);
    $this->fields = fn (string $orgId) => "/api/organizations/{$orgId}/hrm/custom-fields";
});

function addField(object $test, string $token, string $orgId, array $data = []): TestResponse
{
    return $test->asToken($token)->postJson("/api/organizations/{$orgId}/hrm/custom-fields", [
        'key' => 'blood_group',
        'type' => 'choice',
        'label' => ['en' => 'Blood group', 'bn' => 'রক্তের গ্রুপ'],
        'options' => [
            ['value' => 'a_pos', 'label' => ['en' => 'A+', 'bn' => 'এ+']],
            ['value' => 'o_pos', 'label' => ['en' => 'O+']],
        ],
        ...$data,
    ]);
}

it('sets up fields at a company and a branch, and the branch sees both with where they come from', function () {
    addField($this, $this->w->token, $this->w->c1->id)->assertCreated()->assertJsonPath('data.source.kind', 'self');
    addField($this, $this->w->token, $this->w->c1->id, ['organization_id' => $this->w->b1->id, 'key' => 'shift', 'type' => 'text', 'label' => ['en' => 'Shift'], 'options' => null])->assertCreated();

    $atBranch = $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/form-options?unit_id={$this->w->b1->id}")->assertOk()->json('data.custom_fields');
    expect(array_column($atBranch, 'key'))->toBe(['blood_group', 'shift'])
        ->and($atBranch[0]['source'])->toBe(['kind' => 'inherited', 'unit' => $this->w->c1->displayName()])
        ->and($atBranch[0]['options'][0])->toMatchArray(['value' => 'a_pos', 'label' => 'A+'])
        ->and($atBranch[1]['source']['kind'])->toBe('self');

    // The company itself does not see the branch's field.
    $atCompany = $this->asToken($this->w->token)->getJson(($this->fields)($this->w->c1->id))->json();
    expect(array_column($atCompany['data'], 'key'))->toBe(['blood_group'])
        ->and($atCompany['meta'])->toBe(['max' => 20, 'in_use' => 2, 'can_configure' => true])
        ->and(app(AuditQuery::class)->label('hrm.custom_field_created'))->toBe('Extra employee field added');
});

it('checks every kind of value when hiring and editing, and clears with null', function () {
    addField($this, $this->w->token, $this->w->c1->id, ['is_required' => true])->assertCreated();
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'experience_years', 'type' => 'number', 'label' => ['en' => 'Experience'], 'options' => null]);
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'licence_until', 'type' => 'date', 'label' => ['en' => 'Licence until'], 'options' => null]);
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'has_vehicle', 'type' => 'yes_no', 'label' => ['en' => 'Has vehicle'], 'options' => null]);

    hireVia($this, $this->w->token, $this->w->c1)->assertUnprocessable()->assertJsonValidationErrors('custom.blood_group');
    hireVia($this, $this->w->token, $this->w->c1, ['custom' => [
        'blood_group' => 'z_neg', 'experience_years' => '12abc', 'licence_until' => '31-12-2027', 'has_vehicle' => 'maybe', 'salary' => 1,
    ]])->assertUnprocessable()->assertJsonValidationErrors(['custom.blood_group', 'custom.experience_years', 'custom.licence_until', 'custom.has_vehicle', 'custom.salary']);

    $hired = hireVia($this, $this->w->token, $this->w->c1, ['custom' => [
        'blood_group' => 'o_pos', 'experience_years' => '007.50', 'licence_until' => '31/12/2027', 'has_vehicle' => 'হ্যাঁ',
    ]])->assertCreated()->json('data');
    expect($hired['custom'])->toBe(['blood_group' => 'o_pos', 'experience_years' => '7.5', 'has_vehicle' => true, 'licence_until' => '2027-12-31']);

    $url = "/api/organizations/{$this->w->c1->id}/hrm/employees/{$hired['id']}";
    // A required field cannot be emptied; others can.
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'custom' => ['blood_group' => null]])->assertUnprocessable()->assertJsonValidationErrors('custom.blood_group');
    $edited = $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'custom' => ['has_vehicle' => null, 'experience_years' => 8]])->assertOk()->json('data');
    expect($edited['custom'])->toBe(['blood_group' => 'o_pos', 'experience_years' => '8', 'licence_until' => '2027-12-31'])
        ->and($edited['version'])->toBe(2);

    $audit = AuditLog::query()->where('action', 'hrm.employee_updated')->sole();
    expect($audit->new_values['fields'])->toEqualCanonicalizing(['custom.has_vehicle', 'custom.experience_years']);
});

it('keeps key and type fixed, keeps options already offered, and keys unique in the company', function () {
    $field = addField($this, $this->w->token, $this->w->c1->id)->json('data');
    $url = ($this->fields)($this->w->c1->id).'/'.$field['id'];

    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'key' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('key');
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'type' => 'text'])->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'options' => [['value' => 'a_pos', 'label' => ['en' => 'A+']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('options');

    $renamed = $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'options' => [
        ['value' => 'a_pos', 'label' => ['en' => 'A positive']], ['value' => 'o_pos', 'label' => ['en' => 'O+']], ['value' => 'b_pos', 'label' => ['en' => 'B+']],
    ]])->assertOk()->json('data');
    expect(array_column($renamed['options'], 'label'))->toBe(['A positive', 'O+', 'B+']);

    // Stale versions are refused.
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'is_required' => true])->assertStatus(409);

    addField($this, $this->w->token, $this->w->b1->id)->assertUnprocessable()->assertJsonValidationErrors('key');
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'Bad-Key'])->assertUnprocessable()->assertJsonValidationErrors('key');
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'empty_choice', 'options' => []])->assertUnprocessable()->assertJsonValidationErrors('options');

    // Another company may use the same key.
    addField($this, orgToken(createMember($this->w->c2), $this->w->c2), $this->w->c2->id)->assertCreated();
});

it('limits fields in use by the rule, and a field switched off is no longer filled in', function () {
    platformRule('hrm.custom_fields_max', 1);
    $field = addField($this, $this->w->token, $this->w->c1->id)->assertCreated()->json('data');
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'second'])->assertUnprocessable()->assertJsonPath('code', 'too_many_fields');

    $id = hireVia($this, $this->w->token, $this->w->c1, ['custom' => ['blood_group' => 'a_pos']])->json('data.id');

    $url = ($this->fields)($this->w->c1->id).'/'.$field['id'];
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'is_active' => false])->assertOk();
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'second'])->assertCreated();
    // Switching it back on would go over the limit.
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 2, 'is_active' => true])->assertUnprocessable()->assertJsonPath('code', 'too_many_fields');

    $this->asToken($this->w->token)->patchJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$id}", ['base_version' => 1, 'custom' => ['blood_group' => 'o_pos']])
        ->assertUnprocessable()->assertJsonValidationErrors('custom.blood_group');

    // The value already given stays (switching off never deletes data).
    $employee = Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->findOrFail($id);
    expect($employee->custom)->toBe(['blood_group' => 'a_pos'])
        ->and($this->asToken($this->w->token)->getJson(($this->fields)($this->w->c1->id).'?all=1')->json('data.0.is_active'))->toBeFalse();
});

it('needs hrm.configure to set fields up, and changes them only where they were set up', function () {
    $manager = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR'));
    addField($this, orgToken($manager, $this->w->c1), $this->w->c1->id)->assertForbidden();

    $configurer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.configure'], 'HR setup'));
    $field = addField($this, orgToken($configurer, $this->w->c1), $this->w->c1->id)->assertCreated()->json('data');

    // Seen at the branch, but changed at the company only.
    $branchAdmin = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['hrm.view', 'hrm.configure'], 'Branch HR'));
    $branchToken = orgToken($branchAdmin, $this->w->b1);
    expect($this->asToken($branchToken)->getJson(($this->fields)($this->w->b1->id))->json('data.0.source.kind'))->toBe('inherited');
    $this->asToken($branchToken)->patchJson(($this->fields)($this->w->b1->id).'/'.$field['id'], ['base_version' => 1, 'is_required' => true])
        ->assertForbidden()->assertJsonPath('code', 'custom_field_elsewhere');
});

it('is closed while HRM is off and invisible to other companies and partners', function () {
    $field = addField($this, $this->w->token, $this->w->c1->id)->json('data');

    $c2Token = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($c2Token)->patchJson(($this->fields)($this->w->c2->id).'/'.$field['id'], ['base_version' => 1, 'is_required' => true])->assertNotFound();
    expect($this->asToken($c2Token)->getJson(($this->fields)($this->w->c2->id))->json('data'))->toBe([]);
    $this->asToken($c2Token)->getJson(($this->fields)($this->w->c1->id))->assertNotFound();

    $c4Token = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($c4Token)->patchJson(($this->fields)($this->w->c4->id).'/'.$field['id'], ['base_version' => 1, 'is_required' => true])->assertNotFound();

    toggles()->disable($this->w->g1, 'hrm', 'Test setup', confirm: true);
    $this->asToken($this->w->token)->getJson(($this->fields)($this->w->c1->id))->assertForbidden();
    addField($this, $this->w->token, $this->w->c1->id, ['key' => 'other'])->assertForbidden();
});
