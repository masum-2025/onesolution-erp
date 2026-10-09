<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Exceptions\LocalizationException;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\Models\Language;
use App\Platform\Localization\Models\TranslationOverride;
use App\Platform\Localization\ScopeChain;
use App\Platform\Localization\Services\TranslationService;
use App\Platform\Localization\TranslationOverlay;
use App\Platform\Localization\TranslationScope;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;

/*
 * LANG-1: languages and wording in the database, on top of the files.
 * Platform -> partner -> group -> company, the nearest level winning;
 * groups and companies only while multi_language is on and the partner
 * allows it. Plain text, known keys, known placeholders. Browser texts are
 * cached under a hash: no wording, no request.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'multi_language', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
});

function wording(object $test, string $token, string $organizationId, array $body)
{
    return $test->asToken($token)->putJson("http://localhost/api/organizations/{$organizationId}/translations", $body);
}

function houseOwner(): object
{
    $house = Partner::query()->where('is_house', true)->first() ?? Partner::factory()->create(['name' => 'House', 'is_house' => true]);
    $owner = createPartnerStaff($house, PartnerUserRole::Owner);

    return (object) ['partner' => $house, 'user' => $owner, 'token' => partnerToken($owner, $house)];
}

function platformText(string $locale, string $key, string $value, Channel $channel = Channel::Ui): void
{
    app(TranslationService::class)->save(TranslationScope::platform(), $channel, $locale, $key, $value, houseOwner()->user);
}

/** The browser's texts for a context: what /api/me points at. */
function bundleFor(object $test, string $token, string $locale): array
{
    $me = $test->asToken($token)->getJson('http://localhost/api/me')->assertOk();
    $hash = $me->json('data.i18n.hash');

    return $test->asToken($token)->getJson("http://localhost/api/i18n/{$hash}/{$locale}")->assertOk()->json('data');
}

// ── Saving wording ──

it('lets a company word a text its own way, audited, and shows it to its people', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'Keep it'])
        ->assertOk()
        ->assertJsonPath('data.own', 'Keep it');

    expect(bundleFor($this, $this->token, 'en'))->toBe(['core.actions.save' => 'Keep it'])
        ->and(AuditLog::where('action', 'i18n.text_saved')->sole())
        ->organization_id->toBe($this->w->c1->id)
        ->new_values->toMatchArray(['key' => 'core.actions.save', 'value' => 'Keep it', 'level' => 'organization']);
});

it('refuses unknown keys, markup and placeholders the original does not have', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.no_such_text', 'value' => 'x'])
        ->assertStatus(422)->assertJsonPath('code', 'unknown_key');
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => '<img src=x onerror=alert(1)>'])
        ->assertStatus(422)->assertJsonPath('code', 'markup');
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.mode.minutes_left_other', 'value' => '{minutes} min, {secret}'])
        ->assertStatus(422)->assertJsonPath('code', 'unknown_placeholders')->assertJsonPath('placeholders', ['secret']);
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'x', 'organization_id' => $this->w->c2->id])
        ->assertStatus(422);

    expect(TranslationOverride::count())->toBe(0);
});

it('takes plural forms a language needs beyond English', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.mode.minutes_left_few', 'value' => '{minutes} minutes (few) left'])
        ->assertOk();
});

it('resets a text back to what the levels above say', function () {
    platformText('en', 'core.actions.save', 'Save (platform)');
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'Keep it'])->assertOk();

    $this->asToken($this->token)->postJson("http://localhost/api/organizations/{$this->w->c1->id}/translations/reset", ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save'])
        ->assertOk();

    expect(bundleFor($this, $this->token, 'en'))->toBe(['core.actions.save' => 'Save (platform)'])
        ->and(AuditLog::where('action', 'i18n.text_reset')->count())->toBe(1);
});

// ── Levels ──

