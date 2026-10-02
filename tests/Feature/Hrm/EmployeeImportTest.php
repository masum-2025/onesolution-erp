<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Modules\Jobs\EnsureModuleEnabledForJob;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Hrm\Jobs\ImportEmployees;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeImport;
use Modules\Hrm\Models\ImportRow;
use Modules\Hrm\Models\Position;

/*
 * HRM-3a: employees from a CSV file. Checked row by row first; imported
 * through the same hiring rules; details wiped when the import ends.
 */

beforeEach(function () {
    $this->w = hrmWorld($this);
    $this->imports = "/api/organizations/{$this->w->c1->id}/hrm/imports";
});

/**
 * @param  list<list<string>>  $rows  The header first.
 */
function csvFile(array $rows, string $name = 'staff.csv', bool $bom = true): UploadedFile
{
    $stream = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '');
    }
    rewind($stream);
    $content = ($bom ? "\xEF\xBB\xBF" : '').stream_get_contents($stream);

    return UploadedFile::fake()->createWithContent($name, $content);
}

function uploadCsv(object $test, string $token, string $url, UploadedFile $file, array $extra = []): TestResponse
{
    return $test->asToken($token)->post($url, ['file' => $file, ...$extra], ['Accept' => 'application/json']);
}

it('lists the columns, extra fields included, with the limits from the rules', function () {
    $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/custom-fields", [
        'key' => 'shift', 'type' => 'text', 'label' => ['en' => 'Shift'],
    ])->assertCreated();
    platformRule('hrm.import_max_rows', 200);

    $data = $this->asToken($this->w->token)->getJson("{$this->imports}/columns")->assertOk()->json('data');
    expect(array_slice(array_column($data['columns'], 'key'), 0, 3))->toBe(['full_name', 'employment_type', 'joined_on'])
        ->and(collect($data['columns'])->firstWhere('key', 'custom_shift'))->toBe(['key' => 'custom_shift', 'required' => false, 'label' => 'Shift'])
        ->and($data['max_rows'])->toBe(200)
        ->and($data['max_kb'])->toBe(1024);
});

it('checks a file, imports it with the hiring rules and wipes the details', function () {
    $manager = hireVia($this, $this->w->token, $this->w->c1, ['full_name' => 'Boss'])->json('data');
    $position = new Position;
    $position->fill(['organization_id' => $this->w->c1->id, 'code' => 'TCH', 'is_active' => true, 'version' => 1]);
    $position->putTexts('title', ['en' => 'Teacher'])->save();
    $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/custom-fields", [
        'key' => 'shift', 'type' => 'text', 'label' => ['en' => 'Shift'],
    ])->assertCreated();

    $file = csvFile([
        ['full_name', 'full_name_local', 'employment_type', 'joined_on', 'phone', 'national_id', 'position_code', 'unit_code', 'manager_code', 'address_city', 'custom_shift'],
        ['Rahima Akter', 'রহিমা আক্তার', 'permanent', '01/10/2026', '+8801711000001', '1990 0000 0001', 'tch', '', $manager['employee_code'], 'Dhaka', 'Morning'],
        ['Karim Uddin', '', 'contract', '2026-10-05', '+8801711000002', '', '', 'B1', '', '', ''],
        [],
    ]);

    $checked = uploadCsv($this, $this->w->token, $this->imports, $file)->assertCreated()->json('data');
    expect($checked)->toMatchArray(['status' => 'checked', 'total_rows' => 2, 'invalid_rows' => 0, 'valid_rows' => 2, 'problems' => []])
        ->and(Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->count())->toBe(1);

    $done = $this->asToken($this->w->token)->postJson("{$this->imports}/{$checked['id']}/start")->assertStatus(202)->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'imported_rows' => 2, 'failed_rows' => 0, 'progress' => 2]);

    $rahima = Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('full_name', 'Rahima Akter')->sole();
    $karim = Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('full_name', 'Karim Uddin')->sole();
    expect($rahima->full_name_local)->toBe('রহিমা আক্তার')
        ->and($rahima->joined_on->toDateString())->toBe('2026-10-01')
        ->and($rahima->position_id)->toBe($position->id)
        ->and($rahima->manager_id)->toBe($manager['id'])
        ->and($rahima->custom)->toBe(['shift' => 'Morning'])
        ->and($rahima->national_id)->toBe('1990 0000 0001')
        ->and($rahima->employee_code)->toBe('EMP-2026-0002')
        ->and($karim->organization_id)->toBe($this->w->b1->id)
        ->and($karim->employment_type)->toBe('contract');

    // Nothing personal stays with the import.
    expect(ImportRow::query()->withoutGlobalScope(OrganizationScope::class)->whereNotNull('data')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'hrm.employee_hired')->count())->toBe(3)
        ->and(AuditLog::query()->whereIn('action', ['hrm.import_checked', 'hrm.import_started', 'hrm.import_finished'])->pluck('action')->all())
        ->toEqualCanonicalizing(['hrm.import_checked', 'hrm.import_started', 'hrm.import_finished']);

    // It cannot run twice.
    $this->asToken($this->w->token)->postJson("{$this->imports}/{$checked['id']}/start")->assertStatus(409)->assertJsonPath('code', 'import_not_ready');
});

