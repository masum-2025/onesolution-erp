<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 5B acceptance: a partner's domain shows only that partner, an
 * unknown or unverified domain is refused, and a domain is activated only
 * after DNS verification.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    PartnerBrand::create(['partner_id' => $this->w->partnerA->id, 'product_name' => 'Acme ERP', 'primary_color' => '#0F766E']);
    PartnerBrand::create(['partner_id' => $this->w->partnerB->id, 'product_name' => 'Beta Suite', 'primary_color' => '#7C2D12']);

    $this->ownerToken = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
});

// ── Acceptance: brand by address, unknown addresses refused ──

it('shows only Partner A branding on Partner A domain', function () {
    activeDomain($this->w->partnerA, 'erp.partner-a.test');

    $page = $this->get('http://erp.partner-a.test/login')->assertOk();

    expect($page->getContent())->toContain('<title>Acme ERP</title>')
        ->not->toContain('Beta Suite')
        ->not->toContain(config('branding.house.name').'</title>');

    $this->getJson('http://erp.partner-a.test/manifest.webmanifest')->assertOk()->assertJsonPath('name', 'Acme ERP');
});

it('refuses unknown and unverified addresses and never falls back to another tenant', function () {
    PartnerDomain::create(['partner_id' => $this->w->partnerA->id, 'host' => 'pending.partner-a.test', 'status' => 'pending', 'verification_token' => 'x']);

    foreach (['unknown.example.test', 'pending.partner-a.test'] as $host) {
        $this->get("http://{$host}/login")->assertNotFound()->assertDontSee('Acme ERP');
        $this->getJson("http://{$host}/api/me")->assertNotFound()->assertJsonPath('code', 'unknown_host');
    }
});

it('activates a domain only after DNS verification', function () {
    $domain = $this->asToken($this->ownerToken)->postJson('/api/partner/domains', ['host' => 'ERP.Partner-A.test'])
        ->assertCreated()
        ->assertJsonPath('data.host', 'erp.partner-a.test')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.record.name', '_onesolution-verify.erp.partner-a.test')
        ->json('data');

    // Not published yet.
    fakeDns([]);
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/partner/domains/{$domain['id']}/verify")
        ->assertUnprocessable()->assertJsonPath('code', 'verification_failed')->assertJsonPath('record.value', $domain['record']['value']);
    $this->get('http://erp.partner-a.test/login')->assertNotFound();

    // A wrong value does not count either.
    fakeDns([$domain['record']['name'] => ['onesolution-verify=someone-else']]);
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/partner/domains/{$domain['id']}/verify")->assertUnprocessable();

    fakeDns([$domain['record']['name'] => ['v=spf1 -all', $domain['record']['value']]]);
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/partner/domains/{$domain['id']}/verify")
        ->assertOk()->assertJsonPath('data.status', 'active');

    $this->get('http://erp.partner-a.test/login')->assertOk()->assertSee('Acme ERP');
    expect(AuditLog::where('action', 'partner.domain_verified')->exists())->toBeTrue();
});

it('validates hosts and never gives one host to two accounts', function () {
    activeDomain($this->w->partnerB, 'taken.example.test');

    foreach (['https://erp.example.test', 'erp.example.test/path', '192.168.1.10', 'localhost', '-bad.example.test'] as $host) {
        $this->asToken($this->ownerToken)->postJson('/api/partner/domains', ['host' => $host])->assertUnprocessable();
    }

    $this->asToken($this->ownerToken)->postJson('/api/partner/domains', ['host' => 'taken.example.test'])
        ->assertUnprocessable()->assertJsonPath('code', 'host_taken');
});

it('stops serving a removed domain', function () {
    $domain = activeDomain($this->w->partnerA, 'erp.partner-a.test');
    $this->get('http://erp.partner-a.test/login')->assertOk();

    $this->asToken($this->ownerToken)->deleteJson("http://localhost/api/partner/domains/{$domain->id}", ['reason' => 'Moving to a new address'])->assertOk();

    $this->get('http://erp.partner-a.test/login')->assertNotFound();
});

// ── Addresses isolate accounts ──

it('does not open another partner\'s organizations on Partner A domain', function () {
    activeDomain($this->w->partnerA, 'erp.partner-a.test');
    $bOwner = createMember($this->w->c4);
    $aOwner = createMember($this->w->c1);

    // A token of a Partner B organization is refused at Partner A's address.
    $this->asToken(orgToken($bOwner, $this->w->c4))->getJson('http://erp.partner-a.test/api/organizations')
        ->assertForbidden()->assertJsonPath('code', 'wrong_address');

    // Partner A's own member works there.
    $this->asToken(orgToken($aOwner, $this->w->c1))->getJson('http://erp.partner-a.test/api/organizations')->assertOk();
});

