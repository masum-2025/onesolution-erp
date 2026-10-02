<?php

use App\Platform\DataExport\Services\ExportBuilder;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Modules\Hrm\Export\HrmExporter;
use Modules\Hrm\Portal\EmployeeSubjects;

/*
 * HRM-1 with the rest of the platform: positions, the client's portal, the
 * client's data export, and clients kept in a dedicated database.
 */

beforeEach(function () {
    $this->w = hrmWorld($this);
});

it('names positions in both languages and offers those of the units above', function () {
    $url = "/api/organizations/{$this->w->c1->id}/hrm/positions";
    $teacher = $this->asToken($this->w->token)->postJson($url, ['title' => ['en' => 'Teacher', 'bn' => 'শিক্ষক'], 'code' => 'TCH'])
        ->assertCreated()->json('data');

    $this->asToken($this->w->token)->postJson($url, ['title' => ['en' => 'X'], 'salary' => 1])->assertUnprocessable()->assertJsonValidationErrors('salary');
    $this->asToken($this->w->token)->postJson($url, ['title' => ['fr' => 'Professeur']])->assertUnprocessable();

    // A branch sees its company's positions.
    $branchHr = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['hrm.view'], 'Branch HR'));
    $this->asToken(orgToken($branchHr, $this->w->b1))->getJson("/api/organizations/{$this->w->b1->id}/hrm/positions")
        ->assertOk()->assertJsonPath('data.0.titles.bn', 'শিক্ষক');

    $this->asToken($this->w->token)->patchJson("{$url}/{$teacher['id']}", ['base_version' => 1, 'grade' => 'G7'])->assertOk()->assertJsonPath('data.version', 2);
    $this->asToken($this->w->token)->patchJson("{$url}/{$teacher['id']}", ['base_version' => 1, 'grade' => 'G8'])->assertStatus(409);
    $this->asToken($this->w->token)->patchJson("{$url}/{$teacher['id']}", ['base_version' => 2, 'organization_id' => $this->w->b1->id])->assertUnprocessable();
});

it('gives the forms their choices, required details and where the numbers come from', function () {
    orgRule($this->w->c1, 'hrm.probation_days', 60);
    orgRule($this->w->c1, 'hrm.employment_types', ['permanent', 'contract']);
    orgRule($this->w->c1, 'hrm.required_fields', ['phone', 'national_id']);
    $this->withHeader('X-Locale', 'bn');

    $options = $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/form-options?unit_id={$this->w->b1->id}")->assertOk()->json('data');

    expect($options['unit']['id'])->toBe($this->w->b1->id)
        ->and($options['employment_types'])->toBe([['value' => 'permanent', 'label' => 'স্থায়ী'], ['value' => 'contract', 'label' => 'চুক্তিভিত্তিক']])
        ->and($options['required_fields'])->toBe(['phone', 'national_id'])
        ->and($options['probation_days']['value'])->toBe(60)
        ->and($options['probation_days']['source']['kind'])->toBe('inherited')
        ->and($options['probation_days']['source']['name'])->toBe($this->w->c1->displayName())
        ->and($options['notice_period_days']['source']['kind'])->toBe('default')
        ->and($options['can'])->toBe(['manage' => true, 'exit' => true, 'view_sensitive' => true, 'configure' => true]);

    $reader = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Reader'));
    expect($this->asToken(orgToken($reader, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/hrm/form-options")->json('data.can'))
        ->toBe(['manage' => false, 'exit' => false, 'view_sensitive' => false, 'configure' => false]);
});

it('lets an employee see their own record in the portal, without ids', function () {
    $mine = hireVia($this, $this->w->token, $this->w->b1, ['national_id' => '1990123456789'])->json('data.id');
    $c2Owner = createMember($this->w->c2);
    $other = hireVia($this, orgToken($c2Owner, $this->w->c2), $this->w->c2, ['full_name' => 'Elsewhere'])->json('data.id');
    app(CurrentContext::class)->clear();

    $subjects = app(EmployeeSubjects::class);
    $subject = $subjects->find($this->w->c1->fresh(), $mine);

    expect($subject->name)->toBe('Rahima Akter')
        ->and($subjects->relations())->toBe(['self'])
        ->and($subjects->find($this->w->c1->fresh(), $other))->toBeNull()
        ->and(array_map(fn ($found) => $found->id, $subjects->search($this->w->c1->fresh(), 'Rah')))->toBe([$mine]);

    $details = $subjects->details($subject, 'bn');
    expect($details['full_name']['label'])->toBe('নাম')
        ->and($details['unit']['value'])->toBe($this->w->b1->displayName())
        ->and(json_encode($details))->not->toContain('1990123456789');
});

it('hands its data to the client\'s export, for that client only', function () {
    $mine = hireVia($this, $this->w->token, $this->w->c1, ['national_id' => '1990123456789'])->json('data.id');
    hireVia($this, orgToken(createMember($this->w->c3), $this->w->c3), $this->w->c3, ['full_name' => 'Other group']);
    app(CurrentContext::class)->clear();

    $ids = Organization::query()->subtreeOf($this->w->c1)->pluck('id')->all();
    $datasets = app(HrmExporter::class)->export($this->w->c1, $ids);
    $employees = iterator_to_array($datasets['employees'], false);

    expect(array_keys($datasets))->toBe(['positions', 'employees', 'custom_fields', 'employment_events', 'documents'])
        ->and(array_column($employees, 'id'))->toBe([$mine])
        ->and($employees[0]['national_id'])->toBe('1990123456789')
        ->and(iterator_to_array($datasets['employment_events'], false))->toHaveCount(1);

    // Registered with the platform's export (tag "module.exporters").
    $exporters = (fn () => $this->exporters)->call(app(ExportBuilder::class));
    expect(collect($exporters)->map(fn ($exporter) => $exporter->moduleKey())->all())->toContain('hrm');
});

it('works the same for a client in its own database', function () {
    $dedicated = dedicatedTenantDatabase();
    placeClient($this->w->g1);

    $id = hireVia($this, $this->w->token, $this->w->c1)->assertCreated()->json('data.id');
    $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$id}/steps/transfer", ['base_version' => 1, 'to_organization_id' => $this->w->b1->id])->assertOk();

    expect(DB::connection($dedicated)->table('hrm_employees')->where('id', $id)->value('organization_id'))->toBe($this->w->b1->id)
        ->and(DB::connection($dedicated)->table('hrm_employment_events')->where('employee_id', $id)->count())->toBe(2)
        ->and(DB::connection($dedicated)->table('hrm_code_sequences')->count())->toBe(1)
        ->and(DB::table('hrm_employees')->where('id', $id)->exists())->toBeFalse()
        ->and($this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees")->json('meta.total'))->toBe(1);
});
