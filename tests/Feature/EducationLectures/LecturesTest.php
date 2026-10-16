<?php

use App\Models\User;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Enums\OrganizationType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\EducationLectures\Events\LecturePublished;

/*
 * LEC-1: class lectures. A teacher posts for a subject they teach in a
 * section (never another), publishes (or it waits for approval, never by
 * the one who posted), withdraws with a reason; files checked by content,
 * size and count, opened through a signed link; video links only to the
 * allowed sites; students and guardians read their own section's lectures
 * in the portal; permission, campus, company and module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    Storage::fake('local');
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        foreach (['education', 'hrm', 'education_lectures', 'client_portal'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->lec = fn (string $path = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/lectures'.($path === '' ? '' : "/{$path}");
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);

    $this->school = eduSchool($this);
    $this->subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
    $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
    foreach (['BAN1', 'MATH'] as $code) {
        ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $this->subjects[$code]['id'], 'kind' => 'compulsory'])->assertCreated();
    }
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated()->json('data');

    // A teacher of Maths in section A (HRM employee with a login, the template's permissions).
    $this->teacher = function (string $name, ?string $subject = null) {
        $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education_lectures.view', 'education_lectures.post'], "Teacher {$name}"));
        $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => $name, 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
        DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
        if ($subject !== null) {
            ($this->as)()->postJson(($this->api)('structure/subject_teachers'), ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects[$subject]['id'], 'teacher_id' => $employee])->assertCreated();
        }

        return (object) ['user' => $user, 'token' => orgToken($user, $this->w->c1), 'employee' => $employee];
    };
    $this->post = fn (string $token, string $subject = 'MATH', array $extra = []) => ($this->as)($token)->postJson(($this->lec)(), [
        'section_id' => $this->school->section['id'], 'subject_id' => $this->subjects[$subject]['id'], 'lesson_on' => '2026-03-10', 'title' => 'Fractions', 'body' => "Halves and quarters.\nPage 12.", ...$extra,
    ]);
    $this->rina = ($this->teacher)('Rina Akter', 'MATH');
});

it('lets a teacher post for their subject, publish it, and tells nobody else\'s subject apart', function () {
    Event::fake([LecturePublished::class]);
    $draft = ($this->post)($this->rina->token, extra: ['op_id' => 'tap-1'])->assertCreated()->json('data');
    expect($draft)->toMatchArray(['status' => 'draft', 'teacher_id' => $this->rina->employee, 'lesson_on' => '2026-03-10', 'may_change' => true, 'version' => 1]);
    // The same post again (a resend) is the same lecture.
    ($this->post)($this->rina->token, extra: ['op_id' => 'tap-1'])->assertOk()->assertJsonPath('data.id', $draft['id']);

    // Bangla is not theirs; a subject outside the class's curriculum is nobody's.
    ($this->post)($this->rina->token, 'BAN1')->assertForbidden()->assertJsonPath('code', 'not_your_subject');
    ($this->as)()->postJson(($this->lec)(), ['section_id' => $this->school->section['id'], 'subject_id' => $this->subjects['ENG1']['id'], 'lesson_on' => '2026-03-10', 'title' => 'Tenses'])
        ->assertUnprocessable()->assertJsonPath('code', 'subject_not_in_section');

    $changed = ($this->as)($this->rina->token)->patchJson(($this->lec)($draft['id']), ['title' => 'Fractions 1', 'base_version' => 1])->assertOk()->json('data');
    ($this->as)($this->rina->token)->patchJson(($this->lec)($draft['id']), ['title' => 'Old', 'base_version' => 1])->assertStatus(409);
    $published = ($this->as)($this->rina->token)->postJson(($this->lec)("{$draft['id']}/publish"), ['base_version' => $changed['version']])->assertOk()->json('data');
    expect($published['status'])->toBe('published')->and($published['published_at'])->not->toBeNull();
    Event::assertDispatched(LecturePublished::class, fn ($event) => $event->lectureId === $draft['id']);

    // A published lecture is withdrawn with a reason, never deleted.
    ($this->as)($this->rina->token)->deleteJson(($this->lec)($draft['id']))->assertStatus(409);
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$draft['id']}/withdraw"), ['base_version' => $published['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$draft['id']}/withdraw"), ['base_version' => $published['version'], 'reason' => 'Wrong chapter'])
        ->assertOk()->assertJsonPath('data.status', 'withdrawn')->assertJsonPath('data.withdrawn_reason', 'Wrong chapter');
});

it('makes a teacher\'s lecture wait for approval when the rule asks, never approved by the one who posted it', function () {
    orgRule($this->w->c1, 'education_lectures.approval_required', true);
    $draft = ($this->post)($this->rina->token)->assertCreated()->json('data');
    $waiting = ($this->as)($this->rina->token)->postJson(($this->lec)("{$draft['id']}/publish"), ['base_version' => 1])->assertOk()->assertJsonPath('data.status', 'waiting')->json('data');
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$draft['id']}/approve"), ['base_version' => $waiting['version']])->assertForbidden();

    // The head teacher sees it in the bell and approves it.
    $head = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education_lectures.view', 'education_lectures.manage'], 'Head'));
    $headToken = orgToken($head, $this->w->c1);
    expect(collect(($this->as)($headToken)->getJson('/api/attention')->json('data'))->firstWhere('key', 'education_lectures.waiting'))->toMatchArray(['count' => 1]);
    ($this->as)($headToken)->postJson(($this->lec)("{$draft['id']}/approve"), ['base_version' => $waiting['version']])->assertOk()->assertJsonPath('data.status', 'published');

    // A lecture the head teacher posts is shown at once, and they cannot approve their own.
    $own = ($this->post)($headToken, 'BAN1')->assertCreated()->json('data');
    ($this->as)($headToken)->postJson(($this->lec)("{$own['id']}/publish"), ['base_version' => 1])->assertOk()->assertJsonPath('data.status', 'published');
});

it('checks files by what they are, their size and count, and opens them only through a short signed link', function () {
    $draft = ($this->post)($this->rina->token)->assertCreated()->json('data');
    $add = fn ($file) => ($this->as)($this->rina->token)->post(($this->lec)("{$draft['id']}/files"), ['file' => $file], ['Accept' => 'application/json']);

    $withFile = $add(UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"))->assertCreated()->json('data');
    expect($withFile['files'])->toHaveCount(1)->and($withFile['files'][0])->toMatchArray(['name' => 'notes.pdf', 'mime' => 'application/pdf']);
    // A script named like a PDF is refused.
    $add(UploadedFile::fake()->createWithContent('virus.pdf', "<?php echo 'x';"))->assertUnprocessable()->assertJsonValidationErrors('file');
    // A Word file is recognised by what is inside it.
    $docx = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($docx, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<w:document/>');
    $zip->close();
    // A Bangla name keeps its vowel signs; anything path-like does not survive.
    $add(new UploadedFile($docx, 'ভগ্নাংশ অনুশীলনী.docx', null, null, true))->assertCreated()->assertJsonPath('data.files.1.name', 'ভগ্নাংশ অনুশীলনী.docx');

    orgRule($this->w->c1, 'education_lectures.max_file_kb', 100);
    $add(UploadedFile::fake()->create('big.pdf', 200, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
    orgRule($this->w->c1, 'education_lectures.max_files', 2);
    $add(UploadedFile::fake()->createWithContent('third.pdf', "%PDF-1.4\n%%EOF"))->assertUnprocessable()->assertJsonPath('code', 'too_many_files');

    // The link opens the file; without the signature, or once withdrawn, it does not.
    $url = $withFile['files'][0]['url'];
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(strtok($url, '?'))->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});

it('takes video links only to the sites the institution allows', function () {
    ($this->post)($this->rina->token, extra: ['video_url' => 'https://www.youtube.com/watch?v=abc'])->assertCreated();
    ($this->post)($this->rina->token, extra: ['video_url' => 'https://evil.example.com/watch'])->assertUnprocessable()->assertJsonValidationErrors('video_url');
    ($this->post)($this->rina->token, extra: ['video_url' => 'http://youtu.be/abc'])->assertUnprocessable()->assertJsonValidationErrors('video_url');
    orgRule($this->w->c1, 'education_lectures.video_hosts', ['vimeo']);
    ($this->post)($this->rina->token, extra: ['video_url' => 'https://youtu.be/abc'])->assertUnprocessable()->assertJsonValidationErrors('video_url');
    ($this->post)($this->rina->token, extra: ['video_url' => 'https://vimeo.com/123'])->assertCreated();
});

it('shows a student and their guardian only the published lectures of the student\'s section', function () {
    $published = ($this->post)($this->rina->token)->assertCreated()->json('data');
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$published['id']}/publish"), ['base_version' => 1])->assertOk();
    ($this->post)($this->rina->token, extra: ['title' => 'Still a draft'])->assertCreated();

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
    [$rahimToken] = $join($this->rahim, 'self', 'rahim@example.com');
    [$parentToken, $linkId] = $join($this->rahim, 'guardian', 'parent@example.com');

    $own = $this->asToken($rahimToken)->getJson('/api/portal/lectures')->assertOk();
    expect($own->json('data'))->toHaveCount(1)->and($own->json('data.0.title'))->toBe('Fractions')->and($own->json('meta.student.name'))->toBe('Rahim');
    $this->asToken($parentToken)->getJson("/api/portal/lectures?record={$linkId}")->assertOk()->assertJsonCount(1, 'data');
    // A guardian without naming the child has no "self" student; staff are not portal members.
    $this->asToken($parentToken)->getJson('/api/portal/lectures')->assertNotFound();
    ($this->as)()->getJson('/api/portal/lectures')->assertNotFound();

    // A student of another section sees none of it.
    $sectionB = ($this->as)()->postJson(($this->api)('structure/sections'), ['unit_id' => $this->w->b1->id, 'session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'name' => 'B', 'capacity' => 30])->assertCreated()->json('data');
    $karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => [], 'enrollment' => ['session_id' => $this->school->session['id'], 'level_id' => $this->school->class6['id'], 'section_id' => $sectionB['id']]])->assertCreated()->json('data');
    [$karimToken] = $join($karim, 'self', 'karim@example.com');
    $this->asToken($karimToken)->getJson('/api/portal/lectures')->assertOk()->assertJsonCount(0, 'data');

    // A new lecture is told by mail to the section's portal logins (Rahim and his parent), never to Karim.
    $next = ($this->post)($this->rina->token, extra: ['title' => 'Decimals'])->assertCreated()->json('data');
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$next['id']}/publish"), ['base_version' => 1])->assertOk();
    $mails = NotificationDelivery::query()->where('notification_key', 'education_lectures.published')->where('channel', 'mail')->get();
    expect($mails)->toHaveCount(2)->and($mails->pluck('channel')->unique()->all())->toBe(['mail']);
    // The rule can keep it quiet.
    orgRule($this->w->c1, 'education_lectures.notify_on_publish', false);
    $quiet = ($this->post)($this->rina->token, extra: ['title' => 'Percent'])->assertCreated()->json('data');
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$quiet['id']}/publish"), ['base_version' => 1])->assertOk();
    expect(NotificationDelivery::query()->where('notification_key', 'education_lectures.published')->count())->toBe(2);
});

it('keeps lectures to who may see them, the campus, the company and the module being on', function () {
    $draft = ($this->post)($this->rina->token)->assertCreated()->json('data');
    // Another teacher sees neither Rina's draft nor (not teaching here) her section's lectures.
    $kamal = ($this->teacher)('Kamal Hossain');
    ($this->as)($kamal->token)->getJson(($this->lec)($draft['id']))->assertNotFound();
    ($this->as)($kamal->token)->getJson(($this->lec)())->assertOk()->assertJsonCount(0, 'data');
    ($this->as)($kamal->token)->patchJson(($this->lec)($draft['id']), ['title' => 'Mine now', 'base_version' => 1])->assertNotFound();
    // Someone who may not post cannot; someone without the module's permission cannot read.
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education_lectures.view'], 'Viewer'));
    ($this->post)(orgToken($viewer, $this->w->c1))->assertForbidden();
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Clerk'));
    ($this->as)(orgToken($clerk, $this->w->c1))->getJson(($this->lec)())->assertForbidden();

    // Another campus and another company see nothing of it.
    $north = createChild($this->w->c1, OrganizationType::Branch, 'North campus');
    ($this->as)()->getJson(($this->lec)($draft['id'], $north))->assertNotFound();
    $stranger = createMember($this->w->c2);
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->lec)($draft['id'], $this->w->c2))->assertNotFound();
    $this->asToken(orgToken($stranger, $this->w->c2))->getJson(($this->lec)('', $this->w->c2))->assertOk()->assertJsonCount(0, 'data');

    toggles()->disable($this->w->g1, 'education_lectures', 'Test', confirm: true);
    ($this->as)()->getJson(($this->lec)())->assertForbidden();
    ($this->as)()->getJson('/api/modules/education_lectures/dashboard/week')->assertForbidden();
});

it('counts the week\'s lectures and names subjects that have gone quiet', function () {
    $draft = ($this->post)($this->rina->token, extra: ['lesson_on' => '2026-03-09'])->assertCreated()->json('data');
    ($this->as)($this->rina->token)->postJson(($this->lec)("{$draft['id']}/publish"), ['base_version' => 1])->assertOk();
    ($this->as)()->getJson('/api/modules/education_lectures/dashboard/week')->assertOk()->assertJsonPath('data.value', 1);

    // Maths had a lecture yesterday; nobody teaches Bangla yet, so neither is listed.
    expect(($this->as)()->getJson('/api/modules/education_lectures/dashboard/quiet')->assertOk()->json('data.items'))->toBe([]);

    // Bangla gets a teacher whose last lecture was in February: quiet for longer than 14 days.
    $kamal = ($this->teacher)('Kamal Hossain', 'BAN1');
    $old = ($this->post)($kamal->token, 'BAN1', ['lesson_on' => '2026-02-20'])->assertCreated()->json('data');
    ($this->as)($kamal->token)->postJson(($this->lec)("{$old['id']}/publish"), ['base_version' => 1])->assertOk();
    $quiet = ($this->as)()->getJson('/api/modules/education_lectures/dashboard/quiet')->assertOk()->json('data.items');
    expect($quiet)->toHaveCount(1)->and($quiet[0])->toMatchArray(['date' => '2026-02-20', 'tone' => 'warn', 'path' => "/lectures?section={$this->school->section['id']}&subject={$this->subjects['BAN1']['id']}"]);
    // A longer quiet time (rule) leaves it out.
    orgRule($this->w->c1, 'education_lectures.quiet_days', 30);
    expect(($this->as)()->getJson('/api/modules/education_lectures/dashboard/quiet')->json('data.items'))->toBe([]);
});
