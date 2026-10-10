<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Portal\Jobs\SendPortalInvitation;
use App\Platform\Portal\Models\PortalLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Modules\CourseRegistration\Events\CourseDropped;
use Modules\CourseRegistration\Events\CourseRegistered;
use Modules\CourseRegistration\Events\RegistrationApproved;
use Modules\CourseRegistration\Events\SeatOffered;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;

/*
 * EDU-4a: course registration. Subjects offered from a level's curriculum,
 * the registration window, students registered by staff and by themselves
 * in the portal: credits, prerequisites, seats and waiting lists, add/drop
 * then withdraw, hand in and approve (never one's own), a whole section at
 * once, outcomes; teachers see what they teach; isolation and module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 04:00:00', 'UTC'));
    // Setting up a university takes more sensitive writes than one person may send a minute.
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'course_registration', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->edu = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/course-registration/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    // A university: BSc CSE by semesters, 2026 spring and autumn, section A of semester 1 at branch B1.
    ($this->as)()->postJson(($this->edu)('presets/university/apply'))->assertOk();
    $setup = ($this->as)()->getJson(($this->edu)('setup'))->json('data');
    $this->program = collect($setup['programs'])->firstWhere('code', 'BSCSE');
    $levels = collect($setup['levels'])->where('program_id', $this->program['id'])->sortBy('sequence')->values();
    $this->s1 = $levels[0];
    $year = ($this->as)()->postJson(($this->edu)('structure/years'), ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open'])->json('data');
    $this->spring = ($this->as)()->postJson(($this->edu)('structure/sessions'), ['academic_year_id' => $year['id'], 'kind' => 'semester', 'sequence' => 1, 'name' => ['en' => 'Spring 2026'], 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'open'])->assertCreated()->json('data');
    $this->section = ($this->as)()->postJson(($this->edu)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->spring['id'], 'level_id' => $this->s1['id'], 'name' => 'A', 'capacity' => 40])->assertCreated()->json('data');
    $this->subject = collect(($this->as)()->getJson(($this->edu)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->edu)('structure/curricula'), ['program_id' => $this->program['id'], 'name' => '2026 curriculum', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['CSE101' => 'compulsory', 'CSE102' => 'compulsory', 'MAT101' => 'elective', 'ENG101' => 'elective'] as $code => $kind) {
        ($this->as)()->postJson(($this->edu)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->s1['id'], 'subject_id' => $this->subject[$code]['id'], 'kind' => $kind])->assertCreated();
    }
    ($this->as)()->postJson(($this->edu)('structure/prerequisites'), ['subject_id' => $this->subject['CSE201']['id'], 'requires_subject_id' => $this->subject['CSE101']['id']])->assertCreated();

    $this->student = fn (string $name) => ($this->as)()->postJson(($this->edu)('students'), [
        'name' => $name, 'program_id' => $this->program['id'], 'unit_id' => $this->w->b1->id,
        'enrollment' => ['session_id' => $this->spring['id'], 'level_id' => $this->s1['id'], 'section_id' => $this->section['id']],
    ])->assertCreated()->json('data');
    $this->offer = fn (array $extra = []) => ($this->as)()->postJson(($this->api)('offerings/from-curriculum'), [
        'unit_id' => $this->w->b1->id, 'session_id' => $this->spring['id'], 'level_id' => $this->s1['id'], 'group_name' => 'A', 'capacity' => 30, ...$extra,
    ])->assertCreated()->json('data');
    $this->open = fn (array $student, ?string $token = null) => ($this->as)($token)->postJson(($this->api)('registrations'), ['student_id' => $student['id'], 'session_id' => $this->spring['id']]);
    $this->add = fn (array $registration, array $offering, ?string $token = null) => ($this->as)($token)->postJson(($this->api)("registrations/{$registration['id']}/items"), ['offering_id' => $offering['id']]);
    $this->byCode = fn (array $offerings, string $code) => collect($offerings)->first(fn ($offering) => $offering['subject']['code'] === $code);
});

// ── Offerings and the window ──

it('offers a level\'s curriculum at once, with credits from the subjects, never twice', function () {
    $made = ($this->offer)();
    expect(collect($made)->pluck('subject.code')->sort()->values()->all())->toBe(['CSE101', 'CSE102', 'ENG101', 'MAT101'])
        ->and(($this->byCode)($made, 'CSE101'))->toMatchArray(['kind' => 'compulsory', 'credits_centi' => 300, 'capacity' => 30, 'taken' => 0])
        ->and(($this->byCode)($made, 'CSE102')['credits_centi'])->toBe(150);
    expect(($this->offer)())->toBe([]);

    // A second group of a subject by hand; the same name twice is refused.
    $b = ['session_id' => $this->spring['id'], 'subject_id' => $this->subject['CSE101']['id'], 'group_name' => 'B', 'capacity' => 20, 'unit_id' => $this->w->b1->id];
    ($this->as)()->postJson(($this->api)('offerings'), $b)->assertCreated();
    ($this->as)()->postJson(($this->api)('offerings'), $b)->assertUnprocessable()->assertJsonValidationErrors(['group_name']);
    ($this->as)()->postJson(($this->api)('offerings'), [...$b, 'group_name' => 'C', 'subject_id' => str_repeat('A', 26)])->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
    expect(($this->as)()->getJson(($this->api)("offerings?session_id={$this->spring['id']}"))->json('data'))->toHaveCount(5);
});

it('keeps the window in order and inside the session', function () {
    ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-10', 'closes_on' => '2026-01-05', 'add_drop_until' => '2026-01-20'])
        ->assertUnprocessable()->assertJsonValidationErrors(['closes_on']);
    ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-05', 'closes_on' => '2026-01-20', 'add_drop_until' => '2026-07-20'])
        ->assertUnprocessable()->assertJsonValidationErrors(['add_drop_until']);
    $window = ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-05', 'closes_on' => '2026-01-20', 'add_drop_until' => '2026-01-31'])->assertOk()->json('data');
    expect($window)->toMatchArray(['opens_on' => '2026-01-05', 'version' => 1]);
    ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-06', 'closes_on' => '2026-01-20', 'add_drop_until' => '2026-01-31', 'base_version' => 9])->assertStatus(409);
    ($this->as)()->getJson(($this->api)("setup?session_id={$this->spring['id']}"))->assertOk()->assertJsonPath('data.window.closes_on', '2026-01-20')->assertJsonPath('data.can.manage', true);
});

// ── Registering ──

it('registers subjects within the credits, then hands in for approval by someone else', function () {
    Event::fake([CourseRegistered::class, RegistrationApproved::class]);
    orgRule($this->w->c1, 'course_registration.max_credits', 6);
    orgRule($this->w->c1, 'course_registration.overload_credits', 0);
    orgRule($this->w->c1, 'course_registration.min_credits', 4);
    $offered = ($this->offer)();
    $rahim = ($this->student)('Rahim');

    $registration = ($this->open)($rahim)->assertCreated()->json('data');
    expect($registration)->toMatchArray(['status' => 'draft', 'credits_centi' => 0]);
    ($this->open)($rahim)->assertOk()->assertJsonPath('data.id', $registration['id']);

    ($this->add)($registration, ($this->byCode)($offered, 'CSE101'))->assertCreated()->assertJsonPath('data.credits_centi', 300);
    ($this->add)($registration, ($this->byCode)($offered, 'CSE101'))->assertStatus(409)->assertJsonPath('code', 'already_registered');
    ($this->as)()->postJson(($this->api)("registrations/{$registration['id']}/submit"))->assertUnprocessable()->assertJsonPath('code', 'credits_under');
    ($this->add)($registration, ($this->byCode)($offered, 'MAT101'))->assertCreated()->assertJsonPath('data.credits_centi', 600);
    ($this->add)($registration, ($this->byCode)($offered, 'ENG101'))->assertUnprocessable()->assertJsonPath('code', 'credits_over');
    Event::assertDispatchedTimes(CourseRegistered::class, 2);

    $submitted = ($this->as)()->postJson(($this->api)("registrations/{$registration['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
    // Whoever handed it in does not approve it.
    ($this->as)()->postJson(($this->api)("registrations/{$registration['id']}/approve"), ['base_version' => $submitted['version']])->assertForbidden()->assertJsonPath('code', 'own_approval');
    $advisor = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['course_registration.view', 'course_registration.approve'], 'Advisor'));
    $advisorToken = orgToken($advisor, $this->w->c1);
    $returned = $this->asToken($advisorToken)->postJson(($this->api)("registrations/{$registration['id']}/send-back"), ['base_version' => $submitted['version'], 'note' => 'Take ENG101 next time'])
        ->assertOk()->assertJsonPath('data.status', 'returned')->json('data');
    $again = ($this->as)()->postJson(($this->api)("registrations/{$registration['id']}/submit"))->assertOk()->json('data');
    $approved = $this->asToken($advisorToken)->postJson(($this->api)("registrations/{$registration['id']}/approve"), ['base_version' => $again['version']])->assertOk()->assertJsonPath('data.status', 'approved')->json('data');
    Event::assertDispatched(RegistrationApproved::class);
    expect($approved['approved_by'])->toBe($advisor->id)->and($returned['note'])->toBe('Take ENG101 next time');

    // A change after approval asks for it again.
    $dropped = collect($approved['items'])->firstWhere('subject.code', 'MAT101');
    ($this->as)()->postJson(($this->api)("items/{$dropped['id']}/drop"))->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.credits_centi', 300);
    expect(AuditLog::where('action', 'course_registration.registration_approved')->count())->toBe(1);
});

it('approves on handing in when no approval is needed, but never an overload', function () {
    orgRule($this->w->c1, 'course_registration.approval_required', false);
    orgRule($this->w->c1, 'course_registration.max_credits', 3);
    orgRule($this->w->c1, 'course_registration.overload_credits', 3);
    $offered = ($this->offer)();
    $one = ($this->open)(($this->student)('One'))->json('data');
    ($this->add)($one, ($this->byCode)($offered, 'CSE101'))->assertCreated();
    ($this->as)()->postJson(($this->api)("registrations/{$one['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'approved');

    $two = ($this->open)(($this->student)('Two'))->json('data');
    ($this->add)($two, ($this->byCode)($offered, 'CSE101'))->assertCreated();
    ($this->add)($two, ($this->byCode)($offered, 'MAT101'))->assertCreated()->assertJsonPath('data.overload', true);
    ($this->as)()->postJson(($this->api)("registrations/{$two['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'submitted');
});

it('puts students on a waiting list when a group is full and gives a freed seat to the first who fits', function () {
    Event::fake([SeatOffered::class, CourseDropped::class, CourseRegistered::class]);
    $offered = ($this->offer)(['capacity' => 1]);
    $cse = ($this->byCode)($offered, 'CSE101');
    $first = ($this->open)(($this->student)('First'))->json('data');
    $second = ($this->open)(($this->student)('Second'))->json('data');
    $third = ($this->open)(($this->student)('Third'))->json('data');

    ($this->add)($first, $cse)->assertCreated();
    expect(collect(($this->add)($second, $cse)->json('data.items'))->firstWhere('subject.code', 'CSE101')['status'])->toBe('waitlisted');
    ($this->add)($third, $cse)->assertCreated();
    $roster = ($this->as)()->getJson(($this->api)("offerings/{$cse['id']}/students"))->assertOk()->json('data');
    expect($roster['offering'])->toMatchArray(['taken' => 1, 'waiting' => 2])
        ->and(collect($roster['students'])->pluck('student.name')->all())->toBe(['First', 'Second', 'Third']);

    $item = RegistrationItem::query()->where('registration_id', $first['id'])->sole();
    ($this->as)()->postJson(($this->api)("items/{$item->id}/drop"))->assertOk();
    expect(RegistrationItem::query()->where('registration_id', $second['id'])->sole()->status)->toBe('registered')
        ->and(RegistrationItem::query()->where('registration_id', $third['id'])->sole()->status)->toBe('waitlisted');
    Event::assertDispatched(SeatOffered::class, fn ($event) => $event->studentId === $second['student_id']);
    Event::assertDispatched(CourseDropped::class, fn ($event) => $event->status === 'dropped');

    // Without waiting lists a full group is refused.
    orgRule($this->w->c1, 'course_registration.waitlist', false);
    $fourth = ($this->open)(($this->student)('Fourth'))->json('data');
    ($this->add)($fourth, $cse)->assertStatus(409)->assertJsonPath('code', 'offering_full');
});

it('asks for prerequisites first, counting completed outcomes', function () {
    ($this->offer)();
    $cse201 = ($this->as)()->postJson(($this->api)('offerings'), ['session_id' => $this->spring['id'], 'subject_id' => $this->subject['CSE201']['id'], 'group_name' => 'A', 'capacity' => 10, 'unit_id' => $this->w->b1->id])->json('data');
    $cse101 = ($this->byCode)(($this->as)()->getJson(($this->api)("offerings?session_id={$this->spring['id']}"))->json('data'), 'CSE101');
    $registration = ($this->open)(($this->student)('Rahim'))->json('data');

    ($this->add)($registration, $cse201)->assertUnprocessable()->assertJsonPath('code', 'prerequisites_missing')->assertJsonPath('missing', ['CSE101']);
    $item = ($this->add)($registration, $cse101)->json('data.items.0');
    ($this->as)()->postJson(($this->api)("items/{$item['id']}/outcome"), ['outcome' => 'failed'])->assertOk();
    ($this->add)($registration, $cse201)->assertUnprocessable();
    ($this->as)()->postJson(($this->api)("items/{$item['id']}/outcome"), ['outcome' => 'completed'])->assertOk()->assertJsonPath('data.outcome', 'completed');
    ($this->add)($registration, $cse201)->assertCreated();

    orgRule($this->w->c1, 'course_registration.prerequisites_enforced', false);
    $other = ($this->open)(($this->student)('Karim'))->json('data');
    ($this->add)($other, $cse201)->assertCreated();
});

it('drops until the add/drop date, then withdraws with a reason', function () {
    $offered = ($this->offer)();
    ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-01', 'closes_on' => '2026-01-09', 'add_drop_until' => '2026-01-09'])->assertOk();
    $registration = ($this->open)(($this->student)('Rahim'))->json('data');
    $item = ($this->add)($registration, ($this->byCode)($offered, 'CSE101'))->json('data.items.0');

    ($this->as)()->postJson(($this->api)("items/{$item['id']}/drop"))->assertUnprocessable()->assertJsonPath('code', 'reason_needed');
    $after = ($this->as)()->postJson(($this->api)("items/{$item['id']}/drop"), ['reason' => 'Health reasons'])->assertOk()->json('data');
    expect($after['items'][0])->toMatchArray(['status' => 'withdrawn', 'outcome' => 'withdrawn', 'reason' => 'Health reasons'])
        ->and($after['credits_centi'])->toBe(0);
    ($this->as)()->postJson(($this->api)("items/{$item['id']}/drop"), ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'wrong_status');
});

it('registers a whole section for its compulsory subjects and hands each in', function () {
    orgRule($this->w->c1, 'course_registration.approval_required', false);
    ($this->offer)();
    foreach (['Rahim', 'Karim', 'Nusrat'] as $name) {
        ($this->student)($name);
    }
    $result = ($this->as)()->postJson(($this->api)("sections/{$this->section['id']}/register"))->assertOk()->json('data');
    expect($result)->toMatchArray(['students' => 3, 'added' => 6, 'waitlisted' => 0, 'problems' => []]);
    expect(Registration::query()->where('status', 'approved')->count())->toBe(3)
        ->and(RegistrationItem::query()->where('source', 'section')->pluck('subject_id')->unique()->count())->toBe(2);

    // Again: nothing twice.
    expect(($this->as)()->postJson(($this->api)("sections/{$this->section['id']}/register"))->json('data.added'))->toBe(0);
});

// ── The student in the portal ──

it('lets a student register themselves in the portal, only in the window and when allowed', function () {
    Bus::fake([SendPortalInvitation::class]);
    RateLimiter::clear('portal-join:127.0.0.1');
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $offered = ($this->offer)();
    $rahim = ($this->student)('Rahim');
    $karim = ($this->student)('Karim');
    // Rahim joins the portal as himself; a parent joins for Karim.
    $join = function (array $student, string $relation, string $email) {
        $invitation = $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/portal/invitations", [
            'subject_type' => 'education.student', 'subject_id' => $student['id'], 'relation' => $relation, 'name' => $student['name'], 'channel' => 'mail', 'email' => $email,
        ])->assertCreated()->json('data');
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $this->withHeader('Origin', config('app.url'))->actingAs($user, 'web')->postJson('/session/portal/join', ['key' => $invitation['code']])->assertOk();
        $link = PortalLink::query()->where('subject_id', $student['id'])->sole();
        $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/portal/links/{$link->id}/approve")->assertOk();

        return orgToken($user, $this->w->c1);
    };
    $rahimToken = $join($rahim, 'self', 'rahim@example.com');
    $parentToken = $join($karim, 'guardian', 'parent@example.com');
    $cse = ($this->byCode)($offered, 'CSE101');

    // Not allowed yet, then no window, then a window that has not opened.
    $this->asToken($rahimToken)->postJson('/api/portal/course-registration/items', ['offering_id' => $cse['id']])->assertForbidden()->assertJsonPath('code', 'self_registration_off');
    orgRule($this->w->c1, 'course_registration.self_registration', true);
    $this->asToken($rahimToken)->postJson('/api/portal/course-registration/items', ['offering_id' => $cse['id']])->assertStatus(409)->assertJsonPath('code', 'no_window');
    ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-12', 'closes_on' => '2026-01-20', 'add_drop_until' => '2026-01-31'])->assertOk();
    $this->asToken($rahimToken)->postJson('/api/portal/course-registration/items', ['offering_id' => $cse['id']])->assertStatus(409)->assertJsonPath('code', 'window_closed');

    ($this->as)()->putJson(($this->api)("sessions/{$this->spring['id']}/window"), ['opens_on' => '2026-01-05', 'closes_on' => '2026-01-20', 'add_drop_until' => '2026-01-31', 'base_version' => 1])->assertOk();
    $view = $this->asToken($rahimToken)->getJson('/api/portal/course-registration')->assertOk()->json('data');
    expect($view['student']['name'])->toBe('Rahim')->and($view['registration'])->toBeNull()->and($view['offerings'])->toHaveCount(4);
    $after = $this->asToken($rahimToken)->postJson('/api/portal/course-registration/items', ['offering_id' => $cse['id'], 'op_id' => 'tap-1'])->assertOk()->json('data');
    $this->asToken($rahimToken)->postJson('/api/portal/course-registration/items', ['offering_id' => $cse['id'], 'op_id' => 'tap-1'])->assertOk();
    expect($after['registration']['items'])->toHaveCount(1)->and($after['registration']['items'][0]['source'])->toBe('student');
    $this->asToken($rahimToken)->postJson('/api/portal/course-registration/submit')->assertOk()->assertJsonPath('data.registration.status', 'submitted');

    // A guardian does not register for the student; nobody touches another student's subjects.
    $this->asToken($parentToken)->getJson('/api/portal/course-registration')->assertNotFound();
    $karimRegistration = ($this->open)($karim)->json('data');
    $karimItem = ($this->add)($karimRegistration, $cse)->json('data.items.0');
    $this->asToken($rahimToken)->postJson("/api/portal/course-registration/items/{$karimItem['id']}/drop")->assertNotFound();
    // Staff screens stay closed to portal members.
    $this->asToken($rahimToken)->getJson(($this->api)("registrations?session_id={$this->spring['id']}"))->assertForbidden();
});

// ── Who may do what ──

it('shows a teacher only the subjects they teach', function () {
    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    $offered = ($this->offer)();
    $teacher = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['course_registration.view', 'education.view'], 'Teacher'));
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Teacher One'])->assertCreated()->json('data.id');
    DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $teacher->id]);
    $mine = ($this->byCode)($offered, 'CSE101');
    ($this->as)()->patchJson(($this->api)("offerings/{$mine['id']}"), ['base_version' => $mine['version'], 'teacher_id' => $employee])->assertOk();
    ($this->as)()->patchJson(($this->api)('offerings/'.($this->byCode)($offered, 'MAT101')['id']), ['base_version' => 1, 'teacher_id' => str_repeat('A', 26)])->assertUnprocessable();

    $token = orgToken($teacher, $this->w->c1);
    $list = $this->asToken($token)->getJson(($this->api)("offerings?session_id={$this->spring['id']}"))->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->toBe([$mine['id']]);
    $this->asToken($token)->getJson(($this->api)("offerings/{$mine['id']}/students"))->assertOk();
    $this->asToken($token)->getJson(($this->api)('offerings/'.($this->byCode)($offered, 'MAT101')['id'].'/students'))->assertNotFound();
    $this->asToken($token)->postJson(($this->api)('offerings/from-curriculum'), ['session_id' => $this->spring['id'], 'level_id' => $this->s1['id'], 'group_name' => 'B', 'capacity' => 5])->assertForbidden();
});

it('keeps institutions, partners and campuses apart, and answers 403 while the module is off', function () {
    $offered = ($this->offer)();
    $registration = ($this->open)(($this->student)('Rahim'))->json('data');

    $c2 = createMember($this->w->c2);
    $this->asToken(orgToken($c2, $this->w->c2))->getJson(($this->api)("registrations/{$registration['id']}", $this->w->c2))->assertNotFound();
    $this->asToken(orgToken($c2, $this->w->c2))->postJson(($this->api)("registrations/{$registration['id']}/items", $this->w->c2), ['offering_id' => $offered[0]['id']])->assertNotFound();
    $c4 = createMember($this->w->c4);
    $this->asToken(orgToken($c4, $this->w->c4))->getJson(($this->api)("offerings?session_id={$this->spring['id']}", $this->w->c1))->assertNotFound();
    $d1 = createMember($this->w->d1);
    $this->asToken(orgToken($d1, $this->w->d1))->getJson(($this->api)("registrations/{$registration['id']}", $this->w->d1))->assertNotFound();

    $looker = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Looker'));
    $this->asToken(orgToken($looker, $this->w->c1))->getJson(($this->api)("registrations?session_id={$this->spring['id']}"))->assertForbidden();

    toggles()->disable($this->w->g1, 'course_registration', 'Test', confirm: true);
    ($this->as)()->getJson(($this->api)("registrations/{$registration['id']}"))->assertForbidden();
    expect(Registration::count())->toBe(1);
});

// ── What the screens read ──

it('gives the screens sessions, classes, campuses, students and sections to choose from', function () {
    $rahim = ($this->student)('Rahim Uddin');
    ($this->student)('Karim Ahmed');
    $setup = ($this->as)()->getJson(($this->api)("setup?session_id={$this->spring['id']}"))->assertOk()->json('data');
    expect(collect($setup['sessions'])->pluck('id'))->toContain($this->spring['id'])
        ->and(collect($setup['levels'])->firstWhere('id', $this->s1['id'])['program']['code'])->toBe('BSCSE')
        ->and(collect($setup['campuses'])->pluck('id'))->toContain($this->w->b1->id)
        ->and($setup['teachers'])->toBe([])
        ->and($setup['rules'])->toMatchArray(['approval_required' => true, 'waitlist' => true]);

    expect(collect(($this->as)()->getJson(($this->api)('students?q=rahim'))->assertOk()->json('data'))->pluck('id')->all())->toBe([$rahim['id']]);
    ($this->as)()->getJson(($this->api)('students?q=r'))->assertUnprocessable();
    expect(($this->as)()->getJson(($this->api)("sections?session_id={$this->spring['id']}"))->assertOk()->json('data.0'))->toMatchArray(['id' => $this->section['id'], 'students' => 2]);

    ($this->open)($rahim)->assertCreated();
    ($this->open)(($this->student)('Nusrat'))->assertCreated();
    ($this->as)()->getJson(($this->api)("registrations?session_id={$this->spring['id']}&q=rahim"))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student.name', 'Rahim Uddin');

    // Finding students and sections is for people who register.
    $teacher = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['course_registration.view'], 'Viewer'));
    $this->asToken(orgToken($teacher, $this->w->c1))->getJson(($this->api)('students?q=rahim'))->assertForbidden();
    $this->asToken(orgToken($teacher, $this->w->c1))->getJson(($this->api)("sections?session_id={$this->spring['id']}"))->assertForbidden();
});