it('lets the nearest level win: platform, then partner, then group, then company', function () {
    platformText('en', 'core.actions.save', 'P');
    platformText('en', 'core.actions.edit', 'P');
    platformText('en', 'core.actions.next', 'P');
    app(TranslationService::class)->save(TranslationScope::partner($this->w->partnerA), Channel::Ui, 'en', 'core.actions.edit', 'Partner', $this->owner);
    app(TranslationService::class)->save(TranslationScope::partner($this->w->partnerA), Channel::Ui, 'en', 'core.actions.next', 'Partner', $this->owner);
    app(TranslationService::class)->save(TranslationScope::organization($this->w->g1), Channel::Ui, 'en', 'core.actions.next', 'Group', $this->owner);
    app(TranslationService::class)->save(TranslationScope::organization($this->w->g1), Channel::Ui, 'en', 'core.actions.close', 'Group', $this->owner);
    app(TranslationService::class)->save(TranslationScope::organization($this->w->c1), Channel::Ui, 'en', 'core.actions.close', 'Company', $this->owner);

    expect(bundleFor($this, $this->token, 'en'))->toMatchArray([
        'core.actions.save' => 'P',
        'core.actions.edit' => 'Partner',
        'core.actions.next' => 'Group',
        'core.actions.close' => 'Company',
    ]);

    // A branch reads its company's wording.
    $branchUser = createMember($this->w->b1);
    expect(bundleFor($this, orgToken($branchUser, $this->w->b1), 'en'))->toMatchArray(['core.actions.close' => 'Company']);
});

it('stops using a client\'s own wording when its partner keeps one wording, without deleting it', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'Keep it'])->assertOk();

    ruleService()->set(app(RuleTargets::class)->partner($this->w->partnerA), 'i18n.allow_overrides', RuleMode::Set, false, 'Test setup', trusted: true);

    expect(bundleFor($this, $this->token, 'en'))->toBe([]);
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.edit', 'value' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'own_wording_off');
    expect(TranslationOverride::where('key', 'core.actions.save')->exists())->toBeTrue();
});

it('ignores a company\'s wording while multi_language is off there (data kept), and answers 403', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'Keep it'])->assertOk();
    toggles()->disable($this->w->g1, 'multi_language', 'Test', confirm: true);

    expect(bundleFor($this, $this->token, 'en'))->toBe([]);
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.edit', 'value' => 'x'])->assertForbidden();
    expect(TranslationOverride::count())->toBe(1);
});

it('keeps wording at a group or company, not at a branch', function () {
    wording($this, $this->token, $this->w->b1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'x'])
        ->assertStatus(422)->assertJsonPath('code', 'wrong_level');
});

it('needs multi_language.manage', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);
    wording($this, orgToken($staff, $this->w->c1), $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'x'])
        ->assertForbidden();
});

// ── Isolation ──

it('never shows one company\'s or partner\'s wording to another', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'C1 only'])->assertOk();
    app(TranslationService::class)->save(TranslationScope::partner($this->w->partnerA), Channel::Ui, 'en', 'core.actions.edit', 'Partner A only', $this->owner);

    $c2 = createMember($this->w->c2);
    $c4 = createMember($this->w->c4);

    expect(bundleFor($this, orgToken($c2, $this->w->c2), 'en'))->toBe(['core.actions.edit' => 'Partner A only'])
        ->and(bundleFor($this, orgToken($c4, $this->w->c4), 'en'))->toBe([]);

    // Nor can anyone word or read another company's texts by guessing its id.
    wording($this, $this->token, $this->w->c4->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'x'])->assertNotFound();
    $this->asToken($this->token)->getJson("http://localhost/api/organizations/{$this->w->c4->id}/translations?locale=en&channel=ui")->assertNotFound();
});

it('keeps partner consoles apart and the platform level for the house partner\'s owners', function () {
    $ownerA = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $tokenA = partnerToken($ownerA, $this->w->partnerA);

    $this->asToken($tokenA)->putJson('http://localhost/api/partner/translations', ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'A'])
        ->assertOk();
    expect(TranslationOverride::sole())->scope_type->toBe('partner')->scope_id->toBe($this->w->partnerA->id);

    $this->asToken($tokenA)->putJson('http://localhost/api/partner/translations', ['level' => 'platform', 'locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'P'])
        ->assertForbidden();

    $support = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
    $this->asToken(partnerToken($support, $this->w->partnerA))->putJson('http://localhost/api/partner/translations', ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'S'])
        ->assertForbidden();

    $ownerB = createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner);
    $this->asToken(partnerToken($ownerB, $this->w->partnerB))->getJson('http://localhost/api/partner/translations?locale=en&channel=ui&filter=own')
        ->assertOk()->assertJsonPath('meta.total', 0);
});

// ── Speed: hash and cache ──

