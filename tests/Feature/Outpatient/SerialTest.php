<?php

use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Outpatient\Events\SerialBooked;

/*
 * H-3a Outpatient: a doctor's week at a chamber and sign-in, serials given in
 * order up to the session's limit (the schedule's or the rule's), the fee on
 * the patient's bill, free follow-ups within the rule's days, moving serials
 * on (a cancelled one frees its place and its fee leaves an open bill), a
 * doctor seeing only their own patients, the waiting-room screen without
 * names, SMS by rule, permissions, modules off, and isolation.
 */

beforeEach(function () {
    // Monday 2 November 2026, 10:00 in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'outpatient'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $this->desk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['outpatient.view', 'outpatient.book', 'patients.view', 'patients.register'], 'Reception')), $this->w->c1);
    $this->doctorUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['outpatient.view', 'outpatient.consult', 'patients.view', 'patients.treat'], 'Doctor'));
    $this->doctorToken = orgToken($this->doctorUser, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['outpatient.view'], 'Viewer')), $this->w->c1);
    $this->org = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/{$path}";
    $this->api = fn (string $path, $unit = null) => ($this->org)("outpatient/{$path}", $unit);

    foreach ([['FEE', 50000], ['FUP', 30000], ['FEE2', 70000]] as [$code, $price]) {
        $this->asToken($this->owner)->postJson(($this->org)('patient-billing/services'), ['code' => $code, 'name' => ['en' => "Fee {$code}"], 'category' => 'consultation', 'price_minor' => $price, 'is_active' => true])->assertCreated();
    }
    $this->doctor = $this->asToken($this->owner)->postJson(($this->api)('doctors'), [
        'name' => ['en' => 'Dr. Ayesha Rahman', 'bn' => 'ডা. আয়েশা রহমান'], 'registration_no' => 'A-1001', 'specialty' => 'Medicine',
        'fee_service_code' => 'FEE', 'follow_up_service_code' => 'FUP', 'is_active' => true,
    ])->assertCreated()->json('data');
    $this->other = $this->asToken($this->owner)->postJson(($this->api)('doctors'), [
        'name' => ['en' => 'Dr. Kamal Hossain'], 'fee_service_code' => 'FEE2', 'is_active' => true,
    ])->assertCreated()->json('data');
    // The chamber is at branch B1.
    $this->chamber = $this->asToken($this->owner)->postJson(($this->api)('chambers', $this->w->b1), ['name' => 'Chamber 1', 'room' => '101', 'is_active' => true])->assertCreated()->json('data');
    $this->week = function (array $doctor, array $sessions, ?string $userId = null, $token = null) {
        $version = $this->asToken($this->owner)->getJson(($this->api)("doctors/{$doctor['id']}/schedule"))->json('data.doctor.version');

        return $this->asToken($token ?? $this->owner)->putJson(($this->api)("doctors/{$doctor['id']}/schedule"), ['user_id' => $userId, 'base_version' => $version, 'sessions' => $sessions]);
    };
    // Monday evening, two serials; Monday morning for the other doctor.
    $saved = ($this->week)($this->doctor, [['chamber_id' => $this->chamber['id'], 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '20:00', 'serial_limit' => 2]], $this->doctorUser->id)->assertOk()->json('data');
    $this->session = $saved['sessions'][0];
    $this->otherSession = ($this->week)($this->other, [['chamber_id' => $this->chamber['id'], 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00']])->assertOk()->json('data.sessions.0');

    $this->patient = fn (string $name, ?string $mobile = null) => $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => $name, 'sex' => 'male', 'mobile' => $mobile, 'age_years' => 40])->assertCreated()->json('data');
    $this->rahim = ($this->patient)('Rahim Uddin', '01711-000001');
    $this->karim = ($this->patient)('Karim Mia');
    $this->book = fn (array $patient, ?array $session = null, string $date = '2026-11-02', $token = null, array $extra = []) => $this->asToken($token ?? $this->desk)
        ->postJson(($this->api)('serials', $this->w->b1), ['schedule_id' => ($session ?? $this->session)['id'], 'date' => $date, 'patient_id' => $patient['id'], ...$extra]);
    $this->move = fn (array $serial, string $to, $token = null, array $extra = []) => $this->asToken($token ?? $this->desk)
        ->postJson(($this->api)("serials/{$serial['id']}/status", $this->w->b1), ['status' => $to, 'base_version' => $serial['version'], ...$extra]);
});

