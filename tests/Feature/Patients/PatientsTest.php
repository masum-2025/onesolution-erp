<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Patients\Contracts\PatientDirectory;
use Modules\Patients\Events\PatientRegistered;
use Modules\Patients\Events\PatientsMerged;

/*
 * H-1 Patients: registering (patient number, mobile in one form, age as an
 * estimated birth date, look-alike warning), encrypted ID number and
 * allergies, the "who looked" log, doctors seeing only their own patients,
 * merging with a second person, the directory other modules use, the portal,
 * rules by level, permissions, the module off, and tenant/partner isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    toggles()->enable($this->w->g1, 'patients', 'Test setup');

    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $this->reception = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['patients.view', 'patients.register'], 'Reception')), $this->w->c1);
    $this->doctorUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['patients.view', 'patients.treat'], 'Doctor'));
    $this->doctor = orgToken($this->doctorUser, $this->w->c1);
    $this->managerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['patients.view', 'patients.register', 'patients.manage'], 'Records'));
    $this->manager = orgToken($this->managerUser, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/patients/{$path}";
    $this->register = fn (array $data = [], ?string $token = null) => $this->asToken($token ?? $this->reception)->postJson(($this->api)('patients'), [
        'name' => 'Rahim Uddin', 'sex' => 'male', 'age_years' => 40, 'mobile' => '01712-345678', ...$data,
    ]);
});

it('registers a patient: next patient number, mobile in one form, age kept as an estimated birth date, a first history line', function () {
    Event::fake([PatientRegistered::class]);
    $made = ($this->register)(['op_id' => 'tap-1', 'allergies' => 'Penicillin', 'blood_group' => 'B+'])->assertCreated()
        ->assertJsonPath('data.mrn', 'P000001')
        ->assertJsonPath('data.mobile', '+8801712345678')
        ->assertJsonPath('data.age', 40)
        ->assertJsonPath('data.dob_estimated', true)
        ->assertJsonPath('data.date_of_birth', '1986-11-02')
        ->json('data');
    ($this->register)(['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $made['id']);
    ($this->register)(['name' => 'Karima Begum', 'sex' => 'female', 'mobile' => '01812345678', 'date_of_birth' => '1990-05-01'])->assertCreated()
        ->assertJsonPath('data.mrn', 'P000002')->assertJsonPath('data.dob_estimated', false)->assertJsonPath('data.age', 36);
    Event::assertDispatched(PatientRegistered::class, fn ($event) => $event->patientId === $made['id'] && $event->companyId === $this->w->c1->id);

    $history = $this->asToken($this->reception)->getJson(($this->api)("patients/{$made['id']}/timeline"))->assertOk()->json('data.entries');
    expect($history)->toHaveCount(1)->and($history[0]['source_type'])->toBe('registration');

    // Found by number, by mobile typed another way (Bangla digits too) and by part of the name.
    foreach (['P000001', '+880 1712 345678', '০১৭১২৩৪৫৬৭৮', 'rahim'] as $term) {
        expect(collect($this->asToken($this->reception)->getJson(($this->api)('patients?q='.urlencode($term)))->assertOk()->json('data'))->pluck('id')->all())->toBe([$made['id']]);
    }
});

it('warns about the same patient before making another, unless the person confirms', function () {
    $first = ($this->register)()->assertCreated()->json('data');
    ($this->register)(['name' => 'Rahim'])->assertStatus(409)->assertJsonPath('code', 'possible_duplicate')->assertJsonPath('matches.0.id', $first['id']);
    // Same mobile, different person (a wife on her husband's phone): no warning with mobile_name.
    ($this->register)(['name' => 'Salma Khatun', 'sex' => 'female'])->assertCreated();
    ($this->register)(['name' => 'Rahim', 'confirm_new' => true])->assertCreated()->assertJsonPath('data.mrn', 'P000003');

    // The same ID number always warns, even with the check off.
    orgRule($this->w->c1, 'patients.duplicate_check', 'off');
    ($this->register)(['name' => 'A', 'mobile' => null, 'id_type' => 'nid', 'id_number' => '1990 1234 5678'])->assertUnprocessable();
    ($this->register)(['name' => 'Abul Kalam', 'mobile' => null, 'id_type' => 'nid', 'id_number' => '1990 1234 5678'])->assertCreated();
    ($this->register)(['name' => 'Someone Else', 'mobile' => null, 'id_type' => 'nid', 'id_number' => '199012345678'])->assertStatus(409)
        ->assertJsonPath('matches.0.same_id_number', true);
});

it('checks what is sent and says what to do next', function () {
    $this->asToken($this->reception)->postJson(($this->api)('patients'), [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'sex']);
    $this->asToken($this->reception)->postJson(($this->api)('patients'), ['name' => 'X Y', 'sex' => 'male', 'organization_id' => $this->w->c2->id, 'age_years' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors(['organization_id']);
    ($this->register)(['age_years' => null])->assertUnprocessable()->assertJsonPath('code', 'age_or_birth_date');
    ($this->register)(['mobile' => '12345'])->assertUnprocessable()->assertJsonPath('code', 'bad_mobile');
    ($this->register)(['date_of_birth' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth']);
    ($this->register)(['id_number' => '123'])->assertUnprocessable()->assertJsonValidationErrors(['id_type']);

    orgRule($this->w->c1, 'patients.require_id_number', true);
    ($this->register)()->assertUnprocessable()->assertJsonPath('code', 'id_number_required');
    ($this->register)(['id_type' => 'birth_reg', 'id_number' => '20261234567890123'])->assertCreated();
});

it('keeps the ID number and allergies encrypted, shows the ID number only to people who may change patients, and audits no values', function () {
    $made = ($this->register)(['id_type' => 'nid', 'id_number' => '1990123456789', 'allergies' => 'Penicillin'])->assertCreated()->json('data');
    $row = DB::table('patients_patients')->where('id', $made['id'])->first();
    expect($row->id_number)->not->toContain('1990123456789')->and($row->allergies)->not->toContain('Penicillin');

    $this->asToken($this->reception)->getJson(($this->api)("patients/{$made['id']}"))->assertOk()
        ->assertJsonPath('data.id_number', '•••• 6789')->assertJsonPath('data.allergies', 'Penicillin');
    $this->asToken($this->manager)->getJson(($this->api)("patients/{$made['id']}"))->assertOk()->assertJsonPath('data.id_number', '1990123456789');
    // List rows never carry them.
    expect($this->asToken($this->reception)->getJson(($this->api)('patients'))->json('data.0'))->not->toHaveKeys(['id_number', 'allergies', 'address']);

    $this->asToken($this->manager)->patchJson(($this->api)("patients/{$made['id']}"), ['allergies' => 'Sulfa', 'base_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    $logged = AuditLog::query()->whereIn('action', ['patients.patient_registered', 'patients.patient_updated'])->get()->map(fn ($event) => json_encode([$event->old_values, $event->new_values]))->implode(' ');
    expect($logged)->not->toContain('Penicillin')->not->toContain('Sulfa')->not->toContain('1990123456789')->not->toContain('Rahim')->toContain('allergies');
});

it('changes a patient with its version and only with patients.manage', function () {
    $made = ($this->register)()->assertCreated()->json('data');
    $this->asToken($this->reception)->patchJson(($this->api)("patients/{$made['id']}"), ['name' => 'Rahim Mia', 'base_version' => 1])->assertForbidden();
    $this->asToken($this->manager)->patchJson(($this->api)("patients/{$made['id']}"), ['name' => 'Rahim Mia', 'age_years' => 41, 'base_version' => 1])->assertOk()
        ->assertJsonPath('data.name', 'Rahim Mia')->assertJsonPath('data.age', 41)->assertJsonPath('data.version', 2);
    $this->asToken($this->manager)->patchJson(($this->api)("patients/{$made['id']}"), ['name' => 'X Y', 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->manager)->patchJson(($this->api)("patients/{$made['id']}"), ['name' => 'Rahim Mia', 'base_version' => 2, 'op_id' => 'x'])->assertUnprocessable();
    $this->asToken($this->manager)->deleteJson(($this->api)("patients/{$made['id']}"))->assertStatus(405);
});

it('writes down every look at a record, history or card, readable only with patients.audit', function () {
    $made = ($this->register)()->assertCreated()->json('data');
    $this->asToken($this->reception)->getJson(($this->api)("patients/{$made['id']}"))->assertOk();
    $this->asToken($this->doctor)->getJson(($this->api)("patients/{$made['id']}/timeline"))->assertOk();
    $card = $this->asToken($this->reception)->getJson(($this->api)("patients/{$made['id']}/card"))->assertOk()->json('data');
    expect($card['qr'])->toStartWith('data:image/svg+xml;base64,')->and($card['mrn'])->toBe('P000001');

    $this->asToken($this->reception)->getJson(($this->api)("patients/{$made['id']}/views"))->assertForbidden();
    $views = $this->asToken($this->owner)->getJson(($this->api)("patients/{$made['id']}/views"))->assertOk()->json('data');
    expect(collect($views)->pluck('purpose')->all())->toEqualCanonicalizing(['record', 'history', 'card'])
        ->and(collect($views)->pluck('user_id'))->toContain($this->doctorUser->id);
});

it('lets doctors see only their own patients when the company says so', function () {
    $mine = ($this->register)()->assertCreated()->json('data');
    $other = ($this->register)(['name' => 'Karima Begum', 'sex' => 'female', 'mobile' => '01812345678'])->assertCreated()->json('data');
    expect($this->asToken($this->doctor)->getJson(($this->api)('patients'))->json('meta.total'))->toBe(2);

    trustedOrgRule($this->w->c1, 'patients.doctor_sees', 'own');
    $this->asToken($this->doctor)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.only_own', true);
    expect($this->asToken($this->doctor)->getJson(($this->api)('patients'))->json('meta.total'))->toBe(0);
    $this->asToken($this->doctor)->getJson(($this->api)("patients/{$mine['id']}"))->assertNotFound();

    // A visit (another module) links the doctor through the directory.
    app(PatientDirectory::class)->linkCarer($this->w->c1, $mine['id'], $this->doctorUser->id, 'outpatient');
    expect(collect($this->asToken($this->doctor)->getJson(($this->api)('patients'))->json('data'))->pluck('id')->all())->toBe([$mine['id']]);
    $this->asToken($this->doctor)->getJson(($this->api)("patients/{$mine['id']}"))->assertOk();
    $this->asToken($this->doctor)->getJson(($this->api)("patients/{$other['id']}/timeline"))->assertNotFound();
    // Reception (registers patients) still finds everyone.
    expect($this->asToken($this->reception)->getJson(($this->api)('patients'))->json('meta.total'))->toBe(2);
});

it('keeps patients to their branch when branches do not share', function () {
    $second = createChild($this->w->c1, OrganizationType::Branch, 'B1-2');
    $atB1 = orgToken(staffWithRoles($this->w->b1, makeRole($this->w->b1, ['patients.view', 'patients.register'], 'Desk B1')), $this->w->b1);
    $atSecond = orgToken(staffWithRoles($second, makeRole($second, ['patients.view', 'patients.register'], 'Desk B2')), $second);
    $made = $this->asToken($atB1)->postJson(($this->api)('patients', $this->w->b1), ['name' => 'Rahim Uddin', 'sex' => 'male', 'age_years' => 40])->assertCreated()->json('data');

    $this->asToken($atSecond)->getJson(($this->api)("patients/{$made['id']}", $second))->assertOk();
    orgRule($this->w->c1, 'patients.share_between_branches', false);
    $this->asToken($atSecond)->getJson(($this->api)("patients/{$made['id']}", $second))->assertNotFound();
    $this->asToken($atB1)->getJson(($this->api)("patients/{$made['id']}", $this->w->b1))->assertOk();
});

it('merges a duplicate only with a second person, moving its history; other modules follow the merge', function () {
    Event::fake([PatientsMerged::class]);
    $keep = ($this->register)()->assertCreated()->json('data');
    $dupe = ($this->register)(['name' => 'Rahim', 'confirm_new' => true])->assertCreated()->json('data');
    app(PatientDirectory::class)->addToTimeline($this->w->c1, $dupe['id'], 'diagnostics', 'report', 'R-1', ['en' => 'CBC report', 'bn' => 'CBC রিপোর্ট'], CarbonImmutable::now());

    $this->asToken($this->reception)->postJson(($this->api)("patients/{$dupe['id']}/merge"), ['into_id' => $keep['id'], 'reason' => 'Same man, new card'])->assertForbidden();
    $this->asToken($this->manager)->postJson(($this->api)("patients/{$dupe['id']}/merge"), ['into_id' => $dupe['id'], 'reason' => 'Same man, new card'])->assertUnprocessable()->assertJsonPath('code', 'merge_self');
    $merge = $this->asToken($this->manager)->postJson(($this->api)("patients/{$dupe['id']}/merge"), ['into_id' => $keep['id'], 'reason' => 'Same man, new card'])->assertCreated()->json('data');
    $this->asToken($this->manager)->postJson(($this->api)("patients/{$dupe['id']}/merge"), ['into_id' => $keep['id'], 'reason' => 'Asked again'])->assertStatus(409)->assertJsonPath('code', 'merge_waiting');
    $this->asToken($this->manager)->postJson(($this->api)("merges/{$merge['id']}/decide"), ['approve' => true])->assertForbidden()->assertJsonPath('code', 'own_request');
    $this->asToken($this->owner)->postJson(($this->api)("merges/{$merge['id']}/decide"), ['approve' => false])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $this->asToken($this->owner)->postJson(($this->api)("merges/{$merge['id']}/decide"), ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->asToken($this->owner)->postJson(($this->api)("merges/{$merge['id']}/decide"), ['approve' => true])->assertStatus(409)->assertJsonPath('code', 'not_pending');

    Event::assertDispatched(PatientsMerged::class, fn ($event) => $event->sourceId === $dupe['id'] && $event->targetId === $keep['id']);
    expect(collect($this->asToken($this->reception)->getJson(($this->api)('patients?q=rahim'))->json('data'))->pluck('id')->all())->toBe([$keep['id']]);
    expect(collect($this->asToken($this->reception)->getJson(($this->api)("patients/{$keep['id']}/timeline"))->json('data.entries'))->pluck('source_id'))->toContain('R-1');
    $this->asToken($this->owner)->patchJson(($this->api)("patients/{$dupe['id']}"), ['name' => 'X Y', 'base_version' => 2])->assertStatus(409)->assertJsonPath('merged_into_id', $keep['id']);
    // The directory answers for the old id with the record that stayed.
    expect(app(PatientDirectory::class)->find($this->w->c1, $dupe['id'])?->id)->toBe($keep['id'])
        ->and(app(PatientDirectory::class)->label($this->w->c1, $dupe['id']))->toBe('P000001 · Rahim Uddin');
});

it('shows portal patients only their own record and history, and nothing else', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $mine = ($this->register)()->assertCreated()->json('data');
    $other = ($this->register)(['name' => 'Karima Begum', 'sex' => 'female', 'mobile' => '01812345678'])->assertCreated()->json('data');
    app(PatientDirectory::class)->addToTimeline($this->w->c1, $other['id'], 'diagnostics', 'report', 'R-9', ['en' => 'X-ray', 'bn' => 'এক্স-রে'], CarbonImmutable::now());

    $person = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $person, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $person->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $person->id,
        'subject_type' => 'patients.patient', 'subject_id' => $mine['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($person, $this->w->c1);
    $this->withHeader('Origin', config('app.url'));

    $seen = $this->asToken($portal)->getJson('/api/portal/patients')->assertOk()->json('data');
    expect($seen['enabled'])->toBeTrue()
        ->and(collect($seen['patients'])->pluck('id')->all())->toBe([$mine['id']])
        ->and($seen['patients'][0])->not->toHaveKeys(['id_number', 'allergies', 'address', 'mobile'])
        ->and(collect($seen['patients'][0]['history'])->pluck('source_id'))->not->toContain('R-9');
    $this->asToken($portal)->getJson(($this->api)("patients/{$other['id']}"))->assertForbidden();
    $this->asToken($portal)->getJson(($this->api)('patients'))->assertForbidden();

    orgRule($this->w->c1, 'patients.portal_enabled', false);
    $this->asToken($portal)->getJson('/api/portal/patients')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.patients', []);
});

it('takes the patient number prefix from the level that sets it', function () {
    orgRule($this->w->g1, 'patients.mrn_prefix', 'HG');
    ($this->register)()->assertCreated()->assertJsonPath('data.mrn', 'HG000001');
    orgRule($this->w->c1, 'patients.mrn_prefix', 'DH');
    ($this->register)(['name' => 'Karima Begum', 'sex' => 'female', 'mobile' => '01812345678'])->assertCreated()->assertJsonPath('data.mrn', 'DH000002');
    expect(ruleFor('patients.mrn_prefix', $this->w->c2))->toBe('HG');
});

it('keeps each permission to its own work and answers 403 with the module off', function () {
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['patients.view'], 'Viewer')), $this->w->c1);
    ($this->register)([], $viewer)->assertForbidden();
    $this->asToken($viewer)->getJson(($this->api)('merges'))->assertForbidden();
    $this->asToken($viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.register', false)->assertJsonPath('data.can.manage', false);
    $this->asToken($this->reception)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.register', true);
    $nobody = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Other')), $this->w->c1);
    $this->asToken($nobody)->getJson(($this->api)('patients'))->assertForbidden();

    toggles()->disable($this->w->c1, 'patients', 'Test');
    $this->asToken($this->owner)->getJson(($this->api)('patients'))->assertForbidden();
    ($this->register)([], $this->owner)->assertForbidden();
});

it('never shows another company\'s or another partner\'s patients', function () {
    $made = ($this->register)()->assertCreated()->json('data');
    $sister = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($sister)->getJson(($this->api)("patients/{$made['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($sister)->getJson(($this->api)("patients/{$made['id']}"))->assertNotFound();
    expect($this->asToken($sister)->getJson(($this->api)('patients?q=rahim', $this->w->c2))->json('meta.total'))->toBe(0);

    setPlan($this->w->g3, 'business');
    toggles()->enable($this->w->g3, 'patients', 'Test setup');
    $elsewhere = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($elsewhere)->getJson(($this->api)("patients/{$made['id']}", $this->w->c4))->assertNotFound();
    $this->asToken($elsewhere)->postJson(($this->api)("patients/{$made['id']}/merge", $this->w->c4), ['into_id' => $made['id'], 'reason' => 'Trying it'])->assertNotFound();
    // The same mobile at another company is another patient (numbers count per company).
    $this->asToken($elsewhere)->postJson(($this->api)('patients', $this->w->c4), ['name' => 'Rahim Uddin', 'sex' => 'male', 'age_years' => 40, 'mobile' => '+8801712345678'])
        ->assertCreated()->assertJsonPath('data.mrn', 'P000001');
    expect(app(PatientDirectory::class)->find($this->w->c4, $made['id']))->toBeNull();
});
