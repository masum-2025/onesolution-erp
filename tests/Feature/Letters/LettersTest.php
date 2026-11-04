<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Models\Letter;

/*
 * LET-1: HR letters from designs. Every {placeholder} is a known field
 * (HRM's employee, Payroll's pay, the letter's own, the design's inputs);
 * letters are numbered (rule letters.number_format), kept as issued and
 * revoked with a reason; salary only where the company allows it (rule
 * letters.salary_fields_enabled) and for people with letters.salary;
 * employees see the letters shared with them, in the app and the portal.
 */

beforeEach(function () {
    Queue::fake(); // keep messages queued: a sent message's values are wiped after delivery
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'payroll', 'Test setup');
        toggles()->enable($group, 'letters', 'Test setup');
    }
    $role = fn (array $permissions, string $name) => orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name)), $this->w->c1);
    $this->hr = $role(['hrm.view', 'letters.view', 'letters.design', 'letters.issue', 'letters.salary'], 'HR head');
    $this->clerk = $role(['hrm.view', 'letters.view', 'letters.design', 'letters.issue'], 'HR clerk');
    $this->viewer = $role(['letters.view'], 'Letters viewer');
    $payroll = $role(['hrm.view', 'payroll.view', 'payroll.run'], 'Payroll clerk');
    $this->workerUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['attendance.punch'], 'Worker'));
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/letters/{$path}";

    $position = $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/hrm/positions", ['title' => ['en' => 'Senior Accountant', 'bn' => 'সিনিয়র হিসাবরক্ষক']])
        ->assertCreated()->json('data');
    $hire = fn (string $name, $unit) => hireVia($this, $this->w->token, $unit, ['full_name' => $name, 'joined_on' => '2025-03-01', 'position_id' => $position['id']])->assertCreated()->json('data');
    $this->rahima = $hire('Rahima Akter', $this->w->b1);
    $this->selim = $hire('Selim Reza', $this->w->b1);
    $this->asToken($this->w->token)->putJson("/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->rahima['id']}/login", ['base_version' => $this->rahima['version'], 'user_id' => $this->workerUser->id])->assertOk();

    $pay = fn (string $path) => "/api/organizations/{$this->w->c1->id}/payroll/{$path}";
    $structure = $this->asToken($payroll)->postJson($pay('structures'), ['code' => 'PLAIN', 'name' => ['en' => 'Basic only'], 'items' => []])->assertCreated()->json('data');
    $this->asToken($payroll)->postJson($pay("employees/{$this->rahima['id']}/salary"), ['structure_id' => $structure['id'], 'basic_minor' => 3000000, 'effective_from' => '2025-03-01'])->assertCreated();

    $this->preset = fn (string $key, ?string $token = null) => $this->asToken($token ?? $this->hr)->postJson(($this->api)("templates/presets/{$key}"));
    $this->activate = fn (array $template, ?string $token = null) => $this->asToken($token ?? $this->hr)
        ->patchJson(($this->api)("templates/{$template['id']}"), ['base_version' => $template['version'], 'status' => 'active']);
    $this->issue = fn (array $template, array $people, array $data = [], ?string $token = null) => $this->asToken($token ?? $this->hr)->postJson(($this->api)('issued'), [
        'template_id' => $template['id'], 'employee_ids' => array_column($people, 'id'), 'locale' => 'en', 'op_id' => 'op-'.bin2hex(random_bytes(6)), ...$data,
    ]);
    $this->text = fn (array $letter) => collect($letter['content']['blocks'])->map(fn ($block) => $block['text'] ?? implode(' ', $block['items'] ?? []))->implode("\n");
});

