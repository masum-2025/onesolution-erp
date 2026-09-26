<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Branding\BrandResolver;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\SupportAccess\Enums\Severity;
use App\Platform\SupportAccess\Services\SupportAccessService;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 5B-5: a client shows its own name, color and logo to its own
 * people, only where its partner allows it; nothing else changes.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->g1);
    $this->ownerToken = orgToken($this->owner, $this->w->g1);
    $this->url = "http://localhost/api/organizations/{$this->w->g1->id}/brand";
});

function allowSubBrands(object $test): void
{
    partnerRule($test->w->partnerA, 'branding.client_sub_brands_allowed', true);
}

it('is off until the partner allows it', function () {
    $this->asToken($this->ownerToken)->getJson($this->url)->assertOk()->assertJsonPath('data.allowed', false);
    $this->patchJson($this->url, ['display_name' => 'Sunrise Smart School'])->assertForbidden()->assertJsonPath('code', 'not_allowed');
});

it('shows the client\'s own brand to its people, and only to them', function () {
    allowSubBrands($this);

    $this->asToken($this->ownerToken)->patchJson($this->url, ['display_name' => 'Sunrise Smart School', 'primary_color' => '#1d4ed8'])->assertOk()
        ->assertJsonPath('data.effective.name', 'Sunrise Smart School')
        ->assertJsonPath('data.effective.primary_color', '#1D4ED8')
        ->assertJsonPath('data.effective.client', true)
        ->assertJsonPath('data.partner.name', 'Partner A');

    // Inside the client, every unit sees it.
    $staff = createMember($this->w->c1, MembershipType::Staff);
    $this->asToken(orgToken($staff, $this->w->c1))->getJson('/api/me')->assertJsonPath('data.brand.name', 'Sunrise Smart School');

    // The partner's sign-in title and tagline name its own product: not shown under the client's name.
    PartnerBrand::updateOrCreate(['partner_id' => $this->w->partnerA->id], ['product_name' => 'Partner A', 'version' => 1, 'login_title' => ['en' => 'Welcome to Partner A'], 'tagline' => ['en' => 'Partner A runs it']]);
    $resolved = app(BrandResolver::class)->for($this->w->partnerA->fresh(), $this->w->c1);
    expect($resolved['login_title'])->toBe([])->and($resolved['tagline'])->toBe([]);

    // Another client of the same partner, and the partner console, keep the partner's brand.
    $other = createMember($this->w->g2);
    $this->asToken(orgToken($other, $this->w->g2))->getJson('/api/me')->assertJsonPath('data.brand.name', 'Partner A');
    $this->asToken(partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA))->getJson('/api/me')->assertJsonPath('data.brand.name', 'Partner A');

    expect(AuditLog::where('action', 'organization.brand_updated')->sole()->organization_id)->toBe($this->w->g1->id);
});

it('switches off at once when the partner stops allowing it', function () {
    allowSubBrands($this);
    $this->asToken($this->ownerToken)->patchJson($this->url, ['display_name' => 'Sunrise Smart School'])->assertOk();

    partnerRule($this->w->partnerA, 'branding.client_sub_brands_allowed', false);

    expect(app(BrandResolver::class)->for($this->w->partnerA, $this->w->c1)['name'])->toBe('Partner A');
});

it('checks colors and who may change the brand', function () {
    allowSubBrands($this);
    $staff = createMember($this->w->g1, MembershipType::Staff);

    $this->asToken($this->ownerToken)->patchJson($this->url, ['primary_color' => '#FFF59D'])->assertUnprocessable()->assertJsonPath('code', 'contrast_surface');
    $this->asToken($this->ownerToken)->patchJson($this->url, ['display_name' => '<script>'])->assertUnprocessable()->assertJsonValidationErrors('display_name');
    $this->asToken(orgToken($staff, $this->w->g1))->patchJson($this->url, ['display_name' => 'Mine'])->assertForbidden()->assertJsonPath('code', 'forbidden');
    // A company owner cannot change the whole account's brand.
    $this->asToken(orgToken(createMember($this->w->c1), $this->w->c1))->patchJson("http://localhost/api/organizations/{$this->w->c1->id}/brand", ['display_name' => 'Mine'])->assertForbidden();
});

it('stores the logo privately and serves it only at the client\'s addresses', function () {
    allowSubBrands($this);
    $this->asToken($this->ownerToken)->postJson("{$this->url}/logo", ['file' => UploadedFile::fake()->image('logo.png', 200, 60)])->assertOk();

    $logo = app(BrandResolver::class)->for($this->w->partnerA, $this->w->g1)['logo_url'];
    expect($logo)->toStartWith("/client-brand-assets/{$this->w->g1->id}/logo?v=");

    $this->get("http://localhost{$logo}")->assertOk()->assertHeader('Content-Type', 'image/png');
    // Partner B's address does not serve Partner A's client's logo.
    activeDomain($this->w->partnerB, 'erp.partner-b.test');
    $this->get("http://erp.partner-b.test{$logo}")->assertNotFound();

    $this->asToken($this->ownerToken)->postJson("{$this->url}/logo", ['file' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])->assertUnprocessable();
});

it('sends its people\'s emails in the client\'s brand', function () {
    allowSubBrands($this);
    $this->asToken($this->ownerToken)->patchJson($this->url, ['display_name' => 'Sunrise Smart School'])->assertOk();
    Mail::mailer()->getSymfonyTransport()->flush();

    app(SupportAccessService::class)->request($this->w->partnerA, $this->w->c1, createPartnerStaff($this->w->partnerA), 'Checking the fee report', Severity::Normal, 30);

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first(fn ($sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress() === $this->owner->email)->getOriginalMessage();
    expect($email->getFrom()[0]->getName())->toBe('Sunrise Smart School')
        ->and($email->getHtmlBody())->toContain('Sunrise Smart School');
});
