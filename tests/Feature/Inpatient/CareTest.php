<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Inpatient\Events\Discharged;
use Modules\Inpatient\Events\TestsOrdered;
use Modules\Inpatient\Services\BedCharges;

/*
 * H-4b Inpatient: doctors' round notes and nurses' notes (append-only, op
 * ids, each only by their own permission, read only by those who give care),
 * tests sent to the lab once, the discharge summary (draft, then signed at
 * discharge, amended as a new revision), the final bed days at discharge,
 * discharge blocked while money is due (a rule), the paper in the rule's
 * language, the portal, modules off, and tenant and partner isolation.
 */

beforeEach(function () {
    // Monday 2 November 2026, 10:00 in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'inpatient', 'diagnostics'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $this->desk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inpatient.view', 'inpatient.admit'], 'Admission desk')), $this->w->c1);
    $doctorRole = makeRole($this->w->c1, ['inpatient.view', 'inpatient.round', 'patients.view', 'patients.treat'], 'Doctor');
    $this->doctorUser = staffWithRoles($this->w->c1, $doctorRole);
    $this->doctor = orgToken($this->doctorUser, $this->w->c1);
    $this->otherDoctor = orgToken(staffWithRoles($this->w->c1, $doctorRole), $this->w->c1);
    $this->nurseUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inpatient.view', 'inpatient.nurse'], 'Nurse'));
    $this->nurse = orgToken($this->nurseUser, $this->w->c1);
    $this->org = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/{$path}";
    $this->api = fn (string $path, $unit = null) => ($this->org)("inpatient/{$path}", $unit);

    foreach ([['BED-GEN', 100000, 'bed'], ['CBC', 40000, 'test']] as [$code, $price, $category]) {
        $this->asToken($this->owner)->postJson(($this->org)('patient-billing/services'), ['code' => $code, 'name' => ['en' => $code], 'category' => $category, 'price_minor' => $price, 'is_active' => true])->assertCreated();
    }
    $this->asToken($this->owner)->postJson(($this->org)('diagnostics/tests'), ['code' => 'CBC', 'name' => ['en' => 'Complete blood count'], 'department' => 'haematology', 'sample_type' => 'blood', 'service_code' => 'CBC', 'is_active' => true])->assertCreated();
    $ward = $this->asToken($this->owner)->postJson(($this->api)('wards', $this->w->b1), ['name' => ['en' => 'Male ward', 'bn' => 'পুরুষ ওয়ার্ড'], 'kind' => 'general', 'is_active' => true])->assertCreated()->json('data');
    $this->bed = $this->asToken($this->owner)->postJson(($this->api)('beds', $this->w->b1), ['ward_id' => $ward['id'], 'code' => 'M-1', 'service_code' => 'BED-GEN'])->assertCreated()->json('data');
    $this->rahim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Rahim Uddin', 'sex' => 'male', 'mobile' => null, 'age_years' => 50])->assertCreated()->json('data');
    $this->stay = $this->asToken($this->desk)->postJson(($this->api)('admissions', $this->w->b1), [
        'patient_id' => $this->rahim['id'], 'bed_id' => $this->bed['id'], 'doctor_user_id' => $this->doctorUser->id, 'reason' => 'Chest pain',
    ])->assertCreated()->json('data');
    $this->at = fn (string $path) => ($this->api)("admissions/{$this->stay['id']}/{$path}", $this->w->b1);
    $this->summary = ['diagnosis' => 'Acute gastritis', 'treatment' => 'IV fluids and antacids', 'advice' => 'Light food for a week', 'follow_up_on' => '2026-11-12', 'outcome' => 'improved'];
    // Reads as the owner: call it before choosing who sends the next request.
    $this->version = fn () => $this->asToken($this->owner)->getJson(($this->api)("admissions/{$this->stay['id']}", $this->w->b1))->json('data.version');
    $this->pay = fn (string $billId, int $amount) => $this->asToken($this->owner)->postJson(($this->org)('patient-billing/payments', $this->w->b1), [
        'op_id' => 'pay-'.$amount, 'kind' => 'payment', 'bill_id' => $billId, 'method' => 'card', 'amount_minor' => $amount,
    ])->assertCreated();
});

