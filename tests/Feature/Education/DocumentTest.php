<?php

use App\Platform\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Education\Events\DocumentIssued;
use Modules\Education\Events\DocumentRevoked;
use Modules\Education\Models\Document;

/*
 * EDU-3a: designs of ID cards and certificates (ready-made ones from data
 * files, checked in depth), images on them (private, signed links),
 * issuing (numbers, codes, values and photo kept as at issue, one valid ID
 * card per student, all or nothing, safe to retry), revoking, the public QR
 * check (no private details, one answer for every code that does not
 * verify, throttled), permissions and isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 04:00:00', 'UTC'));
    Storage::fake('local');
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->school = eduSchool($this);
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim Uddin', 'name_local' => 'রহিম উদ্দিন'])->assertCreated()->json('data');
    // A design from the ready-made ones, made active.
    $this->design = function (string $key) {
        $made = ($this->as)()->postJson(($this->api)("document-templates/presets/{$key}/apply"))->assertSuccessful()->json('data');

        return ($this->as)()->patchJson(($this->api)("document-templates/{$made['id']}"), ['base_version' => $made['version'], 'status' => 'active'])->assertOk()->json('data');
    };
    $this->issue = fn (array $template, array $students, array $extra = [], ?string $token = null) => ($this->as)($token)->postJson(($this->api)('documents'), [
        'template_id' => $template['id'], 'student_ids' => $students, ...$extra,
    ]);
    $this->verify = fn (string $code, $company = null) => $this->getJson('/api/public/education/verify/'.($company ?? $this->w->c1)->id."/{$code}");
});

// ── Designs ──

it('adds every ready-made design once, as a draft that passes the checks', function () {
    $list = ($this->as)()->getJson(($this->api)('document-templates'))->assertOk()->json('meta.presets');
    $keys = collect($list)->pluck('key')->all();
    expect($keys)->toBe(['bd_character_certificate', 'bd_id_card', 'bd_testimonial', 'bd_transfer_certificate', 'id_card_en']);

    foreach ($keys as $key) {
        $made = ($this->as)()->postJson(($this->api)("document-templates/presets/{$key}/apply"))->assertCreated()->json('data');
        expect($made)->toMatchArray(['key' => $key, 'status' => 'draft']);
        ($this->as)()->postJson(($this->api)("document-templates/presets/{$key}/apply"))->assertOk()->assertJsonPath('data.id', $made['id']);
    }
    ($this->as)()->postJson(($this->api)('document-templates/presets/nope/apply'))->assertNotFound()->assertJsonPath('code', 'unknown_document_preset');

    $card = ($this->as)()->getJson(($this->api)('document-templates'))->json('data');
    $show = ($this->as)()->getJson(($this->api)('document-templates/'.collect($card)->firstWhere('key', 'bd_id_card')['id']))->assertOk()->json('data');
    expect($show['page_size'])->toBe([856, 540])->and($show['layout']['elements'])->not->toBeEmpty()
        ->and($show['placeholders'])->toContain('student.name')->and($show['placeholders'])->toContain('field.blood_group');
});

it('refuses designs with unknown values, items outside the page or someone else\'s images', function () {
    $base = ['kind' => 'certificate', 'name' => ['en' => 'Note'], 'locale' => 'en', 'page' => ['size' => 'a5', 'orientation' => 'portrait']];
    $text = fn (array $extra) => ['background' => ['color' => '#ffffff'], 'elements' => [['id' => 't1', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 50, 'h' => 10, 'text' => 'Hi {student.name}', ...$extra]]];

    ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'layout' => $text(['text' => 'Hi {studnet.name}'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['layout.elements.0.text']);
    ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'layout' => $text(['x' => 1450])])
        ->assertUnprocessable()->assertJsonValidationErrors(['layout.elements.0.w']);
    ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'layout' => $text(['color' => 'red'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['layout.elements.0.color']);
    ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'layout' => ['elements' => [['id' => 'i', 'type' => 'image', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'asset_id' => str_repeat('A', 26)]]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['layout.elements.0.asset_id']);
    ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'layout' => ['elements' => [['id' => 's', 'type' => 'script', 'x' => 1, 'y' => 1, 'w' => 1, 'h' => 1]]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['layout.elements.0.type']);
    ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'layout' => $text([]), 'extra' => 1])->assertUnprocessable()->assertJsonValidationErrors(['extra']);

    // A question of the design becomes a value it may print; unknown item keys are dropped.
    $made = ($this->as)()->postJson(($this->api)('document-templates'), [...$base, 'inputs' => [['key' => 'reason', 'label' => ['en' => 'Reason']]],
        'layout' => $text(['text' => 'Because {input.reason}', 'onclick' => 'x'])])->assertCreated()->json('data');
    expect($made['layout']['elements'][0])->not->toHaveKey('onclick')->and($made['version'])->toBe(1);

    // Changed with the version read; the kind stays.
    ($this->as)()->patchJson(($this->api)("document-templates/{$made['id']}"), ['base_version' => 1, 'kind' => 'letter'])->assertUnprocessable();
    ($this->as)()->patchJson(($this->api)("document-templates/{$made['id']}"), ['base_version' => 1, 'name' => ['en' => 'Notice']])->assertOk()->assertJsonPath('data.version', 2);
    ($this->as)()->patchJson(($this->api)("document-templates/{$made['id']}"), ['base_version' => 1, 'name' => ['en' => 'Old']])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
});

it('keeps images private, shown only through signed links, and switched off instead of deleted', function () {
    ($this->as)()->postJson(($this->api)('document-assets'), ['kind' => 'logo', 'name' => 'Logo', 'file' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonPath('code', 'bad_asset');
    $asset = ($this->as)()->post(($this->api)('document-assets'), ['kind' => 'logo', 'name' => 'Logo', 'file' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($asset['url'])->toContain('signature=');
    $this->get($asset['url'])->assertOk();
    $this->get(strtok($asset['url'], '?'))->assertForbidden();

    ($this->as)()->patchJson(($this->api)("document-assets/{$asset['id']}"), ['is_active' => false])->assertOk();
    expect(($this->as)()->getJson(($this->api)('document-assets'))->json('data'))->toBe([])
        ->and(($this->as)()->getJson(($this->api)('document-assets?all=1'))->json('data'))->toHaveCount(1);
});

// ── Issuing ──

it('issues an ID card with a number, a code and values as at issue, one valid card per student', function () {
    Event::fake([DocumentIssued::class, DocumentRevoked::class]);
    $card = ($this->as)()->postJson(($this->api)('document-templates/presets/bd_id_card/apply'))->json('data');
    // A draft cannot be issued.
    ($this->issue)($card, [$this->rahim['id']])->assertStatus(409)->assertJsonPath('code', 'template_not_active');
    $card = ($this->design)('bd_id_card');
    ($this->as)()->postJson(($this->api)("sections/{$this->school->section['id']}/rolls"))->assertOk();

    $first = ($this->issue)($card, [$this->rahim['id']], ['op_id' => 'issue-1'])->assertCreated()->json('data.0');
    expect($first)->toMatchArray(['kind' => 'id_card', 'number' => 'IDC-2026-0001', 'status' => 'valid', 'valid_until' => '2026-12-31', 'student_name' => 'রহিম উদ্দিন']);
    $stored = Document::find($first['id']);
    expect($stored->code)->toMatch('/^[A-HJ-NP-Z2-9]{12}$/')
        ->and($stored->snapshot['values'])->toMatchArray(['student.name' => 'Rahim Uddin', 'roll' => '১', 'level' => 'শ্রেণি ৬', 'section' => 'A', 'document.valid_until' => '৩১/১২/২০২৬', 'guardian.primary_phone' => '+8801711000000']);
    Event::assertDispatched(DocumentIssued::class, fn ($event) => $event->documentId === $first['id']);

    // Sent again with the same op id: the same card.
    ($this->issue)($card, [$this->rahim['id']], ['op_id' => 'issue-1'])->assertCreated()->assertJsonPath('data.0.id', $first['id']);
    // A second card replaces the first only when asked.
    ($this->issue)($card, [$this->rahim['id']])->assertStatus(409)->assertJsonPath('code', 'document_exists')->assertJsonPath('number', 'IDC-2026-0001');
    $second = ($this->issue)($card, [$this->rahim['id']], ['replace' => true])->assertCreated()->json('data.0');
    expect($second['number'])->toBe('IDC-2026-0002')
        ->and(Document::find($first['id'])->revoke_reason)->toBe('IDC-2026-0002 দিয়ে বদলানো হয়েছে');
    Event::assertDispatched(DocumentRevoked::class);

    // Later changes to the student never change an issued card.
    $student = ($this->as)()->getJson(($this->api)("students/{$this->rahim['id']}"))->json('data');
    ($this->as)()->patchJson(($this->api)("students/{$this->rahim['id']}"), ['base_version' => $student['version'], 'name' => 'Rahim Ahmed'])->assertOk();
    expect(Document::find($second['id'])->snapshot['values']['student.name'])->toBe('Rahim Uddin');
});

it('issues to many at once, or to none when one cannot have it', function () {
    $card = ($this->design)('bd_id_card');
    $karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => []])->assertCreated()->json('data');
    $left = newStudent($this, $this->school, ['name' => 'Gone', 'guardians' => [], 'enrollment' => null])->assertCreated()->json('data');
    ($this->as)()->postJson(($this->api)("students/{$left['id']}/leave"), ['base_version' => 1, 'status' => 'left', 'reason' => 'Moved away'])->assertOk();

    ($this->issue)($card, [$this->rahim['id'], $karim['id'], $left['id']])->assertStatus(409)->assertJsonPath('code', 'not_active_student');
    expect(Document::count())->toBe(0);

    $made = ($this->issue)($card, [$this->rahim['id'], $karim['id']])->assertCreated()->json('data');
    expect(collect($made)->pluck('number')->all())->toBe(['IDC-2026-0001', 'IDC-2026-0002']);

    // The rule sets how long a card lasts.
    orgRule($this->w->c1, 'education.id_card_valid_months', 6);
    $again = ($this->issue)($card, [$karim['id']], ['replace' => true])->assertCreated()->json('data.0');
    expect($again['valid_until'])->toBe('2026-07-09');
});

it('asks what a certificate needs, prints it in its language, and keeps the photo of the day', function () {
    $testimonial = ($this->design)('bd_testimonial');
    ($this->issue)($testimonial, [$this->rahim['id']])->assertUnprocessable()->assertJsonValidationErrors(['inputs.conduct']);
    $made = ($this->issue)($testimonial, [$this->rahim['id']], ['inputs' => ['conduct' => 'সন্তোষজনক'], 'issued_on' => '2026-01-05'])->assertCreated()->json('data.0');
    $values = Document::find($made['id'])->snapshot['values'];
    expect($made)->toMatchArray(['number' => 'CRT-2026-0001', 'valid_until' => null])
        ->and($values)->toMatchArray(['input.conduct' => 'সন্তোষজনক', 'document.date' => '০৫/০১/২০২৬', 'guardian.father' => 'Abdul Karim']);

    // The English card carries the photo copied at issue.
    ($this->as)()->post(($this->api)("students/{$this->rahim['id']}/photo"), ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
    $card = ($this->design)('id_card_en');
    $issued = ($this->issue)($card, [$this->rahim['id']])->assertCreated()->json('data.0');
    $show = ($this->as)()->getJson(($this->api)("documents/{$issued['id']}"))->assertOk()->json('data');
    expect($show['photo_url'])->toContain('signature=')->and($show['qr'])->toStartWith('data:image/svg+xml;base64,')
        ->and($show['verify_path'])->toBe('/verify/'.$this->w->c1->id.'/'.Document::find($issued['id'])->code)
        ->and($show['page_size'])->toBe([540, 856])->and((array) $show['values'])->toHaveKey('student.code');
    $this->get($show['photo_url'])->assertOk();
    // A new photo later: the card keeps its own copy.
    ($this->as)()->post(($this->api)("students/{$this->rahim['id']}/photo"), ['photo' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->assertOk();
    expect(Storage::disk('local')->exists(Document::find($issued['id'])->photo_path))->toBeTrue();
});

it('lets only people allowed to see private details issue or open documents that print them', function () {
    $made = ($this->as)()->postJson(($this->api)('document-templates'), [
        'kind' => 'letter', 'name' => ['en' => 'Birth letter'], 'locale' => 'en', 'page' => ['size' => 'a5', 'orientation' => 'portrait'],
        'layout' => ['elements' => [['id' => 'dob', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'text' => 'Born {student.date_of_birth}']]],
    ])->assertCreated()->json('data');
    $active = ($this->as)()->patchJson(($this->api)("document-templates/{$made['id']}"), ['base_version' => 1, 'status' => 'active'])->json('data');
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.issue_documents'], 'Clerk'));
    $clerkToken = orgToken($clerk, $this->w->c1);

    ($this->issue)($active, [$this->rahim['id']], [], $clerkToken)->assertForbidden();
    $issued = ($this->issue)($active, [$this->rahim['id']])->assertCreated()->json('data.0');
    expect($issued['has_sensitive'])->toBeTrue();
    $this->asToken($clerkToken)->getJson(($this->api)("documents/{$issued['id']}"))->assertForbidden();
    $this->asToken($clerkToken)->getJson(($this->api)('documents'))->assertOk()->assertJsonPath('meta.total', 1);
    $this->asToken($clerkToken)->postJson(($this->api)("document-templates/{$made['id']}/preview"), ['student_id' => $this->rahim['id']])->assertForbidden();
});

it('revokes with a reason, once, and lists documents by status', function () {
    $card = ($this->design)('bd_id_card');
    $issued = ($this->issue)($card, [$this->rahim['id']])->json('data.0');

    ($this->as)()->postJson(($this->api)("documents/{$issued['id']}/revoke"), ['reason' => ''])->assertUnprocessable();
    ($this->as)()->postJson(($this->api)("documents/{$issued['id']}/revoke"), ['reason' => 'Lost card'])->assertOk()->assertJsonPath('data.status', 'revoked');
    ($this->as)()->postJson(($this->api)("documents/{$issued['id']}/revoke"), ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'document_revoked');
    expect(AuditLog::where('action', 'education.document_revoked')->count())->toBe(1)
        ->and(AuditLog::where('action', 'education.document_issued')->first()->new_values)->not->toHaveKey('snapshot');

    ($this->issue)($card, [$this->rahim['id']])->assertCreated();
    ($this->as)()->getJson(($this->api)('documents?status=revoked'))->assertJsonPath('meta.total', 1);
    ($this->as)()->getJson(($this->api)('documents?status=valid'))->assertJsonPath('meta.total', 1);
    ($this->as)()->getJson(($this->api)("documents?student_id={$this->rahim['id']}"))->assertJsonPath('meta.total', 2);
    ($this->as)()->getJson(($this->api)('documents?q=idc-2026-0002'))->assertJsonPath('meta.total', 1);
    // A card past its date (one clock for the whole test: sign-in tokens expire over long jumps).
    Document::query()->whereNull('revoked_at')->update(['valid_until' => '2026-01-09']);
    ($this->as)()->getJson(($this->api)('documents?status=expired'))->assertJsonPath('meta.total', 1);
    ($this->as)()->getJson(($this->api)('documents?status=valid'))->assertJsonPath('meta.total', 0);
});

// ── The public QR check ──

it('tells anyone whether a document is valid, without private details', function () {
    $card = ($this->design)('bd_id_card');
    $issued = ($this->issue)($card, [$this->rahim['id']])->json('data.0');
    $code = Document::find($issued['id'])->code;

    $answer = ($this->verify)($code)->assertOk()->json('data');
    expect($answer)->toMatchArray(['status' => 'valid'])
        ->and($answer['document'])->toMatchArray(['kind' => 'id_card', 'number' => 'IDC-2026-0001', 'issued_on' => '2026-01-10', 'valid_until' => '2026-12-31'])
        ->and($answer['student'])->toBe(['name' => 'রহিম উদ্দিন', 'level' => 'মাধ্যমিক · শ্রেণি ৬'])
        ->and(json_encode($answer))->not->toContain('+8801711000000')->not->toContain('photo');
    // Typed by hand: lower case and dashes are fine.
    ($this->verify)(strtolower(substr($code, 0, 4).'-'.substr($code, 4)))->assertOk();

    // The rule limits what shows.
    trustedOrgRule($this->w->c1, 'education.verify_shows', ['issued_on']);
    expect(($this->verify)($code)->json('data.student'))->toBe(['name' => null, 'level' => null]);

    ($this->as)()->postJson(($this->api)("documents/{$issued['id']}/revoke"), ['reason' => 'Lost card'])->assertOk();
    ($this->verify)($code)->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.document.revoked_on', '2026-01-10');
});

it('gives one answer for a wrong code, another institution or Education off, and limits tries', function () {
    $card = ($this->design)('bd_id_card');
    $issued = ($this->issue)($card, [$this->rahim['id']])->json('data.0');
    $code = Document::find($issued['id'])->code;

    $wrong = ($this->verify)('ABCDEFGHJKLM')->assertNotFound()->json();
    expect($wrong['code'])->toBe('verify_not_found');
    expect(($this->verify)($code, $this->w->c2)->assertNotFound()->json('code'))->toBe('verify_not_found')
        ->and($this->getJson("/api/public/education/verify/not-an-id/{$code}")->assertNotFound()->json('code'))->toBe('verify_not_found')
        ->and(($this->verify)('short')->assertNotFound()->json('code'))->toBe('verify_not_found');

    toggles()->disable($this->w->g1, 'education', 'Test', confirm: true);
    expect(($this->verify)($code)->assertNotFound()->json('code'))->toBe('verify_not_found');

    RateLimiter::clear('education-verify:127.0.0.1');
    for ($try = 0; $try < 20; $try++) {
        ($this->verify)('ABCDEFGHJKLM');
    }
    ($this->verify)('ABCDEFGHJKLM')->assertStatus(429);
});

// ── Who may do what ──

it('keeps designs and documents to the people allowed, in their own institution', function () {
    $card = ($this->design)('bd_id_card');
    $issued = ($this->issue)($card, [$this->rahim['id']])->json('data.0');

    $looker = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Looker'));
    $lookerToken = orgToken($looker, $this->w->c1);
    $this->asToken($lookerToken)->getJson(($this->api)('documents'))->assertForbidden();
    $this->asToken($lookerToken)->getJson(($this->api)('document-templates'))->assertForbidden();
    ($this->issue)($card, [$this->rahim['id']], ['replace' => true], $lookerToken)->assertForbidden();

    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education.issue_documents'], 'Clerk'));
    $clerkToken = orgToken($clerk, $this->w->c1);
    $this->asToken($clerkToken)->getJson(($this->api)('document-templates'))->assertOk()->assertJsonPath('meta.can.design', false);
    $this->asToken($clerkToken)->postJson(($this->api)('document-templates/presets/bd_testimonial/apply'))->assertForbidden();
    $this->asToken($clerkToken)->patchJson(($this->api)("document-templates/{$card['id']}"), ['base_version' => $card['version'], 'status' => 'retired'])->assertForbidden();

    // Another institution, another partner, another campus: the same 404 as an unknown id.
    $c2 = createMember($this->w->c2);
    $this->asToken(orgToken($c2, $this->w->c2))->getJson(($this->api)("documents/{$issued['id']}", $this->w->c2))->assertNotFound();
    $this->asToken(orgToken($c2, $this->w->c2))->postJson(($this->api)('documents', $this->w->c2), ['template_id' => $card['id'], 'student_ids' => [$this->rahim['id']]])->assertNotFound();
    $c4 = createMember($this->w->c4);
    $this->asToken(orgToken($c4, $this->w->c4))->getJson(($this->api)('documents', $this->w->c1))->assertNotFound();
    $d1 = createMember($this->w->d1);
    $this->asToken(orgToken($d1, $this->w->d1))->getJson(($this->api)("documents/{$issued['id']}", $this->w->d1))->assertNotFound();

    toggles()->disable($this->w->g1, 'education', 'Test', confirm: true);
    ($this->as)()->getJson(($this->api)('documents'))->assertForbidden();
    expect(Document::count())->toBe(1);
});