it('gives serials in order up to the session\'s limit, with the fee on the patient\'s bill', function () {
    Event::fake([SerialBooked::class]);
    $sessions = $this->asToken($this->desk)->getJson(($this->api)('sessions?date=2026-11-02', $this->w->b1))->assertOk()->json('data');
    expect(collect($sessions)->firstWhere('id', $this->session['id']))->toMatchArray(['doctor' => 'Dr. Ayesha Rahman', 'limit' => 2, 'taken' => 0, 'free' => 2, 'starts_at' => '17:00']);
    $this->asToken($this->desk)->getJson(($this->api)("quote?session={$this->session['id']}&patient={$this->rahim['id']}"))->assertOk()
        ->assertJsonPath('data.kind', 'new')->assertJsonPath('data.is_free', false)->assertJsonPath('data.price_minor', 50000);

    $first = ($this->book)($this->rahim, extra: ['op_id' => 'tap-1'])->assertCreated()->json('data');
    expect($first)->toMatchArray(['serial_no' => 1, 'status' => 'booked', 'kind' => 'new', 'is_free' => false, 'fee_minor' => 50000, 'due_minor' => 50000])
        ->and($first['bill_id'])->not->toBeNull()->and($first['patient']['name'])->toBe('Rahim Uddin');
    ($this->book)($this->rahim, extra: ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $first['id']);
    ($this->book)($this->rahim)->assertStatus(409)->assertJsonPath('code', 'already_booked');
    ($this->book)($this->karim)->assertCreated()->assertJsonPath('data.serial_no', 2);
    ($this->book)(($this->patient)('Third'))->assertStatus(409)->assertJsonPath('code', 'session_full');
    Event::assertDispatchedTimes(SerialBooked::class, 2);

    // Days the session is not held, gone days, too far ahead.
    ($this->book)($this->karim, date: '2026-11-03')->assertUnprocessable()->assertJsonPath('code', 'no_session');
    ($this->book)($this->karim, date: '2026-10-26')->assertUnprocessable()->assertJsonPath('code', 'date_passed');
    ($this->book)($this->karim, date: '2027-06-07')->assertUnprocessable()->assertJsonPath('code', 'too_far_ahead');
    ($this->book)($this->karim, extra: ['organization_id' => $this->w->c2->id])->assertUnprocessable();

    $list = $this->asToken($this->desk)->getJson(($this->api)('serials', $this->w->b1))->assertOk()->json();
    expect(collect($list['data'])->pluck('serial_no')->all())->toBe([1, 2])->and($list['meta']['date'])->toBe('2026-11-02');
    expect(collect($this->asToken($this->desk)->getJson(($this->api)('sessions?date=2026-11-02', $this->w->b1))->json('data'))->firstWhere('id', $this->session['id'])['free'])->toBe(0);
});

it('moves serials on; a cancelled one frees its place, keeps its number and leaves an open bill', function () {
    $rahim = ($this->book)($this->rahim)->assertCreated()->json('data');
    $karim = ($this->book)($this->karim)->assertCreated()->json('data');

    ($this->move)($karim, 'cancelled')->assertUnprocessable()->assertJsonValidationErrors('reason');
    $cancelled = ($this->move)($karim, 'cancelled', extra: ['reason' => 'Could not come'])->assertOk();
    expect($cancelled->json('data.status'))->toBe('cancelled')->and($cancelled->json('meta.fee_withdrawn'))->toBeTrue()
        ->and($cancelled->json('data.fee_minor'))->toBe(0)->and($cancelled->json('data.cancel_reason'))->toBe('Could not come');
    ($this->book)(($this->patient)('Third'))->assertCreated()->assertJsonPath('data.serial_no', 3);

    ($this->move)($rahim, 'done')->assertStatus(409)->assertJsonPath('code', 'cannot_move');
    $arrived = ($this->move)($rahim, 'arrived')->assertOk()->json('data');
    ($this->move)($rahim, 'in')->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $in = ($this->move)($arrived, 'in')->assertOk()->json('data');
    $done = ($this->move)($in, 'done')->assertOk()->json('data');
    expect($done['status'])->toBe('done')->and($done['next'])->toBe([])->and($done['finished_at'])->not->toBeNull();
    ($this->move)($done, 'cancelled', extra: ['reason' => 'Late'])->assertStatus(409)->assertJsonPath('code', 'cannot_move');
});

