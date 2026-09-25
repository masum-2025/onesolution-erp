<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Modules\Enums\PurgeStatus;
use App\Platform\Modules\Models\ModulePurgeRequest;
use App\Platform\Tenancy\Enums\MembershipType;
use Tests\Fixtures\FakeHrmPurger;

beforeEach(function () {
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->base = "/api/organizations/{$this->w->c1->id}/modules";

    FakeHrmPurger::$purged = [];
    app()->tag([FakeHrmPurger::class], 'module.purgers.hrm');
});

it('records consent and then allows enabling an AI module', function () {
    $this->asToken($this->token)->postJson("{$this->base}/ai_assistant/enable", ['reason' => 'Try the assistant'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'consent_required');

    $this->asToken($this->token)->postJson("{$this->base}/ai_assistant/consent", ['terms_version' => 'dpa-2026.1'])
        ->assertCreated()
        ->assertJsonPath('data.terms_version', 'dpa-2026.1');

    $this->asToken($this->token)->postJson("{$this->base}/ai_assistant/enable", ['reason' => 'Try the assistant'])
        ->assertOk();

    expect(AuditLog::where('action', 'module.consent_granted')->exists())->toBeTrue();
});

it('turns the AI module off when consent is revoked', function () {
    $this->asToken($this->token)->postJson("{$this->base}/ai_assistant/consent", ['terms_version' => 'v1']);
    toggles()->enable($this->w->c1, 'ai_assistant', 'Try the assistant');

    $this->asToken($this->token)->deleteJson("{$this->base}/ai_assistant/consent", ['reason' => 'Legal review pending'])
        ->assertOk();

    expect(resolvedModule('ai_assistant', $this->w->c1)->enabled)->toBeFalse();
    $this->asToken($this->token)->deleteJson("{$this->base}/ai_assistant/consent", ['reason' => 'Again please'])
        ->assertNotFound();
});

it('refuses consent for modules that do not need it', function () {
    $this->asToken($this->token)->postJson("{$this->base}/crm/consent", ['terms_version' => 'v1'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'consent_not_applicable');
});

it('requires the module key to be typed to schedule a purge', function () {
    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'yes', 'reason' => 'Closing HR'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'purge_confirm_mismatch');
});

it('refuses to purge a module that is still on', function () {
    toggles()->enable($this->w->c1, 'hrm', 'Start HR');

    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'hrm', 'reason' => 'Closing HR'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'purge_requires_disabled');
});

it('schedules a purge 7 days ahead and deletes nothing before that', function () {
    $this->freezeSecond();

    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'hrm', 'reason' => 'Closing HR'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.execute_after', now()->addDays(7)->toIso8601String());

    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'hrm', 'reason' => 'Closing HR'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'purge_already_pending');

    $this->travel(6)->days();
    $this->artisan('modules:purge-due')->assertSuccessful();

    expect(FakeHrmPurger::$purged)->toBe([]);
});

it('executes the purge after the waiting period and audits it', function () {
    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'hrm', 'reason' => 'Closing HR']);

    $this->travel(7)->days();
    $this->artisan('modules:purge-due')->assertSuccessful();

    expect(FakeHrmPurger::$purged)->toBe([$this->w->c1->id])
        ->and(ModulePurgeRequest::sole()->status)->toBe(PurgeStatus::Done)
        ->and(AuditLog::where('action', 'module.purge_executed')->sole()->new_values['deleted'])->toBe([FakeHrmPurger::class => 3]);
});

it('can be cancelled before it runs', function () {
    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'hrm', 'reason' => 'Closing HR']);

    $this->asToken($this->token)->deleteJson("{$this->base}/hrm/purge", ['reason' => 'Changed our mind'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->travel(8)->days();
    $this->artisan('modules:purge-due');

    expect(FakeHrmPurger::$purged)->toBe([]);
});

it('does not purge if the module was turned on again', function () {
    $this->asToken($this->token)->postJson("{$this->base}/hrm/purge", ['confirm_text' => 'hrm', 'reason' => 'Closing HR']);
    toggles()->enable($this->w->c1, 'hrm', 'Reopened HR');

    $this->travel(7)->days();
    $this->artisan('modules:purge-due');

    expect(FakeHrmPurger::$purged)->toBe([])
        ->and(ModulePurgeRequest::sole()->status)->toBe(PurgeStatus::Cancelled);
});
