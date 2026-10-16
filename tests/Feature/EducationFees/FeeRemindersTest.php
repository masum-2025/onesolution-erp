<?php

use App\Models\User;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Modules\EducationFees\Models\Reminder;

/*
 * FEE-4b: reminders to families. Before and after due dates under the rule
 * (off by default, at the institution's hour, once per bill and step); the
 * office's reminder of dues (once a day); money received when asked for.
 * Guardians without a login get SMS or mail on the contact kept on the
 * student's record (address encrypted until sent, then removed); linked
 * portal members get theirs by login.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-05 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    platformRule('notifications.sms_enabled', true);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
        toggles()->enable($group, 'education_fees', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->fees = fn (string $path) => "/api/organizations/{$this->w->c1->id}/fees/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->school = eduSchool($this);
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => [['name' => 'Abdul', 'phone' => '01711-000001', 'relation' => 'father']]])->assertCreated()->json('data');
    $tuition = ($this->as)()->postJson(($this->fees)('heads'), ['code' => 'TUI', 'name' => ['en' => 'Tuition'], 'frequency' => 'monthly', 'income_key' => 'education_fees.tuition_income'])->json('data');
    $structure = ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'Fees', 'session_id' => $this->school->session['id'], 'lines' => [['head_id' => $tuition['id'], 'amount_minor' => 150000]]])->json('data');
    ($this->as)()->postJson(($this->fees)("structures/{$structure['id']}/activate"), ['base_version' => $structure['version']])->assertOk();
    $run = ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'monthly', 'session_id' => $this->school->session['id'], 'period' => '2026-01', 'issue_date' => '2026-01-01'])->json('data');
    ($this->as)()->postJson(($this->fees)("runs/{$run['id']}/finalize"), ['base_version' => $run['version']])->assertOk();
    $this->sms = fn (string $key) => NotificationDelivery::query()->where('notification_key', $key)->where('channel', 'sms')->get();
});

it('reminds before and after the due date at the institution\'s hour, once per step, only when the rule is on', function () {
    // Due 10 January; off by default.
    $this->travelTo(CarbonImmutable::parse('2026-01-07 04:05:00', 'UTC'));
    $this->artisan('education-fees:remind', ['--now' => true])->assertSuccessful();
    expect(NotificationDelivery::query()->count())->toBe(0);

    orgRule($this->w->c1, 'education_fees.reminders', ['enabled' => true, 'before_days' => [3], 'after_days' => [1, 7], 'hour' => 10, 'channels' => ['sms']]);
    // 04:05 UTC is 10:05 in Dhaka: the hour. Three days before: "due soon" to the guardian's phone.
    $this->artisan('education-fees:remind')->assertSuccessful();
    $this->artisan('education-fees:remind')->assertSuccessful();
    $soon = ($this->sms)('education_fees.due_soon');
    expect($soon)->toHaveCount(1)->and($soon[0])->toMatchArray(['status' => 'sent', 'user_id' => null, 'recipient' => '+88017******01'])
        ->and($soon[0]->address)->toBeNull()->and($soon[0]->data)->toBeNull();

    // Another hour: nothing. A day after the due date: "overdue".
    $this->travelTo(CarbonImmutable::parse('2026-01-11 08:00:00', 'UTC'));
    $this->artisan('education-fees:remind')->assertSuccessful();
    expect(($this->sms)('education_fees.overdue'))->toHaveCount(0);
    $this->travelTo(CarbonImmutable::parse('2026-01-11 04:00:00', 'UTC'));
    $this->artisan('education-fees:remind')->assertSuccessful();
    expect(($this->sms)('education_fees.overdue'))->toHaveCount(1)
        ->and(Reminder::query()->withoutGlobalScopes()->pluck('kind')->sort()->values()->all())->toBe(['after_1', 'before_3']);

    // Paid: no more reminders.
    $this->token = orgToken($this->owner, $this->w->c1);
    ($this->as)()->postJson(($this->fees)('receipts'), ['student_id' => $this->rahim['id'], 'amount_minor' => 150000, 'method' => 'cash', 'received_on' => '2026-01-11'])->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-01-17 04:00:00', 'UTC'));
    $this->artisan('education-fees:remind')->assertSuccessful();
    expect(($this->sms)('education_fees.overdue'))->toHaveCount(1);
});

it('tells linked portal members by their login, and the office reminds once a day', function () {
    $parent = User::factory()->create(['email' => 'mother@example.com', 'email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $parent, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $parent->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $parent->id,
        'subject_type' => 'education.student', 'subject_id' => $this->rahim['id'], 'relation' => 'mother', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => [['name' => 'Rafiq', 'phone' => '01711-000002', 'relation' => 'father']]])->assertCreated()->json('data');

    $sent = ($this->as)()->postJson(($this->fees)('reminders'), ['student_ids' => [$this->rahim['id'], $karim['id']]])->assertOk()->json('data');
    // Karim was admitted after January was billed: nothing to remind.
    expect($sent)->toBe(['reminded' => 1, 'skipped' => 1]);
    $deliveries = NotificationDelivery::query()->where('notification_key', 'education_fees.dues_reminder')->get();
    expect($deliveries->pluck('channel')->sort()->values()->all())->toBe(['mail', 'sms'])
        ->and($deliveries->firstWhere('channel', 'mail')->user_id)->toBe($parent->id);
    // Once a day.
    ($this->as)()->postJson(($this->fees)('reminders'), ['student_ids' => [$this->rahim['id']]])->assertOk()->assertJsonPath('data', ['reminded' => 0, 'skipped' => 1]);

    // Someone who only looks cannot send them.
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education_fees.view', 'education.view']));
    ($this->as)(orgToken($viewer, $this->w->c1))->postJson(($this->fees)('reminders'), ['student_ids' => [$this->rahim['id']]])->assertForbidden();
});

it('tells the family money was received only when the institution asks for it', function () {
    $pay = fn (int $amount) => ($this->as)()->postJson(($this->fees)('receipts'), ['student_id' => $this->rahim['id'], 'amount_minor' => $amount, 'method' => 'cash', 'received_on' => '2026-01-05'])->assertCreated();
    $pay(50000);
    expect(($this->sms)('education_fees.payment_received'))->toHaveCount(0);
    orgRule($this->w->c1, 'education_fees.notify_payment', true);
    $pay(50000);
    expect(($this->sms)('education_fees.payment_received'))->toHaveCount(1);
});
