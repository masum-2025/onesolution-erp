<?php

use App\Models\User;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Outpatient\Events\ConsultationDone;
use Modules\Outpatient\Events\PrescriptionWritten;
use Modules\Outpatient\Events\TestsAdvised;

/*
 * H-3b Outpatient: the doctor's consultation for a serial (a draft with its
 * version, finishing makes it final and the serial done, an amendment is a
 * new revision with a reason), medicines from the pharmacy and tests from
 * the lab or written by hand, tests sent to the lab once, the printed
 * prescription in the rule's language, templates, the portal, who may read
 * and write, modules off, and isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'outpatient', 'diagnostics', 'inventory', 'pos', 'pharmacy'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $this->desk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['outpatient.view', 'outpatient.book'], 'Reception')), $this->w->c1);
    $doctorRole = makeRole($this->w->c1, ['outpatient.view', 'outpatient.consult', 'patients.view', 'patients.treat'], 'Doctor');
    $this->doctorUser = staffWithRoles($this->w->c1, $doctorRole);
    $this->doctorToken = orgToken($this->doctorUser, $this->w->c1);
    $this->otherDoctor = orgToken(staffWithRoles($this->w->c1, $doctorRole), $this->w->c1);
    $this->org = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/{$path}";
    $this->api = fn (string $path, $unit = null) => ($this->org)("outpatient/{$path}", $unit);

    foreach ([['FEE', 50000, 'consultation'], ['CBC', 40000, 'test']] as [$code, $price, $category]) {
        $this->asToken($this->owner)->postJson(($this->org)('patient-billing/services'), ['code' => $code, 'name' => ['en' => $code], 'category' => $category, 'price_minor' => $price, 'is_active' => true])->assertCreated();
    }
    $this->asToken($this->owner)->postJson(($this->org)('diagnostics/tests'), ['code' => 'CBC', 'name' => ['en' => 'Complete blood count'], 'department' => 'haematology', 'sample_type' => 'blood', 'service_code' => 'CBC', 'is_active' => true])->assertCreated();
    $generic = $this->asToken($this->owner)->postJson(($this->org)('pharmacy/generics'), ['name' => ['en' => 'Paracetamol', 'bn' => 'প্যারাসিটামল'], 'is_controlled' => false, 'in_use' => true])->assertCreated()->json('data');
    $this->napa = $this->asToken($this->owner)->postJson(($this->org)('pharmacy/medicines'), [
        'brand_name' => ['en' => 'Napa', 'bn' => 'নাপা'], 'sku' => 'NAPA500', 'generic_id' => $generic['id'], 'mrp_minor' => 120, 'form' => 'tablet', 'strength' => '500 mg',
    ])->assertCreated()->json('data');

    $this->doctor = $this->asToken($this->owner)->postJson(($this->api)('doctors'), [
        'name' => ['en' => 'Dr. Ayesha Rahman', 'bn' => 'ডা. আয়েশা রহমান'], 'degrees' => 'MBBS, FCPS', 'registration_no' => 'A-1001', 'fee_service_code' => 'FEE', 'is_active' => true,
    ])->assertCreated()->json('data');
    $this->chamber = $this->asToken($this->owner)->postJson(($this->api)('chambers'), ['name' => 'Chamber 1', 'room' => '101', 'is_active' => true])->assertCreated()->json('data');
    $version = $this->asToken($this->owner)->getJson(($this->api)("doctors/{$this->doctor['id']}/schedule"))->json('data.doctor.version');
    $this->session = $this->asToken($this->owner)->putJson(($this->api)("doctors/{$this->doctor['id']}/schedule"), [
        'user_id' => $this->doctorUser->id, 'base_version' => $version,
        'sessions' => [['chamber_id' => $this->chamber['id'], 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '20:00']],
    ])->assertOk()->json('data.sessions.0');
    $this->rahim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Rahim Uddin', 'sex' => 'male', 'mobile' => null, 'age_years' => 40])->assertCreated()->json('data');
    $this->serial = $this->asToken($this->desk)->postJson(($this->api)('serials'), ['schedule_id' => $this->session['id'], 'date' => '2026-11-02', 'patient_id' => $this->rahim['id']])->assertCreated()->json('data');

    $this->written = [
        'complaints' => 'Fever for 3 days', 'findings' => ['bp' => '120/80', 'temperature' => '101 F'], 'diagnosis' => 'Viral fever', 'advice' => 'Plenty of water',
        'follow_up_on' => '2026-11-09',
        'medicines' => [
            ['medicine_id' => $this->napa['id'], 'medicine_text' => 'Napa 500 mg', 'dose' => '1+1+1', 'duration' => '5 days', 'timing' => 'after_meal'],
            ['medicine_text' => 'ORS', 'dose' => 'after each loose stool', 'timing' => 'as_needed', 'note' => 'One packet in half a litre'],
        ],
        'tests' => [['test_code' => 'CBC', 'test_text' => 'Complete blood count'], ['test_text' => 'Dengue NS1']],
    ];
    $this->save = fn (array $data, ?int $version = null, $token = null) => $this->asToken($token ?? $this->doctorToken)
        ->putJson(($this->api)("serials/{$this->serial['id']}/consultation"), [...$data, 'base_version' => $version]);
    $this->finish = fn (int $version, $token = null) => $this->asToken($token ?? $this->doctorToken)
        ->postJson(($this->api)("serials/{$this->serial['id']}/consultation/finish"), ['base_version' => $version]);
});

it('writes, finishes and keeps a consultation, with medicines and tests from the lists', function () {
    Event::fake([ConsultationDone::class, PrescriptionWritten::class, TestsAdvised::class]);
    ($this->finish)(1)->assertUnprocessable()->assertJsonPath('code', 'nothing_written');
    $draft = ($this->save)(['complaints' => 'Fever'])->assertCreated()->json('data');
    expect($draft)->toMatchArray(['status' => 'draft', 'revision' => 1, 'version' => 1]);
    $saved = ($this->save)($this->written, 1)->assertOk()->json('data');
    expect($saved['version'])->toBe(2)->and($saved['medicines'][0])->toMatchArray(['medicine_id' => $this->napa['id'], 'dose' => '1+1+1', 'timing' => 'after_meal'])
        ->and($saved['tests'][1])->toMatchArray(['test_code' => null, 'test_text' => 'Dengue NS1'])->and($saved['findings'])->toBe(['bp' => '120/80', 'temperature' => '101 F']);
    ($this->save)($this->written, 1)->assertStatus(409)->assertJsonPath('code', 'version_conflict');

    $done = ($this->finish)(2)->assertOk()->json('data');
    expect($done['status'])->toBe('finished')->and($done['finished_at'])->not->toBeNull();
    $this->asToken($this->desk)->getJson(($this->api)("serials/{$this->serial['id']}"))->assertOk()->assertJsonPath('data.status', 'done');
    ($this->save)($this->written, 3)->assertStatus(409)->assertJsonPath('code', 'already_finished');
    Event::assertDispatched(ConsultationDone::class);
    Event::assertDispatched(PrescriptionWritten::class);
    Event::assertDispatched(TestsAdvised::class);

    // The patient's history: a line without the diagnosis.
    $history = $this->asToken($this->owner)->getJson(($this->org)("patients/patients/{$this->rahim['id']}/timeline"))->json('data.entries');
    $line = collect($history)->firstWhere('source_module', 'outpatient');
    expect($line)->not->toBeNull()->and($line['title'])->toBe('Seen by Dr. Ayesha Rahman')->and(json_encode($line))->not->toContain('Viral');

    // Unknown medicines and tests from the lists are refused; written ones are fine.
    $second = $this->asToken($this->desk)->postJson(($this->api)('serials'), ['schedule_id' => $this->session['id'], 'date' => '2026-11-09', 'patient_id' => $this->rahim['id']])->assertCreated()->json('data');
    $this->asToken($this->doctorToken)->putJson(($this->api)("serials/{$second['id']}/consultation"), ['medicines' => [['medicine_id' => str_repeat('1', 26), 'medicine_text' => 'X']]])
        ->assertUnprocessable()->assertJsonValidationErrors('medicines.0.medicine_id');
    $this->asToken($this->doctorToken)->putJson(($this->api)("serials/{$second['id']}/consultation"), ['tests' => [['test_code' => 'NOPE', 'test_text' => 'X']]])
        ->assertUnprocessable()->assertJsonValidationErrors('tests.0.test_code');
    $this->asToken($this->doctorToken)->putJson(($this->api)("serials/{$second['id']}/consultation"), ['medicines' => [['medicine_text' => 'X', 'timing' => 'whenever']]])
        ->assertUnprocessable()->assertJsonValidationErrors('medicines.0.timing');
    $this->asToken($this->doctorToken)->putJson(($this->api)("serials/{$second['id']}/consultation"), ['diagnosis' => 'Better', 'organization_id' => $this->w->c2->id])->assertUnprocessable();
    // The earlier visit shows in the next one's history.
    $next = $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$second['id']}/consultation"))->json('data');
    expect($next['history'])->toHaveCount(1)->and($next['history'][0])->toMatchArray(['diagnosis' => 'Viral fever', 'date' => '2026-11-02']);
});

it('amends a finished consultation as a new revision with a reason, the first kept', function () {
    ($this->save)($this->written)->assertCreated();
    ($this->finish)(1)->assertOk();
    $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$this->serial['id']}/consultation/amend"), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $next = $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$this->serial['id']}/consultation/amend"), ['reason' => 'Wrong dose'])->assertCreated()->json('data');
    expect($next)->toMatchArray(['revision' => 2, 'status' => 'draft', 'amend_reason' => 'Wrong dose'])->and($next['medicines'])->toHaveCount(2);
    // Until the new revision is finished, the first is the prescription.
    $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$this->serial['id']}/prescription"))->assertOk()->assertJsonPath('data.revision', 1);
    $changed = ($this->save)([...$this->written, 'medicines' => [['medicine_id' => $this->napa['id'], 'medicine_text' => 'Napa 500 mg', 'dose' => '1+0+1']]], $next['version'])->assertOk()->json('data');
    ($this->finish)($changed['version'])->assertOk();
    $print = $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$this->serial['id']}/prescription"))->assertOk()->json('data');
    expect($print['revision'])->toBe(2)->and($print['medicines'])->toHaveCount(1)->and($print['language'])->toBe('bn')->and($print['doctor']['registration_no'])->toBe('A-1001');
    $revisions = $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$this->serial['id']}/consultation"))->json('data.revisions');
    expect($revisions)->toHaveCount(2)->and($revisions[0]['superseded'])->toBeTrue()->and($revisions[1]['amend_reason'])->toBe('Wrong dose');

    // The language comes from the rule at the chamber's unit.
    orgRule($this->w->c1, 'outpatient.prescription_language', 'both');
    $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$this->serial['id']}/prescription"))->assertJsonPath('data.language', 'both');
});

it('sends the lab tests once, billed by the lab', function () {
    ($this->save)($this->written)->assertCreated();
    $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$this->serial['id']}/consultation/send-tests"))->assertStatus(409)->assertJsonPath('code', 'not_finished');
    ($this->finish)(1)->assertOk();
    $sent = $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$this->serial['id']}/consultation/send-tests"))->assertOk()->json('data');
    expect($sent['lab_order_id'])->not->toBeNull();
    $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$this->serial['id']}/consultation/send-tests"))->assertOk()->assertJsonPath('data.lab_order_id', $sent['lab_order_id']);
    $order = $this->asToken($this->owner)->getJson(($this->org)("diagnostics/orders/{$sent['lab_order_id']}"))->assertOk()->json('data');
    expect(collect($order['tests'])->pluck('code')->all())->toBe(['CBC'])->and($order['patient']['id'])->toBe($this->rahim['id']);
});

it('picks medicines from the pharmacy, and writes them by hand with Pharmacy or Diagnostics off', function () {
    $found = $this->asToken($this->doctorToken)->getJson(($this->api)('medicines?q=nap'))->assertOk()->json('data');
    expect($found[0])->toMatchArray(['id' => $this->napa['id'], 'strength' => '500 mg', 'generic' => 'Paracetamol']);
    $this->asToken($this->desk)->getJson(($this->api)('medicines?q=nap'))->assertForbidden();

    toggles()->disable($this->w->c1, 'pharmacy', 'Test');
    toggles()->disable($this->w->c1, 'diagnostics', 'Test');
    $this->asToken($this->doctorToken)->getJson(($this->api)('medicines?q=nap'))->assertForbidden()->assertJsonPath('code', 'pharmacy_off');
    $screen = $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$this->serial['id']}/consultation"))->assertOk()->json('data.options');
    expect($screen)->toMatchArray(['pharmacy' => false, 'lab' => false, 'tests' => []]);
    // A medicine id from the list cannot be checked any more: refused; by name works.
    ($this->save)(['medicines' => [['medicine_id' => $this->napa['id'], 'medicine_text' => 'Napa']]])->assertUnprocessable();
    ($this->save)(['diagnosis' => 'Viral fever', 'medicines' => [['medicine_text' => 'Napa 500 mg', 'dose' => '1+1+1']], 'tests' => [['test_text' => 'CBC']]])->assertCreated();
    ($this->finish)(1)->assertOk();
    $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$this->serial['id']}/consultation/send-tests"))->assertForbidden()->assertJsonPath('code', 'lab_off');
});

it('lets only the serial\'s doctor (or anyone who consults, for a doctor without a sign-in) read and write', function () {
    $this->asToken($this->desk)->getJson(($this->api)("serials/{$this->serial['id']}/consultation"))->assertForbidden();
    ($this->save)($this->written, null, $this->desk)->assertForbidden()->assertJsonPath('code', 'consult_only');
    ($this->save)($this->written, null, $this->otherDoctor)->assertForbidden()->assertJsonPath('code', 'own_patients_only');
    // With doctors seeing only their own, another doctor does not even find the serial.
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'own');
    $this->asToken($this->otherDoctor)->getJson(($this->api)("serials/{$this->serial['id']}/consultation"))->assertNotFound();
    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'all');

    // A doctor without a sign-in: anyone who consults writes for them.
    $version = $this->asToken($this->owner)->getJson(($this->api)("doctors/{$this->doctor['id']}/schedule"))->json('data.doctor.version');
    $this->asToken($this->owner)->putJson(($this->api)("doctors/{$this->doctor['id']}/schedule"), ['user_id' => null, 'base_version' => $version, 'sessions' => [[...$this->session]]])->assertOk();
    ($this->save)($this->written, null, $this->otherDoctor)->assertCreated();

    // Cancelled serials take no consultation.
    $karim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Karim', 'sex' => 'male', 'mobile' => null, 'age_years' => 30])->assertCreated()->json('data');
    $other = $this->asToken($this->desk)->postJson(($this->api)('serials'), ['schedule_id' => $this->session['id'], 'date' => '2026-11-02', 'patient_id' => $karim['id']])->assertCreated()->json('data');
    $this->asToken($this->desk)->postJson(($this->api)("serials/{$other['id']}/status"), ['status' => 'cancelled', 'reason' => 'Did not come', 'base_version' => $other['version']])->assertOk();
    $this->asToken($this->otherDoctor)->putJson(($this->api)("serials/{$other['id']}/consultation"), ['diagnosis' => 'X'])->assertStatus(409)->assertJsonPath('code', 'serial_cancelled');

    toggles()->disable($this->w->c1, 'outpatient', 'Test');
    $this->asToken($this->doctorToken)->getJson(($this->api)("serials/{$this->serial['id']}/consultation"))->assertForbidden();
});

it('keeps templates: a doctor\'s own and shared ones made by managers', function () {
    $body = ['advice' => 'Rest', 'medicines' => [['medicine_text' => 'Napa 500 mg', 'dose' => '1+1+1', 'timing' => 'after_meal']], 'tests' => []];
    $mine = $this->asToken($this->doctorToken)->postJson(($this->api)('templates'), ['name' => 'Fever', 'body' => $body])->assertCreated()->json('data');
    expect($mine['shared'])->toBeFalse();
    $this->asToken($this->doctorToken)->postJson(($this->api)('templates'), ['name' => 'Shared', 'shared' => true, 'body' => $body])->assertForbidden()->assertJsonPath('code', 'shared_templates_managers');
    $this->asToken($this->otherDoctor)->postJson(($this->api)('templates'), ['name' => 'Mine', 'body' => $body])->assertUnprocessable()->assertJsonPath('code', 'not_linked');
    $shared = $this->asToken($this->owner)->postJson(($this->api)('templates'), ['name' => 'Cough', 'shared' => true, 'body' => $body])->assertCreated()->json('data');
    $this->asToken($this->doctorToken)->postJson(($this->api)('templates'), ['name' => 'Bad', 'body' => ['medicines' => [['dose' => '1']]]])->assertUnprocessable()->assertJsonValidationErrors('body.medicines.0.medicine_text');

    expect(collect($this->asToken($this->doctorToken)->getJson(($this->api)('templates'))->json('data'))->pluck('name')->all())->toBe(['Cough', 'Fever']);
    expect(collect($this->asToken($this->otherDoctor)->getJson(($this->api)('templates'))->json('data'))->pluck('name')->all())->toBe(['Cough']);
    $this->asToken($this->otherDoctor)->patchJson(($this->api)("templates/{$mine['id']}"), ['name' => 'X', 'body' => $body, 'base_version' => 1])->assertNotFound();
    $this->asToken($this->doctorToken)->patchJson(($this->api)("templates/{$mine['id']}"), ['name' => 'Fever (adult)', 'body' => $body, 'base_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    $this->asToken($this->doctorToken)->deleteJson(($this->api)("templates/{$shared['id']}?base_version=1"))->assertForbidden();
    $this->asToken($this->doctorToken)->deleteJson(($this->api)("templates/{$mine['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->desk)->getJson(($this->api)('templates'))->assertForbidden();
});

it('shows portal patients only their own prescriptions in force', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    ($this->save)($this->written)->assertCreated();
    ($this->finish)(1)->assertOk();
    $karim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Karim', 'sex' => 'male', 'mobile' => null, 'age_years' => 30])->assertCreated()->json('data');
    $other = $this->asToken($this->desk)->postJson(($this->api)('serials'), ['schedule_id' => $this->session['id'], 'date' => '2026-11-02', 'patient_id' => $karim['id']])->assertCreated()->json('data');
    $this->asToken($this->doctorToken)->putJson(($this->api)("serials/{$other['id']}/consultation"), ['diagnosis' => 'Karim only'])->assertCreated();
    $this->asToken($this->doctorToken)->postJson(($this->api)("serials/{$other['id']}/consultation/finish"), ['base_version' => 1])->assertOk();

    $person = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $person, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $person->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $person->id,
        'subject_type' => 'patients.patient', 'subject_id' => $this->rahim['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($person, $this->w->c1);
    $this->withHeader('Origin', config('app.url'));
    $seen = $this->asToken($portal)->getJson('/api/portal/outpatient/prescriptions')->assertOk()->json('data');
    expect($seen['prescriptions'])->toHaveCount(1)->and($seen['prescriptions'][0])->toMatchArray(['diagnosis' => 'Viral fever', 'date' => '2026-11-02'])
        ->and($seen['prescriptions'][0])->not->toHaveKey('lab_order_id')->and(json_encode($seen))->not->toContain('Karim only');
    // The portal member cannot reach the staff screens.
    $this->asToken($portal)->getJson(($this->api)("serials/{$this->serial['id']}/consultation"))->assertForbidden();
});

it('never shows or writes another company\'s or partner\'s consultations', function () {
    ($this->save)($this->written)->assertCreated();
    // Another partner's group with the same modules on.
    setPlan($this->w->g3, 'business');
    foreach (['patients', 'patient_billing', 'outpatient'] as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    foreach ([$this->w->c2, $this->w->c4] as $company) {
        $token = orgToken(createMember($company), $company);
        $this->asToken($token)->getJson(($this->api)("serials/{$this->serial['id']}/consultation", $company))->assertNotFound();
        $this->asToken($token)->putJson(($this->api)("serials/{$this->serial['id']}/consultation", $company), ['diagnosis' => 'X'])->assertNotFound();
        $this->asToken($token)->getJson(($this->api)("serials/{$this->serial['id']}/prescription", $company))->assertNotFound();
        expect($this->asToken($token)->getJson(($this->api)('templates', $company))->json('data') ?? [])->toBe([]);
    }
});
