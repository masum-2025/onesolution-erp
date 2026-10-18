<?php

use App\Models\User;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Portal\Models\PortalLink;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\StudentAttendance\Events\SheetSaved;
use Modules\StudentAttendance\Listeners\TellAbsences;
use Modules\StudentAttendance\Services\AbsenceNotices;

/*
 * STA-3: families and the home. A family is told of an absence today
 * (rule, off by default) a few minutes after the sheet, once a day, never
 * after it was put right and never for an earlier day; by period, the
 * first period decides, and a missed later class is told too (rule). The
 * portal shows a student (or a parent's child) their own month only. The
 * home shows sections still to take and students with low attendance; the
 * bell says so; the setup checklist asks for periods and holidays.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    platformRule('notifications.sms_enabled', true);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        setPlan($group, 'business');
        foreach (['education', 'hrm', 'client_portal', 'student_attendance'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->sta = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/student-attendance/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['BAN1', 'MATH'] as $code) {
        ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'kind' => 'compulsory'])->assertCreated();
    }
    // Rahim's family has no phone on record; Karim's father has one.
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated()->json('data');
    $this->karim = newStudent($this, $this->school, ['name' => 'Karim'])->assertCreated()->json('data');
    $this->section = $this->school->section['id'];
    $this->sheet = fn (string $date, array $marks, array $extra = [], ?string $token = null) => ($this->as)($token)->putJson(($this->sta)("sheets/{$this->section}/{$date}"), ['marks' => $marks, ...$extra]);
    $this->mark = fn (array $student, string $status) => ['student_id' => $student['id'], 'status' => $status];
    $this->sms = fn (string $kind) => NotificationDelivery::query()->where('notification_key', "student_attendance.{$kind}")->where('channel', 'sms')->count();
    $this->tell = fn (string $sheetId) => app(AbsenceNotices::class)->forSheet($this->w->c1->fresh(), $sheetId);
});

it('tells a family of an absence today, after the rule\'s minutes, once, and never once it was put right', function () {
    Queue::fake();
    // Off by default: nothing goes.
    $sheet = ($this->sheet)('2026-03-10', [($this->mark)($this->karim, 'absent')])->assertCreated()->json('data.sheet');
    expect(($this->tell)($sheet['id']))->toBe(0);

    orgRule($this->w->c1, 'student_attendance.notify_absent', ['enabled' => true, 'channels' => ['sms'], 'delay_minutes' => 20]);
    $sheet = ($this->sheet)('2026-03-10', [($this->mark)($this->karim, 'present')], ['base_version' => 1])->assertOk()->json('data.sheet');
    // Queued, to go the rule's minutes later.
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === TellAbsences::class);
    expect(app(TellAbsences::class)->withDelay(new SheetSaved($this->w->c1->id, $sheet['id'])))->toBe(20 * 60);
    // Put right before the message went: nothing goes.
    expect(($this->tell)($sheet['id']))->toBe(0)->and(($this->sms)('absent'))->toBe(0);

    ($this->sheet)('2026-03-10', [($this->mark)($this->karim, 'absent'), ($this->mark)($this->rahim, 'absent')], ['base_version' => 2])->assertOk();
    // Karim's father by SMS; Rahim's family has no contact.
    expect(($this->tell)($sheet['id']))->toBe(1)->and(($this->sms)('absent'))->toBe(1);
    expect(($this->tell)($sheet['id']))->toBe(0)->and(($this->sms)('absent'))->toBe(1);

    // An earlier day's sheet tells nobody.
    $old = ($this->sheet)('2026-03-09', [($this->mark)($this->karim, 'absent')])->assertCreated()->json('data.sheet');
    expect(($this->tell)($old['id']))->toBe(0);
});

it('by period, tells of absence from the first period, and of a missed later class when asked', function () {
    Queue::fake();
    orgRule($this->w->c1, 'student_attendance.by_period', true);
    orgRule($this->w->c1, 'student_attendance.notify_absent', ['enabled' => true, 'channels' => ['sms']]);
    $p1 = ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 1, 'name' => ['en' => '1st']])->assertCreated()->json('data');
    $p2 = ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 2, 'name' => ['en' => '2nd']])->assertCreated()->json('data');

    $second = ($this->sheet)('2026-03-10', [($this->mark)($this->karim, 'absent')], ['period_id' => $p2['id']])->assertCreated()->json('data.sheet');
    // The 2nd period alone is the day's first taken so far: it decides.
    $first = ($this->sheet)('2026-03-10', [($this->mark)($this->karim, 'present')], ['period_id' => $p1['id']])->assertCreated()->json('data.sheet');
    expect(($this->tell)($first['id']))->toBe(0)->and(($this->tell)($second['id']))->toBe(0);

    orgRule($this->w->c1, 'student_attendance.notify_class_skip', true);
    expect(($this->tell)($second['id']))->toBe(1)->and(($this->sms)('skipped'))->toBe(1)->and(($this->sms)('absent'))->toBe(0);
    $message = NotificationDelivery::query()->where('notification_key', 'student_attendance.skipped')->sole();
    expect($message->body ?? $message->message ?? json_encode($message->toArray()))->toContain('2nd');
});

it('shows a student and their guardian only the student\'s own month in the portal', function () {
    ($this->sheet)('2026-03-09', [($this->mark)($this->rahim, 'absent')])->assertCreated();
    ($this->sheet)('2026-03-10', [($this->mark)($this->rahim, 'late')])->assertCreated();
    $join = function (array $student, string $relation, string $email) {
        $invitation = ($this->as)()->postJson("/api/organizations/{$this->w->c1->id}/portal/invitations", [
            'subject_type' => 'education.student', 'subject_id' => $student['id'], 'relation' => $relation, 'name' => $student['name'], 'channel' => 'mail', 'email' => $email,
        ])->assertCreated()->json('data');
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $this->withHeader('Origin', config('app.url'))->actingAs($user, 'web')->postJson('/session/portal/join', ['key' => $invitation['code']])->assertOk();
        $link = PortalLink::query()->where('subject_id', $student['id'])->where('relation', $relation)->sole();
        ($this->as)()->postJson("/api/organizations/{$this->w->c1->id}/portal/links/{$link->id}/approve")->assertOk();

        return [orgToken($user, $this->w->c1), $link->id];
    };
    [$parentToken, $linkId] = $join($this->rahim, 'guardian', 'parent@example.com');
    [, $karimLink] = $join($this->karim, 'guardian', 'karim.parent@example.com');

    $month = $this->asToken($parentToken)->getJson("/api/portal/student-attendance?record={$linkId}&month=2026-03")->assertOk()->json('data');
    expect($month['student']['name'])->toBe('Rahim')
        ->and($month['days'])->toBe([['date' => '2026-03-09', 'status' => 'absent'], ['date' => '2026-03-10', 'status' => 'late']])
        ->and($month['totals'])->toMatchArray(['present' => 1, 'absent' => 1, 'late' => 1, 'days' => 2]);
    // Another family's child, no child named, or staff: the same 404.
    $this->asToken($parentToken)->getJson("/api/portal/student-attendance?record={$karimLink}")->assertNotFound();
    $this->asToken($parentToken)->getJson('/api/portal/student-attendance')->assertNotFound();
    ($this->as)()->getJson('/api/portal/student-attendance')->assertNotFound();

    toggles()->disable($this->w->c1, 'student_attendance', 'Test');
    $this->asToken($parentToken)->getJson("/api/portal/student-attendance?record={$linkId}")->assertForbidden();
});

it('shows on the home and in the bell what is left today and who comes too little, and asks for periods and holidays', function () {
    $widget = fn (string $key, ?string $token = null) => ($this->as)($token)->getJson("/api/modules/student_attendance/dashboard/{$key}");
    $bell = fn (?string $token = null) => collect(($this->as)($token)->getJson('/api/attention')->assertOk()->json('data'))->keyBy('key');
    $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'student_attendance.view', 'student_attendance.take'], 'Teacher'));
    $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Rina Akter', 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
    DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
    $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$this->school->session['id']}"))->json('data.0');
    ($this->as)()->patchJson(($this->api)("structure/sections/{$this->section}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();
    $rina = orgToken($user, $this->w->c1);

    $widget('today')->assertOk()->assertJsonPath('data.value', 1);
    expect($bell($rina)->get('student_attendance.pending')['count'])->toBe(1);
    // Karim away five days of six: below 75%.
    foreach (['2026-03-03', '2026-03-04', '2026-03-05', '2026-03-08', '2026-03-09'] as $day) {
        ($this->sheet)($day, [($this->mark)($this->karim, 'absent')])->assertCreated();
    }
    ($this->sheet)('2026-03-10', [], token: $rina)->assertCreated();
    $widget('today')->assertJsonPath('data.value', 0);
    expect($bell($rina)->has('student_attendance.pending'))->toBeFalse();
    $low = $widget('low')->assertOk()->json('data.items');
    expect($low)->toHaveCount(1)->and($low[0]['label'])->toBe('Karim')->and($low[0]['path'])->toBe("/education/students/{$this->karim['id']}?tab=attendance");
    // The bell's low count is for who manages attendance only.
    expect($bell()->get('student_attendance.low')['count'])->toBe(1)->and($bell($rina)->has('student_attendance.low'))->toBeFalse();
    orgRule($this->w->c1, 'student_attendance.low_warn_bp', 1500);
    expect($widget('low')->json('data.items'))->toBe([]);

    // A weekly day off has nothing to take.
    orgRule($this->w->c1, 'student_attendance.weekend_days', ['tue']);
    $widget('today')->assertJsonPath('data.value', 0);

    $steps = fn () => collect(($this->as)()->getJson(($this->api)('setup-steps'))->assertOk()->json('data.groups'))->flatMap(fn ($group) => $group['steps'])->keyBy('key');
    expect($steps()->get('holidays')['done'])->toBeFalse()->and($steps()->has('periods'))->toBeFalse();
    ($this->as)()->postJson(($this->sta)('holidays'), ['starts_on' => '2026-12-16', 'name' => ['en' => 'Victory Day']])->assertCreated();
    orgRule($this->w->c1, 'student_attendance.by_period', true);
    expect($steps()->get('holidays')['done'])->toBeTrue()->and($steps()->get('periods')['done'])->toBeFalse();
    ($this->as)()->postJson(($this->sta)('periods', $this->w->b1), ['number' => 1, 'name' => ['en' => '1st']])->assertCreated();
    expect($steps()->get('periods')['done'])->toBeTrue();
});
