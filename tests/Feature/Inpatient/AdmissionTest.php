<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\Inpatient\Events\Admitted;
use Modules\Inpatient\Events\BedMoved;
use Modules\Inpatient\Services\BedCharges;
use Modules\Inpatient\Services\BedDays;

/*
 * H-4a Inpatient: beds of a ward with their daily charge, admitting into a
 * free bed under a lock (never two in one bed), the advance the rule asks
 * for, bed days on the bill once each (midnight or every 24 hours), bed
 * moves, cancelling, the bed board, a doctor seeing only their own patients,
 * permissions, modules off, the job with the module off, and isolation.
 */

beforeEach(function () {
    // Monday 2 November 2026, 10:00 in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'inpatient'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $this->desk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inpatient.view', 'inpatient.admit', 'patient_billing.view', 'patient_billing.collect'], 'Admission desk')), $this->w->c1);
    $doctorRole = makeRole($this->w->c1, ['inpatient.view', 'inpatient.round', 'patients.view', 'patients.treat'], 'Doctor');
    $this->doctorUser = staffWithRoles($this->w->c1, $doctorRole);
    $this->doctorToken = orgToken($this->doctorUser, $this->w->c1);
    $this->otherDoctorUser = staffWithRoles($this->w->c1, $doctorRole);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inpatient.view'], 'Viewer')), $this->w->c1);
    $this->org = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/{$path}";
    $this->api = fn (string $path, $unit = null) => ($this->org)("inpatient/{$path}", $unit);

    foreach ([['BED-GEN', 100000], ['BED-CAB', 300000]] as [$code, $price]) {
        $this->asToken($this->owner)->postJson(($this->org)('patient-billing/services'), ['code' => $code, 'name' => ['en' => $code], 'category' => 'bed', 'price_minor' => $price, 'is_active' => true])->assertCreated();
    }
    $this->ward = $this->asToken($this->owner)->postJson(($this->api)('wards', $this->w->b1), ['name' => ['en' => 'Male ward', 'bn' => 'পুরুষ ওয়ার্ড'], 'kind' => 'general', 'is_active' => true])->assertCreated()->json('data');
    $this->cabins = $this->asToken($this->owner)->postJson(($this->api)('wards', $this->w->b1), ['name' => ['en' => 'Cabins'], 'kind' => 'cabin', 'is_active' => true])->assertCreated()->json('data');
    $bed = fn (array $ward, string $code, string $service) => $this->asToken($this->owner)->postJson(($this->api)('beds', $this->w->b1), ['ward_id' => $ward['id'], 'code' => $code, 'service_code' => $service])->assertCreated()->json('data');
    $this->bed1 = $bed($this->ward, 'M-1', 'BED-GEN');
    $this->bed2 = $bed($this->ward, 'M-2', 'BED-GEN');
    $this->cabin = $bed($this->cabins, 'C-101', 'BED-CAB');
    $this->patient = fn (string $name) => $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => $name, 'sex' => 'male', 'mobile' => null, 'age_years' => 50])->assertCreated()->json('data');
    $this->rahim = ($this->patient)('Rahim Uddin');
    $this->karim = ($this->patient)('Karim Mia');
    $this->admit = fn (array $patient, array $bed, array $extra = [], $token = null) => $this->asToken($token ?? $this->desk)->postJson(($this->api)('admissions', $this->w->b1), [
        'patient_id' => $patient['id'], 'bed_id' => $bed['id'], 'doctor_user_id' => $this->doctorUser->id, 'reason' => 'Chest pain', ...$extra,
    ]);
    $this->stay = fn (array $admission) => $this->asToken($this->owner)->getJson(($this->api)("admissions/{$admission['id']}", $this->w->b1))->assertOk()->json('data');
    $this->charge = fn (string $at) => app(BedCharges::class)->run(CarbonImmutable::parse($at, 'Asia/Dhaka'));
});

