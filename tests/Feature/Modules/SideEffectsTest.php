<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Events\ModuleEnabled;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
});

function integrationToken(Organization $organization, $user): PersonalAccessToken
{
    $token = $user->createToken('integration:erp-sync', ['*'], now()->addYear());
    $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

    return $token->accessToken;
}

it('revokes integration tokens when api_integration is turned off', function () {
    toggles()->enable($this->w->c1, 'api_integration', 'Connect our website');
    $companyToken = integrationToken($this->w->c1, $this->owner);
    $branchToken = integrationToken($this->w->b1, $this->owner);
    $sessionToken = orgToken($this->owner, $this->w->c1);

    toggles()->disable($this->w->c1, 'api_integration', 'Integration contract ended');

    expect(PersonalAccessToken::find($companyToken->id))->toBeNull()
        ->and(PersonalAccessToken::find($branchToken->id))->toBeNull()
        ->and(PersonalAccessToken::findToken($sessionToken))->not->toBeNull()
        ->and(AuditLog::where('action', 'module.integration_tokens_revoked')->count())->toBe(2);
});

it('keeps tokens where a branch still has api_integration on', function () {
    toggles()->enable($this->w->c1, 'api_integration', 'Connect our website');
    toggles()->enable($this->w->b1, 'api_integration', 'Branch keeps its kiosk');
    $branchToken = integrationToken($this->w->b1, $this->owner);

    toggles()->disable($this->w->c1, 'api_integration', 'Company stops');

    expect(PersonalAccessToken::find($branchToken->id))->not->toBeNull();
});

it('does not touch integration tokens of other companies', function () {
    toggles()->enable($this->w->c1, 'api_integration', 'Connect');
    toggles()->enable($this->w->c2, 'api_integration', 'Connect');
    $otherToken = integrationToken($this->w->c2, $this->owner);

    toggles()->disable($this->w->c1, 'api_integration', 'Stop');

    expect(PersonalAccessToken::find($otherToken->id))->not->toBeNull();
});

it('fires events only for modules whose state really changed', function () {
    Event::fake([ModuleEnabled::class, ModuleDisabled::class]);

    toggles()->enable($this->w->c1, 'external_integrations', 'Connect webhooks');
    Event::assertDispatchedTimes(ModuleEnabled::class, 2);

    toggles()->disable($this->w->c1, 'api_integration', 'Stop all integrations', confirm: true);
    Event::assertDispatched(ModuleDisabled::class, fn ($e) => $e->moduleKey === 'api_integration');
    Event::assertDispatched(ModuleDisabled::class, fn ($e) => $e->moduleKey === 'external_integrations');

    // Disabling again changes nothing, so no new events.
    toggles()->disable($this->w->c1, 'api_integration', 'Still stopped');
    Event::assertDispatchedTimes(ModuleDisabled::class, 2);
});