it('keeps round and nursing notes, each written only by its own role', function () {
    $this->asToken($this->doctor)->postJson(($this->at)('rounds'), ['note' => 'Pain less. Continue fluids.', 'op_id' => 'r-1'])->assertCreated();
    $this->asToken($this->doctor)->postJson(($this->at)('rounds'), ['note' => 'Pain less. Continue fluids.', 'op_id' => 'r-1'])->assertOk();
    $this->asToken($this->nurse)->postJson(($this->at)('nursing-notes'), [
        'vitals' => ['bp_systolic' => 120, 'bp_diastolic' => 80, 'pulse' => 84, 'temperature' => 99.4, 'spo2' => 97],
        'note' => 'Slept well', 'medicines_given' => [['name' => 'Inj. Omeprazole', 'dose' => '40 mg', 'route' => 'iv']], 'op_id' => 'n-1',
    ])->assertCreated();

    // Each to their own: a nurse writes no round, a doctor no nursing note, the desk neither (and reads none).
    $this->asToken($this->nurse)->postJson(($this->at)('rounds'), ['note' => 'X'.'yz'])->assertForbidden();
    $this->asToken($this->doctor)->postJson(($this->at)('nursing-notes'), ['note' => 'Xyz'])->assertForbidden();
    $this->asToken($this->desk)->getJson(($this->at)('care'))->assertForbidden()->assertJsonPath('code', 'care_only');
    // Checks: something to write, known vitals in range, no unknown fields.
    $this->asToken($this->nurse)->postJson(($this->at)('nursing-notes'), ['vitals' => []])->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->asToken($this->nurse)->postJson(($this->at)('nursing-notes'), ['vitals' => ['pulse' => 900]])->assertUnprocessable()->assertJsonValidationErrors('vitals.pulse');
    $this->asToken($this->nurse)->postJson(($this->at)('nursing-notes'), ['vitals' => ['mood' => 'ok']])->assertUnprocessable();
    $this->asToken($this->nurse)->postJson(($this->at)('nursing-notes'), ['note' => 'Ok', 'organization_id' => $this->w->c2->id])->assertUnprocessable();

    $care = $this->asToken($this->nurse)->getJson(($this->at)('care'))->assertOk()->json('data');
    expect($care['rounds'])->toHaveCount(1)->and($care['rounds'][0]['note'])->toBe('Pain less. Continue fluids.')
        ->and($care['nursing_notes'])->toHaveCount(1)->and($care['nursing_notes'][0]['vitals'])->toMatchArray(['pulse' => 84, 'spo2' => 97])
        ->and($care['nursing_notes'][0]['medicines_given'][0])->toMatchArray(['name' => 'Inj. Omeprazole', 'route' => 'iv'])
        ->and($care['can'])->toMatchArray(['round' => false, 'nurse' => true])->and($care['catalogue'])->toBe([]);
    // The audit keeps counts, never what was written.
    $audit = AuditLog::query()->whereIn('action', ['inpatient.round_added', 'inpatient.nursing_note_added'])->get();
    expect($audit)->toHaveCount(2)->and(json_encode($audit->pluck('new_values')))->not->toContain('Pain less')->not->toContain('Omeprazole');

    // A doctor limited to their own patients does not reach another doctor's patient.
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'own');
    $this->asToken($this->otherDoctor)->getJson(($this->at)('care'))->assertNotFound();
    $this->asToken($this->otherDoctor)->postJson(($this->at)('rounds'), ['note' => 'Not mine'])->assertNotFound();
    $this->asToken($this->doctor)->getJson(($this->at)('care'))->assertOk();
});

