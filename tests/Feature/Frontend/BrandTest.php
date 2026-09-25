<?php

use App\Platform\Branding\BrandResolver;
use App\Platform\Tenancy\Models\Partner;

/*
 * Brand assets are data: the house brand from config, white-label partners
 * from their own settings. Only images on this site are accepted (the page's
 * CSP allows no other origin), and a partner never shows the house logo.
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
    $partner = Partner::factory()->create(['name' => 'Acme ERP', 'settings' => ['brand' => ['primary_color' => '#0F766E']]]);

    $brand = app(BrandResolver::class)->for($partner);

    expect($brand['logo_url'])->toBeNull()
        ->and($brand['mark_url'])->toBeNull()
        ->and($brand['tagline'])->toBe([])
        ->and($brand['name'])->toBe('Acme ERP');
});

it('uses a partner logo on this site', function () {
    $partner = Partner::factory()->create(['settings' => ['brand' => [
        'logo_url' => '/brand/acme/logo.svg',
        'mark_url' => '/brand/acme/mark.png',
        'tagline' => ['en' => 'Built for schools', 'bn' => 'স্কুলের জন্য তৈরি', 'xx' => 'ignored'],
    ]]]);

    $brand = app(BrandResolver::class)->for($partner);

    expect($brand['logo_url'])->toBe('/brand/acme/logo.svg')
        ->and($brand['mark_url'])->toBe('/brand/acme/mark.png')
        ->and($brand['tagline'])->toBe(['en' => 'Built for schools', 'bn' => 'স্কুলের জন্য তৈরি']);
});

it('rejects logo addresses outside this site or not an image', function (string $url) {
    $partner = Partner::factory()->create(['settings' => ['brand' => ['logo_url' => $url, 'mark_url' => $url]]]);

    $brand = app(BrandResolver::class)->for($partner);

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
