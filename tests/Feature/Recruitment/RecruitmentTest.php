<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\EduWebsite\Models\Site;
use Modules\EduWebsite\Services\BlockTypes;
use Modules\EduWebsite\Services\PublicSite;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Candidate;
use Modules\Recruitment\Services\Careers;

/*
 * REC-1: job openings at a company's units, the public careers page
 * (apply with a CV, confirm the email; robots and old forms refused),
 * applications moving through the company's stages, interviews with each
 * interviewer's feedback, hiring into HRM, people not taken forgotten,
 * and the website's "jobs" block. Company C1; today is Wednesday
 * 28 October 2026.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-28 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    // Recruitment is in the business plan, not the starter one.
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'recruitment', 'Test setup');
    }
    $c1 = $this->w->c1;
    $this->hrUser = staffWithRoles($c1, makeRole($c1, ['hrm.view', 'hrm.manage', 'recruitment.view', 'recruitment.manage', 'recruitment.hire'], 'HR'));
    $this->hr = orgToken($this->hrUser, $c1);
    $this->interviewerUser = staffWithRoles($c1, makeRole($c1, ['recruitment.interview'], 'Interviewer'));
    $this->interviewer = orgToken($this->interviewerUser, $c1);
    $this->viewer = orgToken(staffWithRoles($c1, makeRole($c1, ['recruitment.view'], 'Viewer')), $c1);
    $this->outsider = orgToken(staffWithRoles($c1, makeRole($c1, ['hrm.view'], 'Staff')), $c1);

    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $c1)->id."/recruitment/{$path}";
    $this->opening = function (array $data = [], bool $open = true) {
        $opening = $this->asToken($this->hr)->postJson(($this->api)('openings'), [
            'title' => ['en' => 'Accounts officer', 'bn' => 'হিসাব কর্মকর্তা'], 'description' => ['en' => "Keep the books.\nWork with the team."],
            'vacancies' => 1, 'salary_min_minor' => 2500000, 'salary_max_minor' => 3500000, 'show_salary' => true, ...$data,
        ])->assertCreated()->json('data');

        return $open ? $this->asToken($this->hr)->postJson(($this->api)("openings/{$opening['id']}/open"), ['base_version' => $opening['version']])->assertOk()->json('data') : $opening;
    };
    $this->careers = fn () => $this->asToken($this->hr)->putJson(($this->api)('careers'), ['slug' => 'c1-jobs'])->assertOk();
    // A person applying on the careers page (the form sent after a few seconds, with its token).
    $this->apply = function (array $job, array $data = [], ?UploadedFile $cv = null) {
        $token = app(Careers::class)->token($this->w->c1, now()->subMinute()->getTimestamp());

        return $this->post("/careers/c1-jobs/jobs/{$job['id']}/apply", [
            't' => $token, 'name' => 'Sadia Rahman', 'email' => 'sadia@example.test', 'phone' => '+8801711000111', 'cover_note' => 'Five years in accounts.',
            'consent' => '1', 'lang' => 'en', 'cv' => $cv ?? UploadedFile::fake()->create('sadia-cv.pdf', 200, 'application/pdf'), ...$data,
        ]);
    };
    // The link in the confirmation mail (its token is in the message values until it is sent).
    $this->confirmLink = function () {
        $link = parse_url(NotificationDelivery::query()->where('notification_key', 'recruitment.confirm')->latest('id')->first()->data['link']);

        return $link['path'].'?'.$link['query'];
    };
    $this->added = fn (array $job, array $data = []) => $this->asToken($this->hr)->postJson(($this->api)('applications'), [
        'opening_id' => $job['id'], 'name' => 'Karim Uddin', 'email' => 'karim@example.test', 'source' => 'referral', ...$data,
    ])->assertCreated()->json('data');
});

it('writes, opens and closes openings within the rules, for those who manage recruitment', function () {
    $this->asToken($this->viewer)->postJson(($this->api)('openings'), ['title' => ['en' => 'Clerk']])->assertForbidden();
    $this->asToken($this->hr)->postJson(($this->api)('openings'), ['title' => ['en' => 'Clerk'], 'salary_min_minor' => 5, 'salary_max_minor' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('salary_max_minor');
    $this->asToken($this->hr)->postJson(($this->api)('openings'), ['title' => ['en' => 'Clerk'], 'position_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
        ->assertUnprocessable()->assertJsonValidationErrors('position_id');
    $this->asToken($this->hr)->postJson(($this->api)('openings'), ['title' => ['en' => 'Clerk'], 'unit_id' => $this->w->c2->id])->assertNotFound();
    $this->asToken($this->hr)->postJson(($this->api)('openings'), ['title' => ['en' => 'Clerk'], 'organization_id' => $this->w->c2->id])->assertUnprocessable();

    $draft = ($this->opening)(['closes_on' => '2026-10-01'], open: false);
    expect($draft)->toMatchArray(['status' => 'draft', 'currency' => 'BDT', 'unit_id' => $this->w->c1->id, 'version' => 1]);
    $this->asToken($this->hr)->postJson(($this->api)("openings/{$draft['id']}/open"), ['base_version' => $draft['version']])
        ->assertUnprocessable()->assertJsonValidationErrors('closes_on');
    $draft = $this->asToken($this->hr)->patchJson(($this->api)("openings/{$draft['id']}"), ['base_version' => $draft['version'], 'closes_on' => '2026-11-30', 'vacancies' => 2])
        ->assertOk()->json('data');
    $open = $this->asToken($this->hr)->postJson(($this->api)("openings/{$draft['id']}/open"), ['base_version' => $draft['version']])->assertOk()->json('data');
    expect($open['status'])->toBe('open');
    $this->asToken($this->hr)->patchJson(($this->api)("openings/{$draft['id']}"), ['base_version' => $draft['version'], 'vacancies' => 3])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');

    $list = $this->asToken($this->viewer)->getJson(($this->api)('openings'))->assertOk();
    expect($list->json('data.0.title'))->toBe('Accounts officer')->and($list->json('meta.can_manage'))->toBeFalse();
    $this->asToken($this->outsider)->getJson(($this->api)('openings'))->assertForbidden();
    $closed = $this->asToken($this->hr)->postJson(($this->api)("openings/{$open['id']}/close"), ['base_version' => $open['version']])->assertOk()->json('data');
    expect($closed['status'])->toBe('closed')
        ->and(AuditLog::query()->whereIn('action', ['recruitment.opening_added', 'recruitment.opening_changed', 'recruitment.opening_opened', 'recruitment.opening_closed'])->count())->toBe(4);
});

it('takes an application on the careers page only once the email is confirmed', function () {
    $job = ($this->opening)();
    $hidden = ($this->opening)(['title' => ['en' => 'Internal post'], 'is_public' => false]);
    $this->get('/careers/c1-jobs')->assertNotFound();
    ($this->careers)();
    URL::forceRootUrl(config('app.url'));

    $page = $this->get('/careers/c1-jobs?lang=en')->assertOk()->assertSee('Accounts officer')->assertDontSee('Internal post')->assertSee('25,000 BDT');
    expect($page->headers->get('Content-Security-Policy'))->toContain("script-src 'none'");
    $this->get("/careers/c1-jobs/jobs/{$hidden['id']}")->assertNotFound();
    $this->get("/careers/c1-jobs/jobs/{$job['id']}?lang=bn")->assertOk()->assertSee('হিসাব কর্মকর্তা')->assertSee('name="t"', false);

    // Robots: the hidden field filled, or a form sent within seconds: told it went, nothing kept.
    ($this->apply)($job, ['website' => 'http://spam.test'])->assertRedirect();
    $this->post("/careers/c1-jobs/jobs/{$job['id']}/apply", ['t' => app(Careers::class)->token($this->w->c1), 'name' => 'Bot', 'email' => 'bot@example.test'])->assertRedirect();
    ($this->apply)($job, ['t' => '123.abc'])->assertStatus(422)->assertSee('This form is too old');
    ($this->apply)($job, ['consent' => null])->assertStatus(422)->assertSee('Please agree');
    ($this->apply)($job, [], UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))->assertStatus(422)->assertSee('Only PDF and Word files');
    expect(Application::query()->withoutGlobalScopes()->count())->toBe(0);

    ($this->apply)($job)->assertRedirect("/careers/c1-jobs/jobs/{$job['id']}?lang=en&applied=1#apply");
    $candidate = Candidate::query()->withoutGlobalScopes()->sole();
    expect($candidate->email)->toBe('sadia@example.test')
        ->and(DB::table('rec_candidates')->value('email'))->not->toContain('sadia')
        ->and(Storage::disk('local')->allFiles('recruitment'))->toHaveCount(1);
    // Not seen by the company until confirmed.
    expect($this->asToken($this->hr)->getJson(($this->api)('applications'))->json('data'))->toBe([])
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.applied')->count())->toBe(0);

    $link = ($this->confirmLink)();
    expect($link)->toStartWith('/careers/c1-jobs/confirm/')->toEndWith('?lang=en');
    // Opening the link (a mail scanner) confirms nothing; the button does.
    $this->get($link)->assertOk()->assertSee('Confirm my application');
    expect(Application::query()->withoutGlobalScopes()->sole()->status)->toBe('unconfirmed');
    $this->post($link)->assertOk()->assertSee('Your application is sent');
    $this->post($link)->assertStatus(410);

    $list = $this->asToken($this->hr)->getJson(($this->api)('applications'))->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0])->toMatchArray(['name' => 'Sadia Rahman', 'email' => 'sadia@example.test', 'stage' => 'applied', 'source' => 'careers'])
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.applied')->pluck('user_id')->all())->toContain($this->hrUser->id)
        ->and(json_encode(NotificationDelivery::query()->where('notification_key', 'recruitment.applied')->first()->data))->not->toContain('Sadia');
    ($this->apply)($job)->assertStatus(409)->assertSee('already applied');

    // The CV opens only through its short signed link.
    $detail = $this->asToken($this->hr)->getJson(($this->api)("applications/{$list[0]['id']}"))->assertOk()->json('data');
    expect($detail['cover_note'])->toBe('Five years in accounts.')->and($detail['files'])->toHaveCount(1);
    $this->get($detail['files'][0]['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(str_replace('signature=', 'signature=0', $detail['files'][0]['url']))->assertForbidden();
});

it('limits applications per email and turns public applying off by rule', function () {
    $job = ($this->opening)();
    ($this->careers)();
    partnerRule($this->w->c1->partner, 'recruitment.applications_per_email_day', 1);
    ($this->apply)($job)->assertRedirect();
    $other = ($this->opening)(['title' => ['en' => 'Cashier']]);
    ($this->apply)($other)->assertStatus(429)->assertSee('Too many applications');

    trustedOrgRule($this->w->c1, 'recruitment.public_apply', false);
    $this->get('/careers/c1-jobs?lang=en')->assertOk()->assertSee('No jobs are open right now');
    $this->get("/careers/c1-jobs/jobs/{$job['id']}")->assertNotFound();
});

it('moves applications through the stages, with interviews and each interviewer\'s own feedback', function () {
    $job = ($this->opening)();
    $application = ($this->added)($job);
    expect($application)->toMatchArray(['stage' => 'applied', 'status' => 'active', 'source' => 'referral'])
        ->and(collect($application['stages'])->pluck('key')->all())->toBe(['applied', 'screening', 'interview', 'offer']);
    $this->asToken($this->hr)->postJson(($this->api)('applications'), ['opening_id' => $job['id'], 'name' => 'Karim Again', 'email' => 'KARIM@example.test', 'source' => 'manual'])
        ->assertStatus(409)->assertJsonPath('code', 'already_applied');

    $this->asToken($this->hr)->patchJson(($this->api)("applications/{$application['id']}"), ['base_version' => $application['version'], 'stage' => 'party'])
        ->assertUnprocessable()->assertJsonValidationErrors('stage');
    $application = $this->asToken($this->hr)->patchJson(($this->api)("applications/{$application['id']}"), ['base_version' => $application['version'], 'stage' => 'interview', 'rating' => 4, 'note' => 'Strong CV'])
        ->assertOk()->json('data');
    expect($application)->toMatchArray(['stage' => 'interview', 'rating' => 4])->and(collect($application['history'])->last())->toMatchArray(['from' => 'applied', 'to' => 'interview', 'note' => 'Strong CV']);

    // Only people who may interview here can be asked.
    $choices = collect($this->asToken($this->hr)->getJson(($this->api)("applications/{$application['id']}/interviewers"))->assertOk()->json('data'))->pluck('id');
    expect($choices)->toContain($this->interviewerUser->id, $this->hrUser->id);
    $schedule = fn (array $ids, string $at = '2026-10-30T10:30') => $this->asToken($this->hr)->postJson(($this->api)("applications/{$application['id']}/interviews"), [
        'starts_at' => $at, 'minutes' => 45, 'mode' => 'in_person', 'place' => 'Head office, room 3', 'interviewer_ids' => $ids,
    ]);
    $schedule([$this->w->owner->id === $this->hrUser->id ? 'x' : staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Nobody'))->id])->assertUnprocessable()->assertJsonValidationErrors('interviewer_ids');
    $schedule([$this->interviewerUser->id], '2026-10-27T10:00')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    $detail = $schedule([$this->interviewerUser->id, $this->hrUser->id])->assertCreated()->json('data');
    $interview = $detail['interviews'][0];
    expect($interview)->toMatchArray(['mode' => 'in_person', 'status' => 'scheduled', 'minutes' => 45])
        ->and($interview['starts_at'])->toBe('2026-10-30T04:30:00+00:00')
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.interview_scheduled')->pluck('user_id')->sort()->values()->all())
        ->toEqual(collect([$this->interviewerUser->id, $this->hrUser->id])->sort()->values()->all())
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.interview_invite')->sole()->data['when'])->toContain('১০:৩০');

    // The interviewer sees their interviews and that application only.
    $mine = $this->asToken($this->interviewer)->getJson(($this->api)('interviews/mine'))->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0])->toMatchArray(['name' => 'Karim Uddin', 'opening_title' => 'Accounts officer', 'feedback_given' => false]);
    $this->asToken($this->interviewer)->getJson(($this->api)("applications/{$application['id']}"))->assertOk();
    $this->asToken($this->interviewer)->getJson(($this->api)('applications'))->assertForbidden();
    $this->asToken($this->interviewer)->patchJson(($this->api)("applications/{$application['id']}"), ['base_version' => $application['version'], 'stage' => 'offer'])->assertForbidden();

    $this->travelTo(CarbonImmutable::parse('2026-10-30 06:00:00', 'UTC'));
    // Sign-ins made two days ago have run out.
    $this->interviewer = orgToken($this->interviewerUser, $this->w->c1);
    $this->hr = orgToken($this->hrUser, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['recruitment.view'], 'Viewer 2')), $this->w->c1);
    $this->asToken($this->interviewer)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'recruitment.feedback_owed', 'count' => 1]);
    $feedback = fn (string $token, int $score, string $say) => $this->asToken($token)->postJson(($this->api)("interviews/{$interview['id']}/feedback"), ['score' => $score, 'recommendation' => $say, 'note' => "Score {$score}"]);
    $feedback($this->interviewer, 6, 'yes')->assertUnprocessable()->assertJsonValidationErrors('score');
    $feedback($this->viewer, 4, 'yes')->assertForbidden();
    $feedback($this->interviewer, 4, 'yes')->assertOk();
    // Each interviewer sees only their own feedback; HR sees everyone's.
    $seen = $this->asToken($this->interviewer)->getJson(($this->api)("applications/{$application['id']}"))->json('data.interviews.0.feedback');
    expect($seen)->toHaveCount(1)->and($seen[0]['mine'])->toBeTrue();
    $feedback($this->hr, 2, 'no')->assertOk();
    $detail = $this->asToken($this->hr)->getJson(($this->api)("applications/{$application['id']}"))->json('data');
    expect($detail['interviews'][0]['feedback'])->toHaveCount(2)
        ->and($detail['interviews'][0]['status'])->toBe('done')
        ->and($detail['average_score'])->toBe(3);
    expect(collect($this->asToken($this->interviewer)->getJson(($this->api)("applications/{$application['id']}"))->json('data.interviews.0.feedback'))->pluck('note')->all())->toBe(['Score 4']);
});

it('rejects with a reason, telling the person when HR wishes, and forgets them later', function () {
    $job = ($this->opening)();
    ($this->careers)();
    ($this->apply)($job);
    $this->post(($this->confirmLink)());
    $sadia = $this->asToken($this->hr)->getJson(($this->api)('applications'))->json('data.0');
    $karim = ($this->added)($job);

    $this->asToken($this->hr)->postJson(($this->api)("applications/{$sadia['id']}/reject"), ['base_version' => $sadia['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $rejected = $this->asToken($this->hr)->postJson(($this->api)("applications/{$sadia['id']}/reject"), ['base_version' => $sadia['version'], 'reason' => 'Needs more experience', 'tell' => true])
        ->assertOk()->json('data');
    expect($rejected)->toMatchArray(['status' => 'rejected', 'reject_reason' => 'Needs more experience'])
        ->and(NotificationDelivery::query()->where('notification_key', 'recruitment.not_selected')->sole()->recipient)->not->toBe('sadia@example.test');
    $this->asToken($this->hr)->postJson(($this->api)("applications/{$karim['id']}/withdraw"), ['base_version' => $karim['version'], 'reason' => 'Took another job'])->assertOk();
    expect(NotificationDelivery::query()->where('notification_key', 'recruitment.not_selected')->count())->toBe(1);
    $this->asToken($this->hr)->patchJson(($this->api)("applications/{$sadia['id']}"), ['base_version' => $rejected['version'], 'stage' => 'offer'])->assertStatus(409);

    // Kept for the rule's days, then forgotten with the CV.
    Artisan::call('recruitment:forget-candidates');
    expect(Application::query()->withoutGlobalScopes()->count())->toBe(2);
    $this->travel(181)->days();
    Artisan::call('recruitment:forget-candidates');
    expect(Application::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Candidate::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('recruitment'))->toBe([])
        ->and(AuditLog::query()->where('action', 'recruitment.people_forgotten')->exists())->toBeTrue();
});

it('forgets applications never confirmed after two days', function () {
    $job = ($this->opening)();
    ($this->careers)();
    ($this->apply)($job);
    $link = ($this->confirmLink)();
    $this->travel(49)->hours();
    $this->post($link)->assertStatus(410);
    Artisan::call('recruitment:forget-candidates');
    expect(Application::query()->withoutGlobalScopes()->count())->toBe(0)->and(Candidate::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('hires into HRM with its rules, and fills the opening', function () {
    $job = ($this->opening)(['employment_type' => 'permanent']);
    $application = ($this->added)($job, ['phone' => '+8801711000222']);
    $hire = fn (string $token, array $data = []) => $this->asToken($token)->postJson(($this->api)("applications/{$application['id']}/hire"), ['base_version' => $application['version'], 'joined_on' => '2026-11-01', ...$data]);

    $noHrm = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['recruitment.view', 'recruitment.manage', 'recruitment.hire'], 'Recruiter')), $this->w->c1);
    $hire($noHrm)->assertForbidden();
    $hire($this->hr, ['employment_type' => 'astronaut'])->assertUnprocessable()->assertJsonValidationErrors('employment_type');

    $hired = $hire($this->hr)->assertOk()->json('data');
    expect($hired['status'])->toBe('hired')->and($hired['employee_id'])->not->toBeNull();
    $employee = $this->asToken($this->hr)->getJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$hired['employee_id']}")->assertOk()->json('data');
    expect($employee)->toMatchArray(['full_name' => 'Karim Uddin', 'employment_type' => 'permanent'])
        ->and($this->asToken($this->hr)->getJson(($this->api)("openings/{$job['id']}"))->json('data'))->toMatchArray(['status' => 'filled', 'hired_count' => 1])
        ->and(AuditLog::query()->where('action', 'recruitment.hired')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'hrm.employee_hired')->exists())->toBeTrue();
    $hire($this->hr, ['base_version' => $hired['version']])->assertStatus(409);
    $this->asToken($this->hr)->postJson(($this->api)('applications'), ['opening_id' => $job['id'], 'name' => 'Late', 'email' => 'late@example.test', 'source' => 'manual'])->assertStatus(409);
});

it('shows open jobs on the website\'s jobs block, linking to the careers page', function () {
    toggles()->enable($this->w->g1, 'education', 'Test setup');
    toggles()->enable($this->w->g1, 'edu_website', 'Test setup');
    ($this->opening)();
    $block = app(PublicSite::class);
    $site = fn () => (new Site)->forceFill(['id' => 'x', 'organization_id' => $this->w->c1->id]);

    $none = $block->blocks($this->w->c1, $site(), ['blocks' => [['type' => 'jobs', 'data' => ['count' => 5]]]], '')[0]['extra'];
    expect($none)->toBe(['url' => null, 'jobs' => []]);
    ($this->careers)();
    $extra = $block->blocks($this->w->c1, $site(), ['blocks' => [['type' => 'jobs', 'data' => ['count' => 5]]]], '')[0]['extra'];
    expect($extra['jobs'])->toHaveCount(1)
        ->and($extra['jobs'][0]['title']['en'])->toBe('Accounts officer')
        ->and($extra['url'])->toEndWith('/careers/c1-jobs')
        ->and($extra['jobs'][0]['url'])->toContain('/careers/c1-jobs/jobs/');
    expect(BlockTypes::clean('jobs', ['count' => 3, 'title' => ['en' => 'Join us'], 'evil' => 1]))->toEqual(['count' => 3, 'title' => ['en' => 'Join us']]);
});

it('keeps recruitment to the company and to the module being on', function () {
    $job = ($this->opening)();
    $application = ($this->added)($job);
    ($this->careers)();
    $c2 = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['recruitment.view', 'recruitment.manage', 'recruitment.hire', 'hrm.manage'], 'C2 HR')), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("openings/{$job['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("applications/{$application['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->postJson(($this->api)("applications/{$application['id']}/hire", $this->w->c2), ['base_version' => 1, 'joined_on' => '2026-11-01'])->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)('applications', $this->w->c2))->assertOk()->assertJsonPath('data', []);
    $this->asToken($c2)->postJson(($this->api)('applications', $this->w->c2), ['opening_id' => $job['id'], 'name' => 'X Y', 'email' => 'x@example.test', 'source' => 'manual'])->assertNotFound();
    // Another company cannot take the address.
    $this->asToken($c2)->putJson(($this->api)('careers', $this->w->c2), ['slug' => 'c1-jobs'])->assertUnprocessable()->assertJsonPath('code', 'slug_taken');

    toggles()->disable($this->w->g1, 'recruitment', 'Not used');
    $this->asToken($this->hr)->getJson(($this->api)('openings'))->assertForbidden();
    $this->get('/careers/c1-jobs')->assertNotFound();
    expect(Application::query()->withoutGlobalScopes()->count())->toBe(1);
});
