<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Education\Models\Admission;

/*
 * EDU-2b: what the admission, promotion and import screens read beyond
 * EDU-1: applications found by the applicant's name or a phone (Bangla
 * digits too) with counts per status, the people named on a promotion list,
 * and the rules and permissions the screens explain.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->apply = fn (object $school, string $name, string $phone, ?string $token = null) => ($this->as)($token)->postJson(($this->api)('admissions'), [
        'unit_id' => $this->w->b1->id, 'program_id' => $school->program['id'], 'level_id' => $school->class6['id'], 'session_id' => $school->session['id'],
        'applicant' => ['name' => $name, 'name_local' => str_starts_with($name, 'Nusrat') ? 'নুসরাত' : null, 'guardians' => [['name' => 'Rafiq', 'phone' => $phone, 'relation' => 'father']]],
    ]);
});

it('finds applications by number, name or a guardian phone, and counts them by status', function () {
    $school = eduSchool($this);
    $nusrat = ($this->apply)($school, 'Nusrat Jahan', '01911-000000')->assertCreated()->json('data');
    $karim = ($this->apply)($school, 'Karim Uddin', '01822-333444')->assertCreated()->json('data');
    ($this->as)()->postJson(($this->api)("admissions/{$karim['id']}/step"), ['base_version' => 1, 'status' => 'rejected', 'note' => 'No seat'])->assertOk();

    $find = fn (string $q) => collect(($this->as)()->getJson(($this->api)('admissions?q='.urlencode($q)))->assertOk()->json('data'))->pluck('id')->all();
    expect($find('nusrat'))->toBe([$nusrat['id']])
        ->and($find('নুসরাত'))->toBe([$nusrat['id']])
        ->and($find('01911'))->toBe([$nusrat['id']])
        ->and($find('০১৯১১'))->toBe([$nusrat['id']])
        ->and($find('ADM-2026-0002'))->toBe([$karim['id']])
        ->and($find('nobody'))->toBe([]);

    $meta = ($this->as)()->getJson(($this->api)('admissions?status=applied'))->assertOk()->json('meta');
    expect($meta['total'])->toBe(1)->and((array) $meta['counts'])->toBe(['applied' => 1, 'rejected' => 1]);

    // A change of the applicant is found by the new name.
    ($this->as)()->patchJson(($this->api)("admissions/{$nusrat['id']}"), ['base_version' => 1, 'applicant' => ['name' => 'Nusrat Ara']])->assertOk();
    expect($find('nusrat ara'))->toBe([$nusrat['id']]);
});

it('fills the search of applications made before it existed', function () {
    $school = eduSchool($this);
    $made = ($this->apply)($school, 'Old Applicant', '01711-222333')->assertCreated()->json('data');
    DB::table('edu_admissions')->where('id', $made['id'])->update(['search_text' => null]);

    $migration = require base_path('Modules/Education/database/migrations/tenant/2026_11_12_100000_add_search_to_education_admissions.php');
    $migration->down();
    $migration->up();
    expect(Admission::find($made['id'])->search_text)->toBe('old applicant 01711222333');
});

it('keeps applications to people who admit, in their own institution', function () {
    $school = eduSchool($this);
    ($this->apply)($school, 'Nusrat Jahan', '01911-000000')->assertCreated();

    $looker = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Looker'));
    $this->asToken(orgToken($looker, $this->w->c1))->getJson(($this->api)('admissions?q=nusrat'))->assertForbidden();
    $c2 = createMember($this->w->c2);
    $this->asToken(orgToken($c2, $this->w->c2))->getJson(($this->api)('admissions?q=nusrat', $this->w->c2))->assertOk()->assertJsonPath('meta.total', 0);
    $c4 = createMember($this->w->c4);
    $this->asToken(orgToken($c4, $this->w->c4))->getJson(($this->api)('admissions', $this->w->c1))->assertNotFound();

    toggles()->disable($this->w->g1, 'education', 'Test', confirm: true);
    ($this->as)()->getJson(($this->api)('admissions?q=nusrat'))->assertForbidden();
});

it('names the people on a promotion list and tells the screens the rules', function () {
    $school = eduSchool($this);
    newStudent($this, $school)->assertCreated();
    trustedOrgRule($this->w->c1, 'education.promotion_approval', true);
    $year = ($this->as)()->postJson(($this->api)('structure/years'), ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->json('data');
    $next = ($this->as)()->postJson(($this->api)('structure/sessions'), ['academic_year_id' => $year['id'], 'kind' => 'year', 'name' => ['en' => '2027'], 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->json('data');

    $batch = ($this->as)()->postJson(($this->api)('promotions'), ['from_session_id' => $school->session['id'], 'to_session_id' => $next['id'], 'level_id' => $school->class6['id']])->assertCreated()->json('data');
    expect((array) $batch['people'])->toBe([$this->owner->id => $this->owner->name]);
    $list = ($this->as)()->getJson(($this->api)('promotions'))->assertOk()->json('data.0');
    expect((array) $list['people'])->toBe([$this->owner->id => $this->owner->name])->and((array) $list['decisions'])->toBe(['promote' => 1]);

    $setup = ($this->as)()->getJson(($this->api)('setup'))->assertOk()->json('data');
    expect($setup['rules'])->toBe(['promotion_approval' => true, 'promotion_undo_days' => $setup['rules']['promotion_undo_days'], 'max_repeats' => $setup['rules']['max_repeats']])
        ->and($setup['can'])->toMatchArray(['promote' => true, 'approve_promotion' => true]);

    $head = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.approve_promotion'], 'Head'));
    $headSetup = $this->asToken(orgToken($head, $this->w->c1))->getJson(($this->api)('setup'))->assertOk()->json('data');
    expect($headSetup['can'])->toMatchArray(['promote' => false, 'approve_promotion' => true, 'admit' => false]);
});