it('names each problem by row and column, and imports the rest only when told to skip', function () {
    hireVia($this, $this->w->token, $this->w->c1, ['national_id' => '5555']);

    $file = csvFile([
        ['full_name', 'employment_type', 'joined_on', 'phone', 'national_id', 'position_code', 'gender'],
        ['Good One', 'permanent', '2026-10-01', '+880171', '1111', '', 'Female'],
        ['Bad Date', 'permanent', '2026-13-40', '+880172', '', '', ''],
        ['No Kind', '', '2026-10-01', '+880173', '', '', ''],
        ['Same Id', 'permanent', '2026-10-01', '+880174', '1111', '', ''],
        ['Known Id', 'permanent', '2026-10-01', '+880175', '5 5 5 5', '', ''],
        ['=HYPERLINK("x")', 'permanent', '2026-10-01', '+880176', '', '', ''],
        ['Nobody', 'permanent', '2026-10-01', '+880177', '', 'XYZ', ''],
        ['Wrong Kind', 'robot', '2026-10-01', '+880178', '', '', ''],
        ['No Phone', 'permanent', '2026-10-01', '', '', '', ''],
    ]);

    $checked = uploadCsv($this, $this->w->token, $this->imports, $file)->assertCreated()->json('data');
    $problems = collect($checked['problems'])->mapWithKeys(fn ($row) => [$row['row_no'] => array_keys($row['errors'])])->all();

    expect($checked['invalid_rows'])->toBe(8)
        ->and($problems)->toBe([
            3 => ['joined_on'],
            4 => ['employment_type'],
            5 => ['national_id'],
            6 => ['national_id'],
            7 => ['full_name'],
            8 => ['position_code'],
            9 => ['employment_type'],
            10 => ['phone'],
        ])
        ->and($checked['problems'][2]['errors']['national_id'])->toBe('Row 2 of this file has the same national id.')
        ->and($checked['problems'][2]['name'])->toBe('Same Id');

    $this->asToken($this->w->token)->postJson("{$this->imports}/{$checked['id']}/start")->assertUnprocessable()->assertJsonPath('code', 'import_has_invalid');

    $done = $this->asToken($this->w->token)->postJson("{$this->imports}/{$checked['id']}/start", ['skip_invalid' => true])->assertStatus(202)->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'imported_rows' => 1])
        ->and(collect($done['problems'])->pluck('status')->unique()->all())->toBe(['skipped'])
        ->and(collect($done['problems'])->pluck('name')->filter()->all())->toBe([])
        ->and(Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('full_name', 'Good One')->value('gender'))->toBe('female');
});

