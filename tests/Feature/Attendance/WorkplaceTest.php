<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Services\Workplace;
use Modules\Attendance\Support\GeoDistance;

/*
 * ATT-3: checking in from a workplace (location check), attendance machine
 * files, and check-ins made while the phone was offline. Company C1 is in
 * Asia/Dhaka (UTC+6).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 03:00:00', 'UTC')); // 09:00 in Dhaka
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        toggles()->enable($group, 'attendance', 'Test setup');
    }
    $this->hr = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage', 'attendance.view', 'attendance.manage'], 'HR')), $this->w->c1);
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch', 'offline_mode.use'], 'Worker'));
    $this->worker = orgToken($this->workerUser, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/attendance/{$path}";

    $this->rahima = hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'Rahima Akter', 'joined_on' => '2026-10-01'])->assertCreated()->json('data');
    $this->asToken($this->hr)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->workerUser->id])->assertOk();
    $shift = $this->asToken($this->hr)->postJson(($this->api)('shifts'), ['code' => 'DAY', 'name' => ['en' => 'Day'], 'start_minute' => 540, 'end_minute' => 1020, 'break_minutes' => 60])->json('data');
    $this->asToken($this->hr)->postJson(($this->api)('rosters'), ['employee_ids' => [$this->rahima['id']], 'shift_id' => $shift['id'], 'from' => '2026-10-01'])->assertCreated();

    // The branch office in Dhaka (23.810331, 90.412521).
    $this->office = ['latitude_micro' => 23810331, 'longitude_micro' => 90412521];
    $this->punchAt = fn (int $east, int $accuracy = 20, array $extra = []) => $this->asToken($this->worker)->postJson(($this->api)('me/punch'), [
        'latitude_micro' => $this->office['latitude_micro'], 'longitude_micro' => $this->office['longitude_micro'] + $east, 'accuracy_m' => $accuracy, ...$extra,
    ]);
});

it('measures distances in whole metres from integer points', function () {
    expect(GeoDistance::metres(23810331, 90412521, 23810331, 90414521))->toBe(203)
        ->and(GeoDistance::metres(23810331, 90412521, 23810331, 90412521))->toBe(0)
        ->and(GeoDistance::metres(0, 0, 1000000, 0))->toBe(111195)
        ->and(GeoDistance::valid(91000000, 0))->toBeFalse();
});

it('takes a check-in only from inside a workplace when the unit asks for it', function () {
    trustedOrgRule($this->w->c1, 'attendance.geo_fence_required', true);
    expect($this->asToken($this->worker)->getJson(($this->api)('me'))->json('data.location_required'))->toBeTrue();

    $this->asToken($this->worker)->postJson(($this->api)('me/punch'))->assertConflict()->assertJsonPath('code', 'location_needed');
    ($this->punchAt)(0)->assertConflict()->assertJsonPath('code', 'no_workplaces');

    $location = $this->asToken($this->hr)->postJson(($this->api)('locations'), ['name' => 'Gulshan office', 'unit_id' => $this->w->b1->id, ...$this->office])
        ->assertCreated()->json('data');
    expect($location)->toMatchArray(['radius_m' => 200, 'unit_name' => 'B1']);

    ($this->punchAt)(1000, 150)->assertUnprocessable()->assertJsonPath('code', 'location_vague');
    $outside = ($this->punchAt)(4000)->assertForbidden()->json();
    expect($outside['code'])->toBe('outside_workplace')->and($outside['message'])->toContain('Gulshan office');

    $inside = ($this->punchAt)(1000)->assertCreated()->json('data.punch');
    expect($inside)->toMatchArray(['distance_m' => 102, 'accuracy_m' => 20])->and($inside)->not->toHaveKey('latitude_micro');

    // A workplace switched off no longer counts.
    $this->asToken($this->hr)->patchJson(($this->api)("locations/{$location['id']}"), ['base_version' => 1, 'is_active' => false])->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 11:00:00', 'UTC'));
    ($this->punchAt)(1000)->assertConflict()->assertJsonPath('code', 'no_workplaces');
});

it('keeps no location when the unit does not ask, and forgets old ones', function () {
    $plain = ($this->punchAt)(9000000)->assertCreated()->json('data.punch');
    expect(Punch::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->find($plain['id'])->latitude_micro)->toBeNull();

    trustedOrgRule($this->w->c1, 'attendance.geo_fence_required', true);
    $this->asToken($this->hr)->postJson(($this->api)('locations'), ['name' => 'Office', ...$this->office])->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 11:00:00', 'UTC'));
    $kept = ($this->punchAt)(500)->assertCreated()->json('data.punch');
    $find = fn () => Punch::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->find($kept['id']);
    expect($find()->latitude_micro)->toBe($this->office['latitude_micro']);

    $this->travelTo(CarbonImmutable::parse('2027-01-20 00:00:00', 'UTC'));
    $this->artisan('attendance:forget-locations')->expectsOutputToContain('Forgot 1 location(s).')->assertSuccessful();
    expect($find()->latitude_micro)->toBeNull()->and($find()->distance_m)->toBe(51);
});

it('brings in an attendance machine file once, listing unknown codes', function () {
    $code = $this->rahima['employee_code'];
    $karim = hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'Karim', 'joined_on' => '2026-10-14'])->assertCreated()->json('data');
    $file = fn (string $csv) => UploadedFile::fake()->createWithContent('att.csv', $csv);
    $send = fn (string $csv, array $columns = ['code' => 'No.', 'datetime' => 'Time'], string $format = 'Y-m-d H:i:s', ?string $token = null) => $this->asToken($token ?? $this->hr)
        ->post(($this->api)('device-import'), ['file' => $file($csv), 'columns' => $columns, 'datetime_format' => $format], ['Accept' => 'application/json']);
    $csv = "No.,Name,Time\n{$code},Rahima,2026-10-14 09:02:00\n{$code},Rahima,2026-10-14 17:30:00\nX99,Nobody,2026-10-14 09:00:00\n{$karim['employee_code']},Karim,2026-10-13 09:00:00\n";

    $send($csv, token: $this->worker)->assertForbidden();
    $send("No.,Name,Time\n{$code},Rahima,14/10/2026 09:02\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    $send($csv, ['code' => 'Badge', 'datetime' => 'Time'])->assertUnprocessable()->assertJsonValidationErrors('columns.code');
    $send($csv)->assertCreated()->assertJsonPath('data', ['added' => 2, 'already' => 0, 'unknown_codes' => ['X99'], 'not_employed' => 1]);
    $send($csv)->assertCreated()->assertJsonPath('data.already', 2)->assertJsonPath('data.added', 0);

    $day = collect($this->asToken($this->hr)->getJson(($this->api)("days?from=2026-10-14&to=2026-10-14&employee_id={$this->rahima['id']}"))->json('data'))->first();
    expect($day)->toMatchArray(['status' => 'present', 'worked_minutes' => 448])
        ->and($this->asToken($this->hr)->getJson(($this->api)('device-format'))->json('data.columns'))->toBe(['code' => 'No.', 'datetime' => 'Time'])
        ->and(AuditLog::query()->where('action', 'attendance.device_imported')->count())->toBe(2);

    // A date and a time column.
    $send("Code,Date,Time\n{$code},2026-10-13,09:10:00\n", ['code' => 'Code', 'date' => 'Date', 'time' => 'Time'])->assertCreated()->assertJsonPath('data.added', 1);
});

it('takes check-ins made offline, recent ones only, each once', function () {
    toggles()->enable($this->w->g1, 'offline_mode', 'Test setup');
    $device = $this->asToken($this->worker)->postJson('/api/offline/devices', ['name' => 'Gate phone', 'platform' => 'Android'])->assertCreated()->json('data');
    $sync = fn (array $operations) => $this->asToken($this->worker)->postJson('/api/sync', ['device_id' => $device['device']['id'], 'lease' => $device['lease'], 'operations' => $operations]);
    $op = fn (string $madeAt, ?string $id = null) => [
        'op_id' => $id ?? (string) Str::ulid(), 'kind' => 'attendance.punch', 'action' => 'create', 'data' => (object) [],
        'made_at' => $madeAt, 'lease_id' => $device['lease_id'],
    ];

    $early = $op('2026-10-15T02:58:00+00:00');
    $old = $op('2026-10-10T03:00:00+00:00');
    $results = $sync([$early, $old])->assertOk()->json('results');
    expect($results[$early['op_id']]['status'])->toBe('applied')
        ->and($results[$old['op_id']])->toMatchArray(['status' => 'rejected', 'code' => 'offline_too_old']);
    $again = $sync([$early])->assertOk()->json("results.{$early['op_id']}");
    expect($again['record_id'])->toBe($results[$early['op_id']]['record_id']);

    $punches = collect($this->asToken($this->hr)->getJson(($this->api)("punches?employee_id={$this->rahima['id']}&from=2026-10-15&to=2026-10-15"))->json('data'));
    expect($punches->pluck('source')->all())->toBe(['offline'])
        ->and($punches->first()['punched_at'])->toBe('2026-10-15T02:58:00+00:00');
});

it('keeps workplaces to the company and to people who manage attendance', function () {
    $location = $this->asToken($this->hr)->postJson(($this->api)('locations'), ['name' => 'Office', ...$this->office])->assertCreated()->json('data');
    $this->asToken($this->worker)->postJson(($this->api)('locations'), ['name' => 'Mine', ...$this->office])->assertForbidden();
    $this->asToken($this->hr)->postJson(($this->api)('locations'), ['name' => 'Moon', 'latitude_micro' => 95000000, 'longitude_micro' => 0])->assertUnprocessable()->assertJsonValidationErrors('latitude_micro');

    $c2Hr = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['attendance.view', 'attendance.manage'], 'C2 HR')), $this->w->c2);
    $this->asToken($c2Hr)->patchJson(($this->api)("locations/{$location['id']}", $this->w->c2), ['base_version' => 1, 'name' => 'Taken'])->assertNotFound();
    $this->asToken($c2Hr)->getJson(($this->api)('locations', $this->w->c2))->assertOk()->assertJsonPath('data', []);
    expect(app(Workplace::class)->companyOf($this->w->d1)->id)->toBe($this->w->c1->id);
});
