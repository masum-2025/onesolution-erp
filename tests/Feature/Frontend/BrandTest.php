<?php

use App\Platform\Branding\BrandResolver;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Tenancy\Models\Partner;

/*
 * Brand assets are data: the house brand from config, white-label partners
 * from partner_brands. Only images on this site are shown (the page's CSP
 * allows no other origin), and a partner never shows the house logo.
 */

it('gives the house brand its logo, mark and tagline in both languages', function () {
    $brand = app(BrandResolver::class)->for();

    expect($brand['logo_url'])->toBe('/brand/house/logo.png')
        ->and($brand['mark_url'])->toBe('/brand/house/mark.png')
        ->and($brand['primary_color'])->toBe('#2B4C9B')
        ->and($brand['tagline'])->toBe(['en' => 'the symbol of freedom', 'bn' => 'স্বাধীনতার প্রতীক'])
        ->and(file_exists(public_path('brand/house/logo.png')))->toBeTrue()
        ->and(file_exists(public_path('brand/house/mark.png')))->toBeTrue();
});

it('never shows the house logo or tagline for a white-label partner', function () {
    $partner = Partner::factory()->create(['name' => 'Acme ERP']);
    PartnerBrand::create(['partner_id' => $partner->id, 'primary_color' => '#0F766E']);

    $brand = app(BrandResolver::class)->for($partner);

    expect($brand['logo_url'])->toBeNull()
        ->and($brand['mark_url'])->toBeNull()
        ->and($brand['tagline'])->toBe([])
        ->and($brand['name'])->toBe('Acme ERP')
        ->and($brand['powered_by'])->toBe('One Solutions');
});

it('serves a partner logo from this site, versioned', function () {
    $partner = Partner::factory()->create();
    PartnerBrand::create([
        'partner_id' => $partner->id,
        'logo_light_path' => 'brand/x/logo.png',
        'mark_path' => 'brand/x/mark.png',
        'tagline' => ['en' => 'Built for schools', 'bn' => 'স্কুলের জন্য তৈরি', 'xx' => 'ignored'],
        'version' => 3,
    ]);

    $brand = app(BrandResolver::class)->for($partner);

    expect($brand['logo_url'])->toBe("/brand-assets/{$partner->id}/logo_light?v=3")
        ->and($brand['mark_url'])->toBe("/brand-assets/{$partner->id}/mark?v=3")
        ->and($brand['logo_dark_url'])->toBeNull()
        ->and($brand['tagline'])->toBe(['en' => 'Built for schools', 'bn' => 'স্কুলের জন্য তৈরি']);
});

it('rejects house logo addresses outside this site or not an image', function (string $url) {
    config(['branding.house.logo_url' => $url, 'branding.house.mark_url' => $url]);

    $brand = app(BrandResolver::class)->for();

    expect($brand['logo_url'])->toBeNull()->and($brand['mark_url'])->toBeNull();
})->with([
    'other site' => ['https://evil.example/logo.png'],
    'protocol-relative' => ['//evil.example/logo.png'],
    'script' => ['javascript:alert(1)'],
    'data uri' => ['data:image/svg+xml,<svg onload=alert(1)>'],
    'path traversal' => ['/brand/../../.env.png'],
    'not an image' => ['/brand/acme/logo.html'],
    'quote injection' => ['/brand/a"onerror="x.png'],
]);

it('uses the brand mark as the page icon', function () {
    $this->withoutVite();

    $html = $this->get('/login')->getContent();

    expect($html)->toContain('<link rel="icon" href="/brand/house/mark.png">')
        ->toContain('<meta name="theme-color" content="#2B4C9B">');
});

it('falls back to a generated letter icon without a mark', function () {
    $this->withoutVite();
    config(['branding.house.mark_url' => null]);

    expect($this->get('/login')->getContent())->toContain('<link rel="icon" href="data:image/svg+xml,');
});