it('sends tests to the lab once, billed by the lab', function () {
    Event::fake([TestsOrdered::class]);
    $order = $this->asToken($this->doctor)->postJson(($this->at)('tests'), ['codes' => ['CBC'], 'op_id' => 't-1'])->assertCreated()->json('data');
    $this->asToken($this->doctor)->postJson(($this->at)('tests'), ['codes' => ['CBC'], 'op_id' => 't-1'])->assertOk()->assertJsonPath('data.lab_order_id', $order['lab_order_id']);
    Event::assertDispatchedTimes(TestsOrdered::class, 1);
    $lab = $this->asToken($this->owner)->getJson(($this->org)("diagnostics/orders/{$order['lab_order_id']}"))->assertOk()->json('data');
    expect($lab['patient']['id'] ?? $lab['patient_id'] ?? null)->toBe($this->rahim['id']);
    $care = $this->asToken($this->doctor)->getJson(($this->at)('care'))->assertOk()->json('data');
    expect($care['test_orders'])->toHaveCount(1)->and($care['test_orders'][0]['tests'][0])->toMatchArray(['code' => 'CBC', 'name' => 'Complete blood count'])
        ->and(collect($care['catalogue'])->pluck('code')->all())->toBe(['CBC']);

    $this->asToken($this->doctor)->postJson(($this->at)('tests'), ['codes' => ['XRAY']])->assertUnprocessable()->assertJsonValidationErrors('codes.0');
    $this->asToken($this->nurse)->postJson(($this->at)('tests'), ['codes' => ['CBC']])->assertForbidden();
    toggles()->disable($this->w->c1, 'diagnostics', 'Test');
    $this->asToken($this->doctor)->postJson(($this->at)('tests'), ['codes' => ['CBC']])->assertForbidden()->assertJsonPath('code', 'lab_off');
    expect($this->asToken($this->doctor)->getJson(($this->at)('care'))->assertOk()->json('data.lab'))->toBeFalse();
});

it('discharges only when nothing is due, with the final bed days', function () {
    Event::fake([Discharged::class]);
    // In since Monday 10:00; on Wednesday 08:00 the bill has three bed days (midnight rule).
    $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00', 'Asia/Dhaka'));
    app(BedCharges::class)->run();
    // Sign-ins made on Monday have run out by now.
    [$this->owner, $this->doctor, $this->nurse] = [orgToken($this->ownerUser, $this->w->c1), orgToken($this->doctorUser, $this->w->c1), orgToken($this->nurseUser, $this->w->c1)];
    $stay = $this->asToken($this->owner)->getJson(($this->api)("admissions/{$this->stay['id']}", $this->w->b1))->json('data');
    expect($stay['bed_days'])->toHaveCount(3)->and($stay['bill_due_minor'])->toBe(300000);

    $this->asToken($this->owner)->postJson(($this->at)('discharge/finish'), ['base_version' => ($this->version)()])->assertUnprocessable()->assertJsonPath('code', 'summary_needed');
    $this->asToken($this->doctor)->putJson(($this->at)('discharge'), $this->summary)->assertForbidden();
    $draft = $this->asToken($this->owner)->putJson(($this->at)('discharge'), $this->summary)->assertCreated()->json('data');
    $this->asToken($this->owner)->putJson(($this->at)('discharge'), [...$this->summary, 'base_version' => 9])->assertStatus(409);
    $this->asToken($this->owner)->putJson(($this->at)('discharge'), [...$this->summary, 'outcome' => 'gone'])->assertUnprocessable()->assertJsonValidationErrors('outcome');

    // The discharge day does not count: two days are due, not three.
    $view = $this->asToken($this->nurse)->getJson(($this->at)('discharge'))->assertOk()->json('data');
    expect($view['preview'])->toMatchArray(['bed_days' => 2, 'due_minor' => 200000, 'clearance_required' => true])->and($view['summary']['version'])->toBe($draft['version']);
    $this->asToken($this->owner)->postJson(($this->at)('discharge/finish'), ['base_version' => ($this->version)()])->assertUnprocessable()
        ->assertJsonPath('code', 'dues_left')->assertJsonPath('due_minor', 200000);
    ($this->pay)($stay['bill_id'], 200000);
    $version = ($this->version)();
    $this->asToken($this->nurse)->postJson(($this->at)('discharge/finish'), ['base_version' => $version])->assertForbidden();
    $this->asToken($this->owner)->postJson(($this->at)('discharge/finish'), ['base_version' => ($this->version)()])->assertOk()->assertJsonPath('data.status', 'discharged');
    Event::assertDispatchedTimes(Discharged::class, 1);

    $after = $this->asToken($this->owner)->getJson(($this->api)("admissions/{$this->stay['id']}", $this->w->b1))->json('data');
    expect($after['bed_days'])->toHaveCount(2)->and($after['bill_due_minor'])->toBe(0)->and($after['status'])->toBe('discharged');
    $board = collect($this->asToken($this->owner)->getJson(($this->api)('beds/board', $this->w->b1))->json('data'))->flatMap(fn ($ward) => $ward['beds']);
    expect($board->firstWhere('code', 'M-1')['status'])->toBe('cleaning');
    // Nothing more is written on a stay that ended, and the job charges no more days.
    $this->asToken($this->doctor)->postJson(($this->at)('rounds'), ['note' => 'Too late'])->assertStatus(409);
    $this->asToken($this->owner)->putJson(($this->at)('discharge'), $this->summary)->assertStatus(409);
    $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00', 'Asia/Dhaka'));
    expect(app(BedCharges::class)->run())->toBe(0);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    // History line without the diagnosis; audit without it either.
    $timeline = $this->asToken($this->owner)->getJson(($this->org)("patients/patients/{$this->rahim['id']}/timeline"))->json('data');
    expect(json_encode($timeline))->toContain('IP000001')->not->toContain('gastritis');
    expect(json_encode(AuditLog::query()->where('action', 'inpatient.discharged')->sole()->new_values))->not->toContain('gastritis');
});