it('lets a follow-up within the free days cost nothing, and later the follow-up fee', function () {
    $first = ($this->book)($this->rahim)->assertCreated()->json('data');
    foreach (['arrived', 'in', 'done'] as $to) {
        $first = ($this->move)($first, $to)->assertOk()->json('data');
    }
    // A week later: free (rule 7 days).
    $free = ($this->book)($this->rahim, date: '2026-11-09')->assertCreated()->json('data');
    expect($free)->toMatchArray(['kind' => 'follow_up', 'is_free' => true, 'bill_id' => null, 'fee_minor' => 0]);
    foreach (['arrived', 'in', 'done'] as $to) {
        $free = ($this->move)($free, $to)->assertOk()->json('data');
    }
    // Two weeks after the last paid visit: the follow-up fee, not free again by the free visit.
    $this->asToken($this->desk)->getJson(($this->api)("quote?session={$this->session['id']}&patient={$this->rahim['id']}&date=2026-11-16"))->assertOk()
        ->assertJsonPath('data.kind', 'follow_up')->assertJsonPath('data.is_free', false)->assertJsonPath('data.service_code', 'FUP')->assertJsonPath('data.price_minor', 30000)
        ->assertJsonPath('data.last_visit', '2026-11-09');
    ($this->book)($this->rahim, date: '2026-11-16')->assertCreated()->assertJsonPath('data.fee_minor', 30000);

    // The rule at the branch: 0 = never free.
    orgRule($this->w->b1, 'outpatient.follow_up_free_days', 0);
    $this->asToken($this->desk)->getJson(($this->api)("quote?session={$this->session['id']}&patient={$this->rahim['id']}&date=2026-11-09"))->assertJsonPath('data.is_free', false);
});

it('takes the usual number of serials from the rule, the branch\'s inside the company\'s', function () {
    $saved = ($this->week)($this->doctor, [['id' => $this->session['id'], 'chamber_id' => $this->chamber['id'], 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '20:00', 'serial_limit' => null]], $this->doctorUser->id)->assertOk()->json('data');
    expect($saved['sessions'][0]['id'])->toBe($this->session['id'])->and($saved['usual_limit'])->toBe(40);
    orgRule($this->w->c1, 'outpatient.daily_serial_limit', 3);
    orgRule($this->w->b1, 'outpatient.daily_serial_limit', 1);
    ($this->book)($this->rahim)->assertCreated();
    ($this->book)($this->karim)->assertStatus(409)->assertJsonPath('code', 'session_full')->assertJsonPath('limit', 1);
});