it('costs the browser nothing while no level has wording', function () {
    $this->asToken($this->token)->getJson('http://localhost/api/me')
        ->assertJsonPath('data.i18n.hash', '0')
        ->assertJsonPath('data.i18n.overlays', [])
        ->assertJsonPath('data.locales', ['en', 'bn']);
});

it('caches texts for good under the current hash, and moves the hash on every change', function () {
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'bn', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'রাখুন'])->assertOk();
    $me = $this->asToken($this->token)->getJson('http://localhost/api/me')->assertJsonPath('data.i18n.overlays', ['bn']);
    $hash = $me->json('data.i18n.hash');

    $response = $this->asToken($this->token)->getJson("http://localhost/api/i18n/{$hash}/bn")->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('private');

    wording($this, $this->token, $this->w->c1->id, ['locale' => 'bn', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'সংরক্ষণ'])->assertOk();
    $newHash = $this->asToken($this->token)->getJson('http://localhost/api/me')->json('data.i18n.hash');

    expect($newHash)->not->toBe($hash);
    $stale = $this->asToken($this->token)->getJson("http://localhost/api/i18n/{$hash}/bn")->assertOk()->assertJsonPath('data', ['core.actions.save' => 'সংরক্ষণ']);
    expect($stale->headers->get('Cache-Control'))->toContain('no-store');
});

it('reads texts from the cache, not the database, once warm', function () {
    platformText('en', 'core.actions.save', 'P');
    bundleFor($this, $this->token, 'en');

    DB::enableQueryLog();
    $chain = app(ScopeChain::class)->forPartner($this->w->partnerA);
    app(TranslationOverlay::class)->texts($chain, Channel::Ui, 'en');
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'translation_'));

    expect($queries)->toHaveCount(0);
});

// ── Server texts ──

it('uses the levels\' wording for server messages too', function () {
    app(TranslationService::class)->save(TranslationScope::organization($this->w->c1), Channel::Server, 'en', 'languages.messages.saved', 'Done, saved.', $this->owner);

    wording($this, $this->token, $this->w->c1->id, ['locale' => 'en', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'x'])
        ->assertJsonPath('message', 'Done, saved.');

    // Outside that company (here: no context at all) the file's text.
    app(CurrentContext::class)->clear();
    app()->forgetScopedInstances();
    expect(__('languages.messages.saved'))->toBe('Wording saved.');
});

it('refuses server placeholders the original does not have', function () {
    expect(fn () => app(TranslationService::class)->save(TranslationScope::platform(), Channel::Server, 'en', 'languages.messages.imported', ':count texts, :secret', $this->owner))
        ->toThrow(LocalizationException::class);
});

// ── Database languages ──

