<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 5B: a partner edits its brand in the console; colors must stay
 * readable, images are raster only and stored privately.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->w = tenancyWorld();
    $this->token = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
});

it('saves the brand and shows it to the partner\'s clients', function () {
    $this->asToken($this->token)->patchJson('/api/partner/brand', [
        'product_name' => 'Acme ERP',
        'primary_color' => '#0f766e',
        'font_key' => 'system',
        'login_title' => ['en' => 'Welcome to Acme', 'bn' => 'অ্যাকমে-তে স্বাগতম'],
        'terms_url' => 'https://acme.example/terms',
        'support_phone' => '+880 1700-000000',
    ])->assertOk()
        ->assertJsonPath('data.resolved.name', 'Acme ERP')
        ->assertJsonPath('data.resolved.primary_color', '#0F766E')
        ->assertJsonPath('data.resolved.login_title.bn', 'অ্যাকমে-তে স্বাগতম')
        ->assertJsonPath('data.resolved.terms_url', 'https://acme.example/terms');

    $member = createMember($this->w->c1);
    spaSession($this, $member, $this->w->c1);
    $this->getJson('/api/me')->assertJsonPath('data.brand.name', 'Acme ERP')->assertJsonPath('data.brand.powered_by', 'One Solutions');

    expect(AuditLog::where('action', 'partner.brand_updated')->exists())->toBeTrue()
        ->and(PartnerBrand::where('partner_id', $this->w->partnerA->id)->value('version'))->toBe(1);
});

it('refuses colors that are hard to read', function (string $field, string $color, string $code) {
    $this->asToken($this->token)->patchJson('/api/partner/brand', [$field => $color])
        ->assertUnprocessable()->assertJsonPath('code', $code);
})->with([
    'pale yellow main color' => ['primary_color', '#FFF59D', 'contrast_surface'],
    // Neither white nor near-black text reaches 4.5:1 on this grey.
    'mid grey' => ['primary_color', '#7A7A7A', 'contrast_text'],
]);

it('validates brand input', function (array $body, string $field) {
    $this->asToken($this->token)->patchJson('/api/partner/brand', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'not a color' => [['primary_color' => 'teal'], 'primary_color'],
    'css injection' => [['primary_color' => '#000;}'], 'primary_color'],
    'http legal link' => [['terms_url' => 'http://acme.example/terms'], 'terms_url'],
    'script link' => [['privacy_url' => 'javascript:alert(1)'], 'privacy_url'],
    'unknown font' => [['font_key' => 'comic_sans'], 'font_key'],
    'unknown field' => [['partner_id' => 'x'], 'partner_id'],
    'unsupported language' => [['login_text' => ['fr' => 'Bonjour']], 'login_text'],
]);

it('stores raster images privately and refuses SVG', function () {
    $this->asToken($this->token)->postJson('/api/partner/brand/assets/logo_light', [
        'file' => UploadedFile::fake()->image('logo.png', 480, 120),
    ])->assertOk()->assertJsonPath('data.resolved.logo_url', "/brand-assets/{$this->w->partnerA->id}/logo_light?v=1");

    $path = PartnerBrand::where('partner_id', $this->w->partnerA->id)->value('logo_light_path');
    expect($path)->toStartWith("brand/{$this->w->partnerA->id}/")->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(file_exists(public_path($path)))->toBeFalse();

    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
    $this->asToken($this->token)->postJson('/api/partner/brand/assets/mark', ['file' => $svg])->assertJsonValidationErrors('file');
    $this->asToken($this->token)->postJson('/api/partner/brand/assets/mark', ['file' => UploadedFile::fake()->image('huge.png', 3000, 3000)])->assertJsonValidationErrors('file');
    $this->asToken($this->token)->postJson('/api/partner/brand/assets/banner', ['file' => UploadedFile::fake()->image('x.png', 64, 64)])->assertNotFound();

    // Replacing an image deletes the old file.
    $this->asToken($this->token)->postJson('/api/partner/brand/assets/logo_light', ['file' => UploadedFile::fake()->image('logo2.png', 480, 120)])->assertOk();
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('keeps "Powered by" unless the platform allows hiding it', function () {
    $this->asToken($this->token)->putJson('/api/partner/brand/powered-by', ['show' => false])
        ->assertForbidden()->assertJsonPath('code', 'powered_by_locked');

    partnerRule($this->w->partnerA, 'branding.powered_by_removable', true);

    $this->asToken($this->token)->putJson('/api/partner/brand/powered-by', ['show' => false])
        ->assertOk()->assertJsonPath('data.resolved.powered_by', null);
});

it('lets only partner owners change the brand', function (PartnerUserRole $role) {
    $token = partnerToken(createPartnerStaff($this->w->partnerA, $role), $this->w->partnerA);

    $this->asToken($token)->getJson('/api/partner/brand')->assertOk()->assertJsonPath('data.can_edit', false);
    $this->asToken($token)->patchJson('/api/partner/brand', ['product_name' => 'Hijack'])->assertForbidden();
    $this->asToken($token)->postJson('/api/partner/brand/assets/mark', ['file' => UploadedFile::fake()->image('m.png', 64, 64)])->assertForbidden();
})->with([PartnerUserRole::Support, PartnerUserRole::Sales, PartnerUserRole::Billing]);

it('never changes another partner\'s brand', function () {
    $this->asToken($this->token)->patchJson('/api/partner/brand', ['product_name' => 'Acme ERP'])->assertOk();

    expect(PartnerBrand::where('partner_id', $this->w->partnerB->id)->exists())->toBeFalse();
});

it('keeps the brand out of the client area', function () {
    $owner = createMember($this->w->c1);

    $this->asToken(orgToken($owner, $this->w->c1))->patchJson('/api/partner/brand', ['product_name' => 'Self-branded'])->assertForbidden();
});