it('keeps a doctor\'s week right: no overlaps, chambers of the company, staff sign-ins, retired sessions kept', function () {
    $chamber = ['chamber_id' => $this->chamber['id'], 'weekday' => 2];
    ($this->week)($this->doctor, [[...$chamber, 'starts_at' => '10:00', 'ends_at' => '09:00']])->assertUnprocessable()->assertJsonValidationErrors('sessions.0.ends_at');
    ($this->week)($this->doctor, [[...$chamber, 'starts_at' => '10:00', 'ends_at' => '12:00'], [...$chamber, 'starts_at' => '11:00', 'ends_at' => '13:00']])->assertUnprocessable()->assertJsonValidationErrors('sessions.1.starts_at');
    $elsewhere = $this->asToken($o = orgToken(createMember($this->w->c2), $this->w->c2))->postJson(($this->api)('chambers', $this->w->c2), ['name' => 'Theirs', 'is_active' => true])->assertCreated()->json('data');
    ($this->week)($this->doctor, [['chamber_id' => $elsewhere['id'], 'weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00']])->assertUnprocessable()->assertJsonValidationErrors('sessions.0.chamber_id');
    ($this->week)($this->doctor, [], createMember($this->w->c2)->id)->assertUnprocessable()->assertJsonValidationErrors('user_id');
    ($this->week)($this->other, [], $this->doctorUser->id)->assertUnprocessable()->assertJsonValidationErrors('user_id');
    ($this->week)($this->doctor, [], null, $this->desk)->assertForbidden();
    $this->asToken($this->owner)->patchJson(($this->api)("doctors/{$this->doctor['id']}"), ['fee_service_code' => 'NOPE', 'base_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('fee_service_code');

    // A session with serials is switched off when left out: its serial stays, the day has no session any more.
    $serial = ($this->book)($this->rahim)->assertCreated()->json('data');
    ($this->week)($this->doctor, [], $this->doctorUser->id)->assertOk()->assertJsonPath('data.sessions', []);
    $this->asToken($this->desk)->getJson(($this->api)("serials/{$serial['id']}", $this->w->b1))->assertOk()->assertJsonPath('data.session.starts_at', '17:00');
    expect(collect($this->asToken($this->desk)->getJson(($this->api)('sessions', $this->w->b1))->json('data'))->pluck('id'))->not->toContain($this->session['id']);
    ($this->book)($this->karim)->assertNotFound()->assertJsonPath('code', 'session_not_found');
    // In use: the doctor and the chamber stay.
    $this->asToken($this->owner)->deleteJson(($this->api)("doctors/{$this->doctor['id']}?base_version=3"))->assertStatus(409)->assertJsonPath('code', 'in_use');
    $this->asToken($this->owner)->deleteJson(($this->api)("chambers/{$this->chamber['id']}?base_version=1", $this->w->b1))->assertStatus(409)->assertJsonPath('code', 'in_use');
});

it('lets a doctor see and call in only their own patients when doctors see their own', function () {
    $mine = ($this->book)($this->rahim)->assertCreated()->json('data');
    $theirs = ($this->book)($this->karim, $this->otherSession)->assertCreated()->json('data');
    // Booking linked the doctor to their patient (Patients' care link).
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'own');
    $this->asToken($this->doctorToken)->getJson(($this->org)("patients/patients/{$this->rahim['id']}"))->assertOk();
    $this->asToken($this->doctorToken)->getJson(($this->org)("patients/patients/{$this->karim['id']}"))->assertNotFound();

    $list = $this->asToken($this->doctorToken)->getJson(($this->api)('serials', $this->w->b1))->assertOk()->json();
    expect(collect($list['data'])->pluck('id')->all())->toBe([$mine['id']])->and($list['meta']['own_only'])->toBeTrue();
    $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$theirs['id']}", $this->w->b1))->assertNotFound();
    ($this->move)($theirs, 'arrived', $this->doctorToken)->assertNotFound();
    ($this->move)($mine, 'arrived', $this->doctorToken)->assertForbidden()->assertJsonPath('code', 'desk_only');
    $arrived = ($this->move)($mine, 'arrived')->assertOk()->json('data');
    $in = ($this->move)($arrived, 'in', $this->doctorToken)->assertOk()->json('data');
    ($this->move)($in, 'done', $this->doctorToken)->assertOk();
    $this->asToken($this->doctorToken)->getJson(($this->api)('my-patients', $this->w->b1))->assertOk()->assertJsonPath('meta.linked', true)->assertJsonCount(1, 'data');

    // With everyone seeing all, the doctor lists every serial but still moves only their own.
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'all');
    expect($this->asToken($this->doctorToken)->getJson(($this->api)('serials', $this->w->b1))->json('data'))->toHaveCount(2);
    $arrivedTheirs = ($this->move)($theirs, 'arrived')->assertOk()->json('data');
    ($this->move)($arrivedTheirs, 'in', $this->doctorToken)->assertForbidden()->assertJsonPath('code', 'own_patients_only');
    ($this->move)($arrivedTheirs, 'in', $this->viewer)->assertForbidden();
});