it('admits into a free bed once, with the first bed day on the bill', function () {
    Event::fake([Admitted::class]);
    $admission = ($this->admit)($this->rahim, $this->bed1, ['op_id' => 'tap-1', 'guardian_mobile' => '01711-000002'])->assertCreated()->json('data');
    expect($admission)->toMatchArray(['number' => 'IP000001', 'status' => 'admitted', 'bed_days' => 1])->and($admission['bed'])->toMatchArray(['code' => 'M-1', 'ward' => 'Male ward']);
    ($this->admit)($this->rahim, $this->bed1, ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $admission['id']);
    ($this->admit)($this->rahim, $this->bed2)->assertStatus(409)->assertJsonPath('code', 'already_admitted');
    ($this->admit)($this->karim, $this->bed1)->assertStatus(409)->assertJsonPath('code', 'bed_taken');
    ($this->admit)($this->karim, $this->bed2, ['doctor_user_id' => createMember($this->w->c2)->id])->assertUnprocessable()->assertJsonValidationErrors('doctor_user_id');
    ($this->admit)($this->karim, $this->bed2, ['organization_id' => $this->w->c2->id])->assertUnprocessable();
    Event::assertDispatchedTimes(Admitted::class, 1);

    $stay = ($this->stay)($admission);
    expect($stay['bill_due_minor'])->toBe(100000)->and($stay['bed_days'])->toHaveCount(1)->and($stay['bed_days'][0])->toMatchArray(['day_no' => 1, 'day_on' => '2026-11-02', 'service_code' => 'BED-GEN'])
        ->and($stay['moves'])->toHaveCount(1);
    $board = $this->asToken($this->viewer)->getJson(($this->api)('beds/board', $this->w->b1))->assertOk()->json('data');
    $ward = collect($board)->firstWhere('id', $this->ward['id']);
    expect(collect($ward['beds'])->firstWhere('code', 'M-1'))->toMatchArray(['status' => 'occupied'])
        ->and(collect($ward['beds'])->firstWhere('code', 'M-1')['admission']['patient'])->toBe('Rahim Uddin')
        ->and(collect($ward['beds'])->firstWhere('code', 'M-2')['status'])->toBe('free');
    // The doctor is linked to the patient (Patients' care link).
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'own');
    $this->asToken($this->doctorToken)->getJson(($this->org)("patients/patients/{$this->rahim['id']}"))->assertOk();
});