it('adds a language as a draft, publishes it once enough is translated, and offers it', function () {
    $house = houseOwner();
    platformRule('i18n.publish_min_percent', 0);

    $this->asToken($house->token)->postJson('http://localhost/api/partner/languages', ['code' => 'hi', 'fallback' => 'en'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.english_name', 'Hindi')
        ->assertJsonPath('data.direction', 'ltr');

    // A draft is not offered, and partners cannot word in it.
    $this->asToken($this->token)->getJson('http://localhost/api/me')->assertJsonPath('data.locales', ['en', 'bn']);
    wording($this, $this->token, $this->w->c1->id, ['locale' => 'hi', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'x'])
        ->assertStatus(422)->assertJsonPath('code', 'language_not_offered');

    $this->asToken($house->token)->putJson('http://localhost/api/partner/translations', ['level' => 'platform', 'locale' => 'hi', 'channel' => 'ui', 'key' => 'core.actions.save', 'value' => 'सहेजें'])
        ->assertOk();
    $this->asToken($house->token)->patchJson('http://localhost/api/partner/languages/hi', ['status' => 'published'])->assertOk();

    $me = $this->asToken($this->token)->getJson('http://localhost/api/me')->assertJsonPath('data.locales', ['en', 'bn', 'hi']);
    expect(collect($me->json('data.languages'))->firstWhere('code', 'hi'))->toMatchArray(['source' => 'db', 'fallback' => 'en', 'name' => 'हिन्दी']);

    $hash = $me->json('data.i18n.hash');
    $this->asToken($this->token)->getJson("http://localhost/api/i18n/{$hash}/hi/core")->assertOk()->assertJsonPath('data', ['core.actions.save' => 'सहेजें']);
    expect(AuditLog::whereIn('action', ['i18n.language_added', 'i18n.language_changed'])->count())->toBe(2);
});

it('will not publish a language below the share the platform asks for', function () {
    $house = houseOwner();
    $this->asToken($house->token)->postJson('http://localhost/api/partner/languages', ['code' => 'ne'])->assertCreated();

    $this->asToken($house->token)->patchJson('http://localhost/api/partner/languages/ne', ['status' => 'published'])
        ->assertStatus(422)->assertJsonPath('code', 'below_minimum');
});

it('removes only drafts, and only the platform manages languages', function () {
    $house = houseOwner();
    $this->asToken($house->token)->postJson('http://localhost/api/partner/languages', ['code' => 'xx'])->assertStatus(201);
    $this->asToken($house->token)->postJson('http://localhost/api/partner/languages', ['code' => 'bn'])->assertStatus(422)->assertJsonPath('code', 'language_exists');
    $this->asToken($house->token)->postJson('http://localhost/api/partner/languages', ['code' => 'NOT A CODE'])->assertStatus(422);

    $ownerA = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $this->asToken(partnerToken($ownerA, $this->w->partnerA))->postJson('http://localhost/api/partner/languages', ['code' => 'ur'])->assertForbidden();

    $this->asToken($house->token)->deleteJson('http://localhost/api/partner/languages/xx')->assertOk();
    expect(Language::count())->toBe(0);
    $this->asToken($house->token)->deleteJson('http://localhost/api/partner/languages/bn')->assertStatus(422)->assertJsonPath('code', 'file_language');
});

it('server texts of a database language fall back to its file language', function () {
    Language::query()->forceCreate(['code' => 'hi', 'name' => 'हिन्दी', 'english_name' => 'Hindi', 'direction' => 'ltr', 'fallback' => 'bn', 'status' => 'published']);
    app(LanguageRegistry::class)->forget();
    platformText('hi', 'languages.messages.saved', 'सहेजा गया।', Channel::Server);

    app(CurrentContext::class)->clear();
    expect(__('languages.messages.saved', [], 'hi'))->toBe('सहेजा गया।')
        ->and(__('languages.messages.reset', [], 'hi'))->toBe(trans('languages.messages.reset', [], 'bn'));
});

// ── Editor, import, export ──

it('lists texts with their source, inherited level and own wording, searchable', function () {
    platformText('en', 'core.actions.save', 'P save');

    $this->asToken($this->token)->getJson("http://localhost/api/organizations/{$this->w->c1->id}/translations?locale=en&channel=ui&q=p%20save")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.key', 'core.actions.save')
        ->assertJsonPath('data.0.source', 'Save changes')
        ->assertJsonPath('data.0.inherited.level', 'platform')
        ->assertJsonPath('data.0.own', null)
        ->assertJsonPath('data.0.effective', 'P save');

    $this->asToken($this->token)->getJson("http://localhost/api/organizations/{$this->w->c1->id}/languages")
        ->assertOk()->assertJsonPath('data.own_wording', true)->assertJsonPath('data.can_hold_wording', true);
});

it('imports a translator\'s file all or nothing, and exports what applies', function () {
    $url = "http://localhost/api/organizations/{$this->w->c1->id}/translations/import";
    $this->asToken($this->token)->postJson($url, ['locale' => 'bn', 'channel' => 'ui', 'texts' => [
        ['key' => 'core.actions.save', 'value' => 'রাখুন'],
        ['key' => 'core.nope', 'value' => 'x'],
    ]])->assertStatus(422)->assertJsonPath('key', 'core.nope');
    expect(TranslationOverride::count())->toBe(0);

    $this->asToken($this->token)->postJson($url, ['locale' => 'bn', 'channel' => 'ui', 'texts' => [
        ['key' => 'core.actions.save', 'value' => 'রাখুন'],
        ['key' => 'core.actions.edit', 'value' => ''],
    ]])->assertOk()->assertJsonPath('data.count', 1);

    $export = $this->asToken($this->token)->getJson("http://localhost/api/organizations/{$this->w->c1->id}/translations/export?locale=bn&channel=ui")->assertOk();
    expect(collect($export->json('data'))->firstWhere('key', 'core.actions.save'))->toMatchArray(['text' => 'রাখুন', 'own' => 'রাখুন'])
        ->and(AuditLog::where('action', 'i18n.texts_imported')->sole()->new_values['count'])->toBe(1);
});