it('refuses files it cannot read, with what to do next', function () {
    $missing = uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'phone'], ['A', '1']]))->assertUnprocessable();
    expect($missing->json('errors.file.0'))->toBe('Columns missing from the header: employment_type, joined_on. Start from the template.');

    uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on', 'salary'], ['Ana Rahman', 'permanent', '2026-10-01', '5']]))
        ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Unknown columns in the header: salary. Use the column names of the template.');
    uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on']]))
        ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file has no employees. Fill in at least one row under the header.');
    uploadCsv($this, $this->w->token, $this->imports, UploadedFile::fake()->createWithContent('x.csv', "full_name,employment_type,joined_on\n\xE9t\xE9,permanent,2026-10-01\n"))
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    uploadCsv($this, $this->w->token, $this->imports, UploadedFile::fake()->create('staff.pdf', 10, 'application/pdf'))
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    platformRule('hrm.import_max_rows', 2);
    uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on'], ['Ana Rahman', 'permanent', '2026-10-01'], ['Bina Das', 'permanent', '2026-10-01'], ['Chandan Roy', 'permanent', '2026-10-01']]))
        ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file has more than 2 employees. Split it into smaller files.');

    // Semicolon files (European Excel) are read too.
    uploadCsv($this, $this->w->token, $this->imports, UploadedFile::fake()->createWithContent('s.csv', "full_name;employment_type;joined_on;phone\nAna Rahman;permanent;2026-10-01;+880171\n"))
        ->assertCreated()->assertJsonPath('data.invalid_rows', 0);
});

it('runs one import at a time per company, and a waiting one can be cancelled', function () {
    Queue::fake();
    $file = fn () => csvFile([['full_name', 'employment_type', 'joined_on', 'phone'], ['Ana Rahman', 'permanent', '2026-10-01', '+880171']]);

    $first = uploadCsv($this, $this->w->token, $this->imports, $file())->json('data.id');
    $second = uploadCsv($this, $this->w->token, $this->imports, $file())->json('data.id');

    $this->asToken($this->w->token)->postJson("{$this->imports}/{$first}/start")->assertStatus(202)->assertJsonPath('data.status', 'queued');
    $this->asToken($this->w->token)->postJson("{$this->imports}/{$second}/start")->assertStatus(409)->assertJsonPath('code', 'import_busy');
    Queue::assertPushed(ImportEmployees::class, 1);

    $this->asToken($this->w->token)->deleteJson("{$this->imports}/{$first}")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->asToken($this->w->token)->postJson("{$this->imports}/{$second}/start")->assertStatus(202);

    expect(ImportRow::query()->withoutGlobalScope(OrganizationScope::class)->where('import_id', $first)->whereNotNull('data')->count())->toBe(0)
        ->and(collect($this->asToken($this->w->token)->getJson($this->imports)->json('data'))->pluck('status')->all())->toEqualCanonicalizing(['cancelled', 'queued']);
});