it('charges each bed day once: at midnight by default', function () {
    $admission = ($this->admit)($this->rahim, $this->bed1)->assertCreated()->json('data');
    // The scheduled command itself: nothing new yet on the admission day.
    expect(Artisan::call('inpatient:bed-charges'))->toBe(0);
    ($this->charge)('2026-11-02 23:30');
    expect(($this->stay)($admission)['bed_days'])->toHaveCount(1);
    ($this->charge)('2026-11-03 00:10');
    ($this->charge)('2026-11-03 01:10');
    ($this->charge)('2026-11-05 08:00');
    $days = ($this->stay)($admission)['bed_days'];
    expect(collect($days)->pluck('day_on')->all())->toBe(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'])
        ->and(($this->stay)($admission)['bill_due_minor'])->toBe(400000);

    // Inpatient off for the company: the job charges nothing.
    toggles()->disable($this->w->c1, 'inpatient', 'Test');
    ($this->charge)('2026-11-07 08:00');
    toggles()->enable($this->w->c1, 'inpatient', 'Test');
    expect(($this->stay)($admission)['bed_days'])->toHaveCount(4);
});

it('charges every 24 hours from admission when the rule says so', function () {
    trustedOrgRule($this->w->c1, 'inpatient.bed_day_starts', 'admission_time');
    $admission = ($this->admit)($this->rahim, $this->bed1)->assertCreated()->json('data');
    ($this->charge)('2026-11-03 09:59');
    expect(($this->stay)($admission)['bed_days'])->toHaveCount(1);
    ($this->charge)('2026-11-03 10:01');
    expect(($this->stay)($admission)['bed_days'])->toHaveCount(2);

    // The counting itself.
    $in = CarbonImmutable::parse('2026-11-02 22:00', 'Asia/Dhaka');
    expect(BedDays::count('midnight', $in, $in->addHours(3), false, 'Asia/Dhaka'))->toBe(2)
        ->and(BedDays::count('midnight', $in, $in->addHours(3), true, 'Asia/Dhaka'))->toBe(1)
        ->and(BedDays::count('midnight', $in, $in->addHour(), true, 'Asia/Dhaka'))->toBe(1)
        ->and(BedDays::count('admission_time', $in, $in->addHours(25), true, 'Asia/Dhaka'))->toBe(2)
        ->and(BedDays::count('admission_time', $in, $in->addHours(24), true, 'Asia/Dhaka'))->toBe(1)
        ->and(BedDays::start('midnight', $in, 2, 'Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-03 00:00');
});

it('moves a patient: the days so far at the old bed, the next at the new one, the old bed to cleaning', function () {
    Event::fake([BedMoved::class]);
    $admission = ($this->admit)($this->rahim, $this->bed1)->assertCreated()->json('data');
    $move = fn (array $bed, int $version) => $this->asToken($this->desk)->postJson(($this->api)("admissions/{$admission['id']}/move", $this->w->b1), ['bed_id' => $bed['id'], 'reason' => 'Asked for a cabin', 'base_version' => $version]);
    $move($this->bed1, $admission['version'])->assertUnprocessable()->assertJsonPath('code', 'same_bed');
    $moved = $move($this->cabin, $admission['version'])->assertOk()->json('data');
    $move($this->bed2, $admission['version'])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    expect($moved['bed']['code'])->toBe('C-101');
    Event::assertDispatched(BedMoved::class);
    ($this->charge)('2026-11-04 08:00');
    $stay = ($this->stay)($admission);
    // Day 1 began in the ward bed; days 2 and 3 began in the cabin.
    expect(collect($stay['bed_days'])->pluck('service_code')->all())->toBe(['BED-GEN', 'BED-CAB', 'BED-CAB'])->and($stay['moves'])->toHaveCount(2)
        ->and($stay['bill_due_minor'])->toBe(700000);

    // The old bed is being cleaned: not free until the desk says so.
    ($this->admit)($this->karim, $this->bed1)->assertStatus(409)->assertJsonPath('code', 'bed_taken');
    $bed = collect($this->asToken($this->desk)->getJson(($this->api)('beds', $this->w->b1))->json('data'))->firstWhere('code', 'M-1');
    expect($bed['status'])->toBe('cleaning');
    $this->asToken($this->desk)->postJson(($this->api)("beds/{$bed['id']}/status", $this->w->b1), ['status' => 'free', 'base_version' => $bed['version']])->assertOk()->assertJsonPath('data.status', 'free');
    $this->asToken($this->viewer)->postJson(($this->api)("beds/{$bed['id']}/status", $this->w->b1), ['status' => 'blocked', 'base_version' => $bed['version'] + 1])->assertForbidden();
    ($this->admit)($this->karim, $this->bed1)->assertCreated();
    $cabin = collect($this->asToken($this->desk)->getJson(($this->api)('beds', $this->w->b1))->json('data'))->firstWhere('code', 'C-101');
    $this->asToken($this->desk)->postJson(($this->api)("beds/{$cabin['id']}/status", $this->w->b1), ['status' => 'free', 'base_version' => $cabin['version']])->assertStatus(409)->assertJsonPath('code', 'cannot_set_bed');
});

it('needs the advance the rule asks for before admission', function () {
    orgRule($this->w->c1, 'inpatient.min_advance', ['amount' => 500000, 'currency' => 'BDT']);
    ($this->admit)($this->rahim, $this->bed1)->assertUnprocessable()->assertJsonPath('code', 'advance_needed')->assertJsonPath('needed_minor', 500000)->assertJsonPath('has_minor', 0);
    $this->asToken($this->desk)->getJson(($this->api)("admissions/options?patient={$this->rahim['id']}", $this->w->b1))->assertOk()
        ->assertJsonPath('data.min_advance_minor', 500000)->assertJsonPath('data.advance_minor', 0);
    $this->asToken($this->desk)->postJson(($this->org)('patient-billing/payments', $this->w->b1), ['op_id' => 'adv-1', 'kind' => 'advance', 'patient_id' => $this->rahim['id'], 'method' => 'card', 'amount_minor' => 500000])->assertCreated();
    ($this->admit)($this->rahim, $this->bed1)->assertCreated();
});

it('cancels a mistaken admission while the bill is open: bed days off the bill, the bed to cleaning', function () {
    $admission = ($this->admit)($this->rahim, $this->bed1)->assertCreated()->json('data');
    $this->asToken($this->desk)->postJson(($this->api)("admissions/{$admission['id']}/cancel", $this->w->b1), ['base_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->desk)->postJson(($this->api)("admissions/{$admission['id']}/cancel", $this->w->b1), ['reason' => 'Wrong patient', 'base_version' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(($this->stay)($admission)['bill_due_minor'])->toBe(0);
    ($this->charge)('2026-11-04 08:00');
    expect(($this->stay)($admission)['bed_days'])->toHaveCount(1);
    expect(collect($this->asToken($this->desk)->getJson(($this->api)('admissions', $this->w->b1))->json('data')))->toHaveCount(0)
        ->and(collect($this->asToken($this->desk)->getJson(($this->api)('admissions?status=cancelled', $this->w->b1))->json('data')))->toHaveCount(1);
});

it('keeps beds right: billing codes, codes unique in a ward, in-use beds and wards stay', function () {
    $this->asToken($this->owner)->postJson(($this->api)('beds', $this->w->b1), ['ward_id' => $this->ward['id'], 'code' => 'M-3', 'service_code' => 'NOPE'])->assertUnprocessable()->assertJsonValidationErrors('service_code');
    $this->asToken($this->owner)->postJson(($this->api)('beds', $this->w->b1), ['ward_id' => $this->ward['id'], 'code' => 'm-1', 'service_code' => 'BED-GEN'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->asToken($this->desk)->postJson(($this->api)('beds', $this->w->b1), ['ward_id' => $this->ward['id'], 'code' => 'M-3', 'service_code' => 'BED-GEN'])->assertForbidden();
    $this->asToken($this->owner)->deleteJson(($this->api)("beds/{$this->bed2['id']}?base_version=1", $this->w->b1))->assertNoContent();
    ($this->admit)($this->rahim, $this->bed1)->assertCreated();
    $this->asToken($this->owner)->patchJson(($this->api)("beds/{$this->bed1['id']}", $this->w->b1), ['is_active' => false, 'base_version' => 2])->assertStatus(409)->assertJsonPath('code', 'bed_occupied');
    $this->asToken($this->owner)->deleteJson(($this->api)("beds/{$this->bed1['id']}?base_version=2", $this->w->b1))->assertStatus(409)->assertJsonPath('code', 'in_use');
    $this->asToken($this->owner)->deleteJson(($this->api)("wards/{$this->ward['id']}?base_version=1", $this->w->b1))->assertStatus(409)->assertJsonPath('code', 'in_use');
});

it('lets a doctor see only their own admitted patients when doctors see their own', function () {
    $mine = ($this->admit)($this->rahim, $this->bed1)->assertCreated()->json('data');
    $theirs = ($this->admit)($this->karim, $this->bed2, ['doctor_user_id' => $this->otherDoctorUser->id])->assertCreated()->json('data');
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'own');
    $list = $this->asToken($this->doctorToken)->getJson(($this->api)('admissions', $this->w->b1))->assertOk()->json();
    expect(collect($list['data'])->pluck('id')->all())->toBe([$mine['id']])->and($list['meta']['own_only'])->toBeTrue();
    $this->asToken($this->doctorToken)->getJson(($this->api)("admissions/{$theirs['id']}", $this->w->b1))->assertNotFound();
    $board = collect($this->asToken($this->doctorToken)->getJson(($this->api)('beds/board', $this->w->b1))->json('data'))->firstWhere('id', $this->ward['id']);
    expect(collect($board['beds'])->firstWhere('code', 'M-2'))->toMatchArray(['status' => 'occupied', 'admission' => null]);
    $this->asToken($this->doctorToken)->postJson(($this->api)("admissions/{$mine['id']}/move", $this->w->b1), ['bed_id' => $this->cabin['id'], 'base_version' => 1])->assertForbidden();
});

it('keeps each step to its permission and answers 403 with a module off', function () {
    ($this->admit)($this->rahim, $this->bed1, token: $this->viewer)->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('admissions/options', $this->w->b1))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('patients?q=Rahim', $this->w->b1))->assertForbidden();
    $this->asToken($this->desk)->getJson(($this->api)('patients?q=Rahim', $this->w->b1))->assertOk()->assertJsonPath('data.0.id', $this->rahim['id']);
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.admit', false);
    $this->asToken($this->desk)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.admit', true);

    toggles()->disable($this->w->c1, 'inpatient', 'Test');
    $this->asToken($this->desk)->getJson(($this->api)('beds/board', $this->w->b1))->assertForbidden();
    toggles()->enable($this->w->c1, 'inpatient', 'Test');
    toggles()->disable($this->w->c1, 'patient_billing', 'Test', confirm: true);
    ($this->admit)($this->rahim, $this->bed1)->assertForbidden();
});

it('never shows or admits across companies or partners', function () {
    $admission = ($this->admit)($this->rahim, $this->bed1)->assertCreated()->json('data');
    setPlan($this->w->g3, 'business');
    foreach (['patients', 'patient_billing', 'inpatient'] as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    foreach ([$this->w->c2, $this->w->c4] as $company) {
        $token = orgToken(createMember($company), $company);
        $this->asToken($token)->getJson(($this->api)("admissions/{$admission['id']}", $company))->assertNotFound();
        $this->asToken($token)->getJson(($this->api)("admissions/{$admission['id']}", $this->w->b1))->assertNotFound();
        $this->asToken($token)->postJson(($this->api)("admissions/{$admission['id']}/move", $company), ['bed_id' => $this->bed2['id'], 'base_version' => 1])->assertNotFound();
        $this->asToken($token)->postJson(($this->api)('admissions', $company), ['patient_id' => $this->karim['id'], 'bed_id' => $this->bed2['id'], 'doctor_user_id' => $this->doctorUser->id])->assertNotFound();
        expect($this->asToken($token)->getJson(($this->api)('beds/board', $company))->json('data'))->toBe([]);
    }
});