it('shows the waiting-room screen with serial numbers only', function () {
    $rahim = ($this->book)($this->rahim)->assertCreated()->json('data');
    ($this->book)($this->karim)->assertCreated();
    $rahim = ($this->move)(($this->move)($rahim, 'arrived')->json('data'), 'in')->assertOk()->json('data');
    $screen = $this->asToken($this->viewer)->getJson(($this->api)("queue/{$this->chamber['id']}", $this->w->b1))->assertOk();
    expect($screen->json('data.doctors.0'))->toMatchArray(['name' => 'Dr. Ayesha Rahman', 'now' => [1], 'waiting' => [], 'booked' => 1])
        ->and($screen->getContent())->not->toContain('Rahim')->not->toContain('Karim')->not->toContain($this->rahim['mrn']);
    $this->asToken($this->viewer)->getJson(($this->api)('queue/'.str_repeat('0', 26), $this->w->b1))->assertNotFound();
});

it('texts the serial to the patient by rule, only with SMS on and a mobile', function () {
    ($this->book)($this->rahim)->assertCreated();
    orgRule($this->w->b1, 'outpatient.serial_sms', true);
    ($this->book)($this->karim)->assertCreated();
    expect(NotificationDelivery::query()->where('notification_key', 'outpatient.serial_booked')->count())->toBe(0);

    platformRule('notifications.sms_enabled', true);
    ($this->book)($this->rahim, $this->otherSession)->assertCreated();
    ($this->book)($this->karim, $this->otherSession)->assertCreated(); // no mobile
    $sent = NotificationDelivery::query()->where('notification_key', 'outpatient.serial_booked')->where('channel', 'sms')->get();
    expect($sent)->toHaveCount(1)->and($sent[0]->organization_id)->toBe($this->w->c1->id);
});

it('keeps each step to its permission and answers 403 with a module off', function () {
    ($this->book)($this->rahim, token: $this->viewer)->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('sessions', $this->w->b1))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('patients?q=Rahim'))->assertForbidden();
    $this->asToken($this->desk)->getJson(($this->api)('my-patients'))->assertForbidden();
    $this->asToken($this->desk)->getJson(($this->api)('patients?q=Rahim'))->assertOk()->assertJsonPath('data.0.id', $this->rahim['id']);
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can', ['manage' => false, 'book' => false, 'consult' => false]);
    $this->asToken($this->doctorToken)->getJson(($this->api)('my-patients'))->assertOk()->assertJsonPath('meta.linked', true);

    toggles()->disable($this->w->c1, 'outpatient', 'Test');
    $this->asToken($this->desk)->getJson(($this->api)('serials'))->assertForbidden();
    ($this->book)($this->rahim)->assertForbidden();
    toggles()->enable($this->w->c1, 'outpatient', 'Test');
    toggles()->disable($this->w->c1, 'patient_billing', 'Test', confirm: true);
    $this->asToken($this->desk)->getJson(($this->api)('serials'))->assertForbidden();
});

it('never shows or gives serials across companies or partners', function () {
    $serial = ($this->book)($this->rahim)->assertCreated()->json('data');
    $c2 = orgToken(createMember($this->w->c2), $this->w->c2);
    $c4 = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($c2)->getJson(($this->api)("serials/{$serial['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("serials/{$serial['id']}", $this->w->b1))->assertNotFound();
    $this->asToken($c4)->getJson(($this->api)("serials/{$serial['id']}", $this->w->b1))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)("serials/{$serial['id']}/status", $this->w->c2), ['status' => 'arrived', 'base_version' => 1])->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("queue/{$this->chamber['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("doctors/{$this->doctor['id']}/schedule", $this->w->c2))->assertNotFound();
    expect($this->asToken($c2)->getJson(($this->api)('serials', $this->w->c2))->json('data'))->toBe([]);

    // Another company's session or patient cannot be used.
    toggles()->enable($this->w->c2, 'outpatient', 'Test');
    $this->asToken($c2)->postJson(($this->api)('serials', $this->w->c2), ['schedule_id' => $this->session['id'], 'date' => '2026-11-02', 'patient_id' => $this->rahim['id']])->assertNotFound();
    $this->asToken($this->desk)->postJson(($this->api)('serials', $this->w->b1), ['schedule_id' => $this->session['id'], 'date' => '2026-11-02', 'patient_id' => str_repeat('1', 26)])
        ->assertNotFound()->assertJsonPath('code', 'patient_not_found');
});