it('imports nothing for someone who lost access before it ran, and skips while HRM is off', function () {
    Queue::fake();
    $hr = staffWithRoles($this->w->c1, $role = makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR'));
    $token = orgToken($hr, $this->w->c1);
    $id = uploadCsv($this, $token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on', 'phone'], ['Ana Rahman', 'permanent', '2026-10-01', '+880171']]))->json('data.id');
    $this->asToken($token)->postJson("{$this->imports}/{$id}/start")->assertStatus(202);

    $job = new ImportEmployees($id, $this->w->c1->id, $this->w->c1->id, $hr->id);

    // HRM off: the job leaves the import waiting.
    toggles()->disable($this->w->g1, 'hrm', 'Test setup', confirm: true);
    app(CurrentContext::class)->clear();
    app()->forgetScopedInstances(); // as the queue worker does before each job
    app()->call(fn () => (new EnsureModuleEnabledForJob('hrm', $this->w->c1->id))->handle($job, fn ($job) => app()->call([$job, 'handle'])));
    expect(EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)->find($id)->status->value)->toBe('queued');

    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    DB::table('role_permissions')->where('role_id', $role->id)->where('permission_key', 'hrm.manage')->delete();
    app(CurrentContext::class)->clear();
    app()->forgetScopedInstances();
    app()->call([$job, 'handle']);

    $import = EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)->find($id);
    expect($import->status->value)->toBe('done')
        ->and($import->failed_rows)->toBe(1)
        ->and($import->imported_rows)->toBe(0)
        ->and(Employee::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

it('needs hrm.manage, is closed while HRM is off and invisible to other companies and partners', function () {
    $reader = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Reader'));
    $file = fn () => csvFile([['full_name', 'employment_type', 'joined_on', 'phone'], ['Ana Rahman', 'permanent', '2026-10-01', '+880171']]);
    uploadCsv($this, orgToken($reader, $this->w->c1), $this->imports, $file())->assertForbidden();

    $id = uploadCsv($this, $this->w->token, $this->imports, $file())->json('data.id');

    $c2Token = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($c2Token)->getJson("/api/organizations/{$this->w->c2->id}/hrm/imports/{$id}")->assertNotFound();
    $this->asToken($c2Token)->postJson("/api/organizations/{$this->w->c2->id}/hrm/imports/{$id}/start")->assertNotFound();
    $c4Token = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($c4Token)->deleteJson("/api/organizations/{$this->w->c4->id}/hrm/imports/{$id}")->assertNotFound();

    // A branch manager sees imports of the branch only.
    $branchHr = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['hrm.view', 'hrm.manage'], 'Branch HR'));
    $this->asToken(orgToken($branchHr, $this->w->b1))->getJson("/api/organizations/{$this->w->b1->id}/hrm/imports/{$id}")->assertNotFound();

    toggles()->disable($this->w->g1, 'hrm', 'Test setup', confirm: true);
    $this->asToken($this->w->token)->getJson("{$this->imports}/{$id}")->assertForbidden();
    uploadCsv($this, $this->w->token, $this->imports, $file())->assertForbidden();
});

it('cancels imports left unstarted for a week and wipes their details', function () {
    $id = uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on', 'phone'], ['Ana Rahman', 'permanent', '2026-10-01', '+880171']]))->json('data.id');
    $fresh = uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on', 'phone'], ['Bina Das', 'permanent', '2026-10-01', '+880172']]))->json('data.id');
    EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($id)->update(['created_at' => now()->subDays(8)]);

    $this->artisan('hrm:prune-imports')->expectsOutput('Cancelled 1 stale import(s).')->assertSuccessful();

    expect(EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)->find($id)->status->value)->toBe('cancelled')
        ->and(EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)->find($fresh)->status->value)->toBe('checked')
        ->and(ImportRow::query()->withoutGlobalScope(OrganizationScope::class)->where('import_id', $id)->value('data'))->toBeNull();
});

it('works the same for a client in its own database', function () {
    $dedicated = dedicatedTenantDatabase();
    placeClient($this->w->g1);

    $id = uploadCsv($this, $this->w->token, $this->imports, csvFile([['full_name', 'employment_type', 'joined_on', 'phone'], ['Ana Rahman', 'permanent', '2026-10-01', '+880171']]))->assertCreated()->json('data.id');
    $this->asToken($this->w->token)->postJson("{$this->imports}/{$id}/start")->assertStatus(202)->assertJsonPath('data.status', 'done');

    expect(DB::connection($dedicated)->table('hrm_imports')->where('id', $id)->value('status'))->toBe('done')
        ->and(DB::connection($dedicated)->table('hrm_employees')->where('full_name', 'Ana Rahman')->exists())->toBeTrue()
        ->and(DB::table('hrm_imports')->where('id', $id)->exists())->toBeFalse();
});