it('discharges with money due when the rule allows it', function () {
    trustedOrgRule($this->w->c1, 'inpatient.discharge_requires_clearance', false);
    $this->asToken($this->owner)->putJson(($this->at)('discharge'), $this->summary)->assertCreated();
    $this->asToken($this->owner)->postJson(($this->at)('discharge/finish'), ['base_version' => ($this->version)()])->assertOk();
    // Same day: one bed day stays on the bill, still due.
    $after = $this->asToken($this->owner)->getJson(($this->api)("admissions/{$this->stay['id']}", $this->w->b1))->json('data');
    expect($after['bed_days'])->toHaveCount(1)->and($after['bill_due_minor'])->toBe(100000);
});

it('amends a signed discharge as a new revision and prints in the rule\'s language', function () {
    trustedOrgRule($this->w->c1, 'inpatient.discharge_requires_clearance', false);
    orgRule($this->w->c1, 'inpatient.discharge_language', 'both');
    $this->asToken($this->owner)->postJson(($this->at)('discharge/amend'), [...$this->summary, 'reason' => 'Typo'])->assertStatus(409)->assertJsonPath('code', 'not_discharged');
    $this->asToken($this->owner)->getJson(($this->at)('discharge/paper'))->assertNotFound();
    $this->asToken($this->owner)->putJson(($this->at)('discharge'), $this->summary)->assertCreated();
    $this->asToken($this->owner)->postJson(($this->at)('discharge/finish'), ['base_version' => ($this->version)()])->assertOk();

    $this->asToken($this->owner)->postJson(($this->at)('discharge/amend'), [...$this->summary, 'diagnosis' => 'Peptic ulcer'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->owner)->postJson(($this->at)('discharge/amend'), [...$this->summary, 'diagnosis' => 'Peptic ulcer', 'reason' => 'Endoscopy report came'])->assertCreated()->assertJsonPath('data.revision', 2);
    $view = $this->asToken($this->owner)->getJson(($this->at)('discharge'))->assertOk()->json('data');
    expect($view['revisions'])->toHaveCount(2)->and($view['revisions'][0])->toMatchArray(['revision' => 1, 'superseded' => true])
        ->and($view['revisions'][1])->toMatchArray(['revision' => 2, 'superseded' => false, 'amend_reason' => 'Endoscopy report came'])->and($view['preview'])->toBeNull();
    $paper = $this->asToken($this->doctor)->getJson(($this->at)('discharge/paper'))->assertOk()->json('data');
    expect($paper)->toMatchArray(['number' => 'IP000001', 'diagnosis' => 'Peptic ulcer', 'language' => 'both', 'outcome' => 'improved'])
        ->and($paper['patient']['name'])->toBe('Rahim Uddin')->and($paper['bed']['code'])->toBe('M-1');
    $this->asToken($this->desk)->getJson(($this->at)('discharge/paper'))->assertForbidden();
});

it('shows portal patients only their own signed discharges', function () {
    trustedOrgRule($this->w->c1, 'inpatient.discharge_requires_clearance', false);
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $this->asToken($this->owner)->putJson(($this->at)('discharge'), $this->summary)->assertCreated();
    $this->asToken($this->owner)->postJson(($this->at)('discharge/finish'), ['base_version' => ($this->version)()])->assertOk();

    $karim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Karim', 'sex' => 'male', 'mobile' => null, 'age_years' => 30])->assertCreated()->json('data');
    $portalFor = function (string $patientId) {
        $person = User::factory()->create(['email_verified_at' => now()]);
        app(AddMember::class)->handle($this->w->c1, $person, MembershipType::Portal, AccessScope::Own);
        $membership = OrganizationMembership::query()->where('user_id', $person->id)->where('organization_id', $this->w->c1->id)->sole();
        (new PortalLink)->forceFill([
            'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $person->id,
            'subject_type' => 'patients.patient', 'subject_id' => $patientId, 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
        ])->save();

        return orgToken($person, $this->w->c1);
    };
    $this->withHeader('Origin', config('app.url'));
    // Linked to Karim: Rahim's discharge is not theirs.
    expect($this->asToken($portalFor($karim['id']))->getJson('/api/portal/inpatient/discharges')->assertOk()->json('data.discharges'))->toBe([]);
    $portal = $portalFor($this->rahim['id']);
    $seen = $this->asToken($portal)->getJson('/api/portal/inpatient/discharges')->assertOk()->json('data.discharges');
    expect($seen)->toHaveCount(1)->and($seen[0])->toMatchArray(['number' => 'IP000001', 'diagnosis' => 'Acute gastritis'])->and($seen[0])->not->toHaveKey('signed_by');
    $this->asToken($portal)->getJson(($this->at)('discharge'))->assertForbidden();
});

it('refuses with the module off and never reaches another company\'s or partner\'s stays', function () {
    setPlan($this->w->g3, 'business');
    foreach (['patients', 'patient_billing', 'inpatient'] as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    foreach ([$this->w->c2, $this->w->c4] as $company) {
        $token = orgToken(createMember($company), $company);
        $this->asToken($token)->getJson(($this->api)("admissions/{$this->stay['id']}/care", $company))->assertNotFound();
        $this->asToken($token)->postJson(($this->api)("admissions/{$this->stay['id']}/rounds", $company), ['note' => 'Xyz'])->assertNotFound();
        $this->asToken($token)->getJson(($this->api)("admissions/{$this->stay['id']}/discharge/paper", $company))->assertNotFound();
        $this->asToken($token)->postJson(($this->api)("admissions/{$this->stay['id']}/discharge/finish", $company), ['base_version' => 1])->assertNotFound();
    }
    toggles()->disable($this->w->c1, 'inpatient', 'Test');
    $this->asToken($this->doctor)->postJson(($this->at)('rounds'), ['note' => 'Xyz'])->assertForbidden();
    $this->asToken($this->owner)->getJson(($this->at)('discharge'))->assertForbidden();
});
