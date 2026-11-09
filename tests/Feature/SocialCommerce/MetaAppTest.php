<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Meta\MetaApps;
use App\Platform\Meta\Models\MetaApp;
use App\Platform\Meta\SignedRequest;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\SocialCommerce\Models\SocialPage;

/*
 * SC-1: a partner's own Meta app in the partner console: the id and secret
 * are checked with Meta, wait for a second owner (or the single-approver
 * wait), and the secret never comes back. Meta's callbacks (a person
 * removed the app, asked for deletion) need Meta's signature.
 */

const PARTNER_SECRET = 'fedcba9876543210fedcba9876543210';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 04:00:00', 'UTC'));
    $this->w = tenancyWorld();
    $this->ownerUser = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $this->owner = partnerToken($this->ownerUser, $this->w->partnerA);
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/555555555') && $request['access_token'] === '555555555|'.PARTNER_SECRET) {
            return Http::response(['name' => 'Partner A Shop', 'id' => '555555555']);
        }

        return Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 400);
    });
    $this->save = fn (array $body = [], ?string $token = null) => $this->asToken($token ?? $this->owner)->putJson('/api/partner/meta-app', [
        'app_id' => '555555555', 'app_secret' => PARTNER_SECRET, 'password' => 'password', ...$body,
    ]);
});

it('checks the app with Meta, waits for a second owner, and never shows the secret', function () {
    $secondUser = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $second = partnerToken($secondUser, $this->w->partnerA);

    ($this->save)(['app_secret' => 'wrongwrongwrongwrong'])->assertUnprocessable()->assertJsonPath('code', 'app_check_failed');
    ($this->save)(['password' => 'nope'])->assertUnprocessable();

    $saved = ($this->save)()->assertOk();
    expect($saved->json('data'))->toMatchArray(['status' => 'pending', 'app_id' => null])
        ->and($saved->json('data.pending'))->toMatchArray(['app_id' => '555555555', 'app_name' => 'Partner A Shop', 'mine' => true, 'activates_at' => null])
        ->and($saved->getContent())->not->toContain(PARTNER_SECRET);

    $version = $saved->json('data.version');
    $this->asToken($this->owner)->postJson('/api/partner/meta-app/approve', ['password' => 'password', 'base_version' => $version])->assertForbidden()->assertJsonPath('code', 'own_change');
    $approved = $this->asToken($second)->postJson('/api/partner/meta-app/approve', ['password' => 'password', 'base_version' => $version])->assertOk();
    expect($approved->json('data'))->toMatchArray(['status' => 'active', 'app_id' => '555555555', 'app_name' => 'Partner A Shop', 'secret_hint' => '••••3210', 'pending' => null])
        ->and($approved->json('data.callbacks.deauthorize'))->toEndWith('/api/meta/'.MetaApp::query()->value('id').'/deauthorize')
        ->and($approved->getContent())->not->toContain(PARTNER_SECRET)
        ->and(MetaApp::query()->first()->app_secret)->toBe(PARTNER_SECRET)
        ->and(AuditLog::whereIn('action', ['meta.app_change_requested', 'meta.app_changed'])->count())->toBe(2);
});

it('applies the change of a single owner after the waiting time', function () {
    $saved = ($this->save)()->assertOk()->json('data');
    expect($saved['pending']['activates_at'])->toBe(now()->addHours(24)->toIso8601String());

    expect(app(MetaApps::class)->applyDue())->toBe([]);
    $this->travel(25)->hours();
    expect(app(MetaApps::class)->applyDue())->toHaveCount(1)
        ->and(MetaApp::query()->first()->isActive())->toBeTrue()
        ->and(AuditLog::where('action', 'meta.app_changed_after_wait')->count())->toBe(1);
});

it('lets a partner owner drop a waiting change, and keeps other roles and partners out', function () {
    $saved = ($this->save)()->json('data');
    $support = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA);
    $this->asToken($support)->getJson('/api/partner/meta-app')->assertForbidden();
    ($this->save)([], $support)->assertForbidden();

    $otherPartner = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $this->asToken($otherPartner)->getJson('/api/partner/meta-app')->assertOk()->assertJsonPath('data.exists', false);
    $this->asToken($otherPartner)->postJson('/api/partner/meta-app/reject', ['base_version' => $saved['version']])->assertStatus(409)->assertJsonPath('code', 'nothing_pending');

    $this->asToken($this->owner)->postJson('/api/partner/meta-app/reject', ['base_version' => $saved['version']])->assertOk()->assertJsonPath('data.pending', null);
    ($this->save)(['unknown' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('unknown');
});

it('stops or forgets the pages of a person who removed the app, only on Meta\'s signature', function () {
    $app = new MetaApp;
    $app->forceFill(['partner_id' => $this->w->partnerA->id, 'app_id' => '555555555', 'app_secret' => PARTNER_SECRET, 'app_name' => 'Partner A Shop', 'status' => MetaApp::ACTIVE, 'version' => 1])->save();
    $page = SocialPage::inTenantOf($this->w->c1)->create([
        'organization_id' => $this->w->c1->id, 'meta_app_id' => $app->id, 'page_id' => '111', 'name' => 'BBU Shop', 'token' => 'PAGE_TOKEN',
        'meta_person_id' => 'PERSON1', 'can_post' => true, 'status' => SocialPage::CONNECTED, 'version' => 1,
    ]);
    $signed = app(SignedRequest::class);

    $this->post("/api/meta/{$app->id}/deauthorize", ['signed_request' => 'bad.'.base64_encode('{"user_id":"PERSON1"}')])->assertStatus(400);
    $this->post("/api/meta/{$app->id}/deauthorize", ['signed_request' => $signed->make($app, ['user_id' => 'SOMEONE_ELSE'])])->assertOk();
    expect($page->fresh()->status)->toBe('connected');

    $this->post("/api/meta/{$app->id}/deauthorize", ['signed_request' => $signed->make($app, ['user_id' => 'PERSON1'])])->assertOk();
    expect($page->fresh()->status)->toBe('needs_reconnect');

    $deleted = $this->post("/api/meta/{$app->id}/data-deletion", ['signed_request' => $signed->make($app, ['user_id' => 'PERSON1'])])->assertOk();
    $fresh = SocialPage::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->find($page->id);
    expect($fresh->token)->toBeNull()->and($fresh->status)->toBe('disconnected')->and($fresh->meta_person_id)->toBeNull();
    $this->getJson('/api/meta/deletion/'.$deleted->json('confirmation_code'))->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.confirmation_code', $deleted->json('confirmation_code'));
});