it('lists and enters only this address\'s accounts in the browser', function () {
    activeDomain($this->w->partnerA, 'erp.partner-a.test');
    $person = createMember($this->w->c1);
    app(AddMember::class)->handle($this->w->c4, $person, MembershipType::Owner);

    $this->withHeader('Origin', 'http://erp.partner-a.test');
    $contexts = $this->postJson('http://erp.partner-a.test/session/login', ['email' => $person->email, 'password' => 'password'])
        ->assertOk()->json('contexts.organizations');

    expect(collect($contexts)->pluck('organization_id')->all())->toBe([$this->w->c1->id]);

    $this->postJson('http://erp.partner-a.test/session/context', ['organization_id' => $this->w->c4->id])
        ->assertForbidden()->assertJsonPath('code', 'wrong_address');
});

it('keeps a client domain to that client only', function () {
    activeDomain($this->w->partnerA, 'school.partner-a.test', $this->w->g1);
    $g2Owner = createMember($this->w->c3);
    $staff = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);

    $this->asToken(orgToken(createMember($this->w->c1), $this->w->c1))->getJson('http://school.partner-a.test/api/organizations')->assertOk();
    $this->asToken(orgToken($g2Owner, $this->w->c3))->getJson('http://school.partner-a.test/api/organizations')->assertForbidden();
    // The partner console is not opened from a client's own address.
    $this->asToken(partnerToken($staff, $this->w->partnerA))->getJson('http://school.partner-a.test/api/partner/organizations')->assertForbidden();
});

it('serves brand images only at their own partner\'s addresses', function () {
    Storage::fake('local');
    activeDomain($this->w->partnerB, 'suite.partner-b.test');
    $this->asToken($this->ownerToken)->postJson('/api/partner/brand/assets/mark', [
        'file' => UploadedFile::fake()->image('mark.png', 256, 256),
    ])->assertOk();

    $url = "/brand-assets/{$this->w->partnerA->id}/mark";

    $this->get("http://localhost{$url}")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get("http://suite.partner-b.test{$url}")->assertNotFound();
});

// ── Partner isolation in the console ──

it('never lists or touches another partner\'s domains', function () {
    $bDomain = activeDomain($this->w->partnerB, 'suite.partner-b.test');
    activeDomain($this->w->partnerA, 'erp.partner-a.test');

    expect(collect($this->asToken($this->ownerToken)->getJson('/api/partner/domains')->json('data'))->pluck('host')->all())
        ->toBe(['erp.partner-a.test']);

    $this->asToken($this->ownerToken)->postJson("/api/partner/domains/{$bDomain->id}/verify")->assertNotFound();
    $this->asToken($this->ownerToken)->deleteJson("/api/partner/domains/{$bDomain->id}", ['reason' => 'Take it over'])->assertNotFound();
    $this->asToken($this->ownerToken)->postJson('/api/partner/domains', ['host' => 'x.partner-a.test', 'organization_id' => $this->w->g3->id])
        ->assertNotFound()->assertJsonPath('code', 'client_not_found');
});

it('lets only partner owners manage domains', function () {
    $support = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA);

    $this->asToken($support)->getJson('/api/partner/domains')->assertOk();
    $this->asToken($support)->postJson('/api/partner/domains', ['host' => 'erp.partner-a.test'])->assertForbidden();
});

// ── TLS certificates only for verified hosts ──

it('asks for certificates only for verified hosts, with the shared token', function () {
    config(['branding.tls_ask_token' => 'secret-token']);
    activeDomain($this->w->partnerA, 'erp.partner-a.test');
    PartnerDomain::create(['partner_id' => $this->w->partnerA->id, 'host' => 'pending.partner-a.test', 'status' => 'pending', 'verification_token' => 'x']);

    $this->get('/internal/tls/ask?domain=erp.partner-a.test')->assertForbidden();
    $this->get('/internal/tls/ask?domain=erp.partner-a.test&token=wrong')->assertForbidden();
    $this->get('/internal/tls/ask?domain=erp.partner-a.test&token=secret-token')->assertOk();
    $this->get('/internal/tls/ask?domain=pending.partner-a.test&token=secret-token')->assertNotFound();
    $this->get('/internal/tls/ask?domain=random.example.test&token=secret-token')->assertNotFound();
});

it('keeps the platform address open to every account', function () {
    $group = createMember($this->w->g3, MembershipType::Owner, AccessScope::Descendants);

    $this->asToken(orgToken($group, $this->w->g3))->getJson('/api/organizations')->assertOk();
});