it('adds a ready-made design, issues numbered letters to several people and keeps them as issued', function () {
    $options = $this->asToken($this->hr)->getJson(($this->api)('options'))->assertOk()->json('data');
    expect(collect($options['presets'])->pluck('key'))->toContain('confirmation', 'increment', 'experience')
        ->and(collect($options['fields'])->pluck('group')->all())->toBe(['letter', 'employee', 'pay', 'increment']);

    $template = ($this->preset)('confirmation')->assertCreated()->json('data');
    expect($template)->toMatchArray(['status' => 'draft', 'code' => 'CNF', 'kind' => 'confirmation', 'uses_salary' => false])
        ->and($template['locales'])->toBe(['en', 'bn']);
    // A second time gives the same design.
    expect(($this->preset)('confirmation')->assertOk()->json('data.id'))->toBe($template['id']);
    // A draft is in the designers' bell, not anyone else's.
    $this->asToken($this->hr)->getJson('/api/attention')->assertOk()->assertJsonFragment(['key' => 'letters.drafts', 'count' => 1]);
    expect(collect($this->asToken($this->viewer)->getJson('/api/attention')->json('data'))->pluck('key'))->not->toContain('letters.drafts');

    ($this->issue)($template, [$this->rahima], ['inputs' => ['effective_date' => '2026-12-01']])->assertConflict()->assertJsonPath('code', 'template_not_active');
    $template = ($this->activate)($template)->assertOk()->json('data');
    ($this->issue)($template, [$this->rahima])->assertUnprocessable()->assertJsonValidationErrors('inputs.effective_date');

    $data = ['inputs' => ['effective_date' => '2026-12-01'], 'op_id' => 'confirm-batch-1'];
    $issued = ($this->issue)($template, [$this->rahima, $this->selim], $data)->assertCreated()->json('data');
    expect(collect($issued)->pluck('number')->sort()->values()->all())->toBe(['CNF/2026/0001', 'CNF/2026/0002']);
    // The same op_id (a retry) gives the same letters.
    expect(collect(($this->issue)($template, [$this->rahima, $this->selim], $data)->assertCreated()->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect($issued)->pluck('id')->sort()->values()->all());

    $rahimas = collect($issued)->firstWhere('employee.name', 'Rahima Akter');
    $letter = $this->asToken($this->viewer)->getJson(($this->api)("issued/{$rahimas['id']}"))->assertOk()->json('data');
    expect(($this->text)($letter))->toContain('Rahima Akter')->toContain('Senior Accountant')->toContain('December 1, 2026')
        ->toContain($rahimas['number'])->not->toContain('{');
    // The dashboard counts this month's letters for people who see letters.
    expect($this->asToken($this->viewer)->getJson('/api/modules/letters/dashboard/issued')->assertOk()->json('data.value'))->toBe(2);
    $this->asToken(orgToken($this->workerUser, $this->w->c1))->getJson('/api/modules/letters/dashboard/issued')->assertForbidden();

    // Changing the design never changes a letter already issued.
    $template = $this->asToken($this->hr)->getJson(($this->api)("templates/{$template['id']}"))->json('data');
    $bodies = $template['bodies'];
    $bodies['en'][4]['text'] = 'Changed wording for {employee.name}.';
    $this->asToken($this->hr)->patchJson(($this->api)("templates/{$template['id']}"), ['base_version' => $template['version'], 'bodies' => $bodies])->assertOk();
    expect(($this->text)($this->asToken($this->viewer)->getJson(($this->api)("issued/{$rahimas['id']}"))->json('data')))->not->toContain('Changed wording');

    // Rahima (with a login) is told without the content and sees her own letter; Selim has no login.
    $told = NotificationDelivery::query()->where('notification_key', 'letters.issued')->get();
    expect($told)->toHaveCount(1)->and($told[0]->user_id)->toBe($this->workerUser->id)
        ->and(AuditLog::query()->where('action', 'letters.issued')->count())->toBe(2);
    $worker = orgToken($this->workerUser, $this->w->c1);
    $mine = $this->asToken($worker)->getJson(($this->api)('me'))->assertOk()->json('data');
    expect(collect($mine)->pluck('id')->all())->toBe([$rahimas['id']]);
    $this->asToken($worker)->getJson(($this->api)('me/'.collect($issued)->firstWhere('employee.name', 'Selim Reza')['id']))->assertNotFound();
    $this->asToken($worker)->getJson(($this->api)('issued'))->assertForbidden();

    // The rule can hide letters from employees.
    trustedOrgRule($this->w->c1, 'letters.employees_see_own', false);
    expect($this->asToken($worker)->getJson(($this->api)('me'))->json('data'))->toBe([]);
});

it('refuses fields it does not know and names the people who lack a value', function () {
    $body = fn (string $text) => ['en' => [['type' => 'paragraph', 'text' => $text]]];
    $make = fn (array $data) => $this->asToken($this->hr)->postJson(($this->api)('templates'), [
        'name' => ['en' => 'Note'], 'kind' => 'other', 'code' => 'NOTE', ...$data,
    ]);
    $make(['bodies' => $body('Dear {employee.nmae},')])->assertUnprocessable()->assertJsonValidationErrors('bodies.en.0');
    $make(['bodies' => $body('Hello'), 'code' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $make(['bodies' => ['bn' => [['type' => 'paragraph', 'text' => 'শুধু বাংলা']]]])->assertUnprocessable()->assertJsonValidationErrors('bodies');
    $make(['bodies' => ['en' => [['type' => 'html', 'text' => '<b>x</b>']]]])->assertUnprocessable()->assertJsonValidationErrors('bodies.en.0');
    $make(['bodies' => $body('Hi {input.reason}')])->assertUnprocessable()->assertJsonValidationErrors('bodies.en.0');

    $template = $make(['bodies' => $body('{employee.name} leaves on {employee.exits_on}. {input.note}'),
        'inputs' => [['key' => 'note', 'type' => 'text', 'required' => false, 'label' => ['en' => 'Note']]]])->assertCreated()->json('data');
    $template = ($this->activate)($template)->assertOk()->json('data');
    ($this->issue)($template, [$this->rahima, $this->selim])->assertUnprocessable()->assertJsonPath('code', 'missing_values')
        ->assertJsonFragment(['code' => 'missing_values']);
    expect(($this->issue)($template, [$this->rahima])->json('message'))->toContain('Rahima Akter')->toContain('Last working day');
    expect(Letter::query()->count())->toBe(0);

    // Someone from another unit's subtree cannot be chosen there.
    $other = hireVia($this, $this->w->token, $this->w->c1, ['full_name' => 'Head Office Person'])->assertCreated()->json('data');
    $this->asToken($this->hr)->postJson(($this->api)('issued', $this->w->b1), ['template_id' => $template['id'], 'employee_ids' => [$other['id']], 'locale' => 'en', 'op_id' => 'op-other-unit'])
        ->assertUnprocessable()->assertJsonValidationErrors('employee_ids');
});

it('keeps salary to people allowed to use it and to companies that allow it', function () {
    $options = $this->asToken($this->clerk)->getJson(($this->api)('options'))->assertOk()->json('data');
    expect(collect($options['fields'])->pluck('group')->all())->not->toContain('pay')->not->toContain('increment')
        ->and($options['salary'])->toBe(['enabled' => true, 'allowed' => false]);
    ($this->preset)('salary_certificate', $this->clerk)->assertForbidden()->assertJsonPath('code', 'salary_not_allowed');

    $template = ($this->activate)(($this->preset)('salary_certificate')->assertCreated()->json('data'))->assertOk()->json('data');
    expect($template['uses_salary'])->toBeTrue();
    ($this->issue)($template, [$this->rahima], ['inputs' => ['purpose' => 'a bank loan']], $this->clerk)->assertForbidden()->assertJsonPath('code', 'salary_not_allowed');
    // Selim has no salary: named, nothing issued.
    ($this->issue)($template, [$this->selim], ['inputs' => ['purpose' => 'a bank loan']])->assertUnprocessable()->assertJsonPath('code', 'missing_values');

    $letter = ($this->issue)($template, [$this->rahima], ['inputs' => ['purpose' => 'a bank loan']])->assertCreated()->json('data.0');
    $full = $this->asToken($this->hr)->getJson(($this->api)("issued/{$letter['id']}"))->assertOk()->json('data');
    $table = collect($full['content']['blocks'])->firstWhere('type', 'table');
    expect($table['rows'][0])->toBe(['Basic salary', 'BDT 30,000.00']);
    // Listed for others, but not opened.
    expect(collect($this->asToken($this->clerk)->getJson(($this->api)('issued'))->json('data'))->pluck('uses_salary')->all())->toBe([true]);
    $this->asToken($this->viewer)->getJson(($this->api)("issued/{$letter['id']}"))->assertForbidden();

    // Switched off for the company: no salary letters at all.
    trustedOrgRule($this->w->c1, 'letters.salary_fields_enabled', false);
    ($this->issue)($template, [$this->rahima], ['inputs' => ['purpose' => 'a visa']])->assertForbidden()->assertJsonPath('code', 'salary_off');
    expect(collect($this->asToken($this->hr)->getJson(($this->api)('options'))->json('data.fields'))->pluck('group')->all())->not->toContain('pay')->not->toContain('increment');
});

it('revokes with a reason and keeps letters to the unit, the company and the module being on', function () {
    $template = ($this->activate)(($this->preset)('transfer')->assertCreated()->json('data'))->assertOk()->json('data');
    $letter = ($this->issue)($template, [$this->rahima], ['inputs' => ['new_place' => 'Chattogram branch', 'effective_date' => '2026-12-01']])->assertCreated()->json('data.0');

    $this->asToken($this->viewer)->postJson(($this->api)("issued/{$letter['id']}/revoke"), ['reason' => 'Issued by mistake'])->assertForbidden();
    $this->asToken($this->hr)->postJson(($this->api)("issued/{$letter['id']}/revoke"), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->hr)->postJson(($this->api)("issued/{$letter['id']}/revoke"), ['reason' => 'Issued by mistake', 'extra' => 1])->assertUnprocessable();
    $revoked = $this->asToken($this->hr)->postJson(($this->api)("issued/{$letter['id']}/revoke"), ['reason' => 'Issued by mistake'])->assertOk()->json('data');
    expect($revoked)->toMatchArray(['revoked' => true, 'revoke_reason' => 'Issued by mistake']);
    $this->asToken($this->hr)->postJson(($this->api)("issued/{$letter['id']}/revoke"), ['reason' => 'Again please'])->assertConflict()->assertJsonPath('code', 'already_revoked');
    expect(AuditLog::query()->where('action', 'letters.revoked')->count())->toBe(1)
        ->and($this->asToken(orgToken($this->workerUser, $this->w->c1))->getJson(($this->api)('me'))->json('data'))->toBe([])
        ->and($this->asToken($this->hr)->getJson(($this->api)('issued').'?status=revoked')->json('meta.total'))->toBe(1);

    // Seen from the branch it belongs to, not from a department below it.
    $this->asToken($this->w->token)->getJson(($this->api)("issued/{$letter['id']}", $this->w->b1))->assertOk();
    $this->asToken($this->w->token)->getJson(($this->api)("issued/{$letter['id']}", $this->w->d1))->assertNotFound();

    // Another company (and another client) never reaches it.
    $c2 = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($c2)->getJson(($this->api)("issued/{$letter['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($c2)->getJson(($this->api)("templates/{$template['id']}", $this->w->c2))->assertNotFound();
    expect($this->asToken($c2)->getJson(($this->api)('issued'))->status())->toBeIn([403, 404]);
    $other = orgToken(createMember($this->w->c3), $this->w->c3);
    expect($this->asToken($other)->getJson(($this->api)("issued/{$letter['id']}"))->status())->toBeIn([403, 404]);

    // Off: 403, letters kept.
    toggles()->disable($this->w->g1, 'letters', 'Not used');
    $this->asToken($this->hr)->getJson(($this->api)('issued'))->assertForbidden();
    expect(Letter::query()->count())->toBe(1);
});

it('keeps letter images private behind short-lived links', function () {
    Storage::fake('local');
    $this->asToken($this->hr)->postJson(($this->api)('assets'), ['kind' => 'signature', 'name' => 'MD', 'file' => UploadedFile::fake()->create('sign.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonPath('code', 'bad_asset');
    $this->asToken($this->viewer)->postJson(($this->api)('assets'), ['kind' => 'signature', 'name' => 'MD', 'file' => UploadedFile::fake()->image('sign.png', 300, 100)])->assertForbidden();
    $asset = $this->asToken($this->hr)->postJson(($this->api)('assets'), ['kind' => 'signature', 'name' => 'MD', 'file' => UploadedFile::fake()->image('sign.png', 300, 100)])
        ->assertCreated()->json('data');
    $this->get($asset['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(strtok($asset['url'], '?'))->assertForbidden();

    // A signature block uses it; a stamp id that is a signature is refused.
    $bodies = ['en' => [['type' => 'paragraph', 'text' => 'Hello {employee.name}'], ['type' => 'signature', 'name' => 'Managing Director', 'title' => '', 'image_id' => $asset['id']]]];
    $template = $this->asToken($this->hr)->postJson(($this->api)('templates'), ['name' => ['en' => 'Signed'], 'kind' => 'other', 'code' => 'SIG', 'bodies' => $bodies])
        ->assertCreated()->json('data');
    expect($template['images'])->toHaveKey($asset['id']);
    $bodies['en'][1]['stamp_id'] = $asset['id'];
    $this->asToken($this->hr)->postJson(($this->api)('templates'), ['name' => ['en' => 'Signed'], 'kind' => 'other', 'code' => 'SIG', 'bodies' => $bodies])
        ->assertUnprocessable()->assertJsonValidationErrors('bodies.en.1');
});

it('shows a portal employee their own letters only', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $template = ($this->activate)(($this->preset)('transfer')->assertCreated()->json('data'))->assertOk()->json('data');
    $inputs = ['inputs' => ['new_place' => 'Sylhet branch', 'effective_date' => '2026-12-01']];
    $issued = ($this->issue)($template, [$this->rahima, $this->selim], $inputs)->assertCreated()->json('data');
    ($this->issue)($template, [$this->rahima], [...$inputs, 'shared' => false])->assertCreated();

    $member = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $member, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $member->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $member->id,
        'subject_type' => 'hrm.employee', 'subject_id' => $this->rahima['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($member, $this->w->c1);

    $mine = $this->asToken($portal)->getJson('/api/portal/letters')->assertOk()->json('data');
    $rahimas = collect($issued)->firstWhere('employee.name', 'Rahima Akter');
    expect($mine)->toHaveCount(1)->and(collect($mine[0]['letters'])->pluck('id')->all())->toBe([$rahimas['id']]);
    $this->asToken($portal)->getJson("/api/portal/letters/{$rahimas['id']}")->assertOk()->assertJsonPath('data.number', $rahimas['number']);
    $this->asToken($portal)->getJson('/api/portal/letters/'.collect($issued)->firstWhere('employee.name', 'Selim Reza')['id'])->assertNotFound();
    $this->asToken($portal)->getJson(($this->api)('issued'))->assertForbidden();

    toggles()->disable($this->w->g1, 'client_portal', 'Not used');
    $this->asToken($portal)->getJson('/api/portal/letters')->assertForbidden();
});
