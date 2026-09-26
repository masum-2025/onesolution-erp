<?php

namespace App\Platform\Branding;

use App\Platform\Branding\Models\ClientBrand;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;

/**
 * Brand tokens the browser app applies at runtime (CSS variables, logos,
 * texts): no per-partner builds. The house brand comes from config; a
 * white-label partner's from partner_brands, and never shows the house logo
 * or tagline. Every value is checked again here because it is written into
 * the page (<style>, <img>, <link>).
 */
class BrandResolver
{
    private const COLOR = '/^#[0-9A-Fa-f]{6}$/';

    // Only images served by this site: the CSP allows no other origin.
    private const LOCAL_PATH = '#^/(?!/)[A-Za-z0-9._\-/]+\.(png|svg|webp|jpg|jpeg)$#';

    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /**
     * The brand for a partner, or for one of its clients: a client's own
     * name, color and logo lie over its partner's brand where the partner
     * allows sub-brands (branding.client_sub_brands_allowed). Everything else
     * (fonts, legal links, "Powered by") stays the partner's.
     *
     * @return array<string, mixed>
     */
    public function for(?Partner $partner = null, ?Organization $client = null): array
    {
        $brand = $this->partnerBrand($partner);
        $own = $client === null || $partner === null ? null : $this->clientBrand($partner, $client);

        if ($own === null) {
            return [...$brand, 'client' => false];
        }

        $logo = $own->logo_path === null ? null : "/client-brand-assets/{$own->organization_id}/logo?v={$own->version}";
        $name = $this->text($own->display_name);

        return [
            ...$brand,
            'name' => $name ?? $brand['name'],
            // The partner's sign-in title and tagline speak for its own product;
            // under the client's own name the neutral defaults show instead.
            'login_title' => $name === null ? $brand['login_title'] : [],
            'tagline' => $name === null ? $brand['tagline'] : [],
            'primary_color' => $this->color($own->primary_color) === null ? $brand['primary_color'] : strtoupper($own->primary_color),
            'logo_url' => $logo ?? $brand['logo_url'],
            'logo_dark_url' => $logo === null ? $brand['logo_dark_url'] : null,
            'mark_url' => $logo ?? $brand['mark_url'],
            'version' => $brand['version'] * 1000 + $own->version,
            'client' => true,
        ];
    }

    public function subBrandsAllowed(Partner $partner): bool
    {
        return (bool) $this->rules->get('branding.client_sub_brands_allowed', $this->contexts->forPartner($partner));
    }

    private function clientBrand(Partner $partner, Organization $client): ?ClientBrand
    {
        if (! $this->subBrandsAllowed($partner)) {
            return null;
        }

        $rootId = $client->isRoot() ? $client->getKey() : $client->root_id;

        return ClientBrand::query()->where('organization_id', $rootId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerBrand(?Partner $partner): array
    {
        $house = config('branding.house');
        $fonts = (array) config('branding.fonts');

        if ($partner === null || $partner->is_house) {
            return [
                'name' => $this->text($house['name']) ?? 'One Solutions',
                'primary_color' => strtoupper($this->color($house['primary_color']) ?? '#2B4C9B'),
                'secondary_color' => null,
                'font' => $fonts['inter'] ?? null,
                'support_email' => filter_var($house['support_email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
                'support_phone' => null,
                'logo_url' => $this->path($house['logo_url'] ?? null),
                'logo_dark_url' => null,
                'mark_url' => $this->path($house['mark_url'] ?? null),
                'favicon_url' => null,
                'tagline' => $this->texts($house['tagline'] ?? null, 120),
                'login_title' => [],
                'login_text' => [],
                'footer_text' => [],
                'terms_url' => null,
                'privacy_url' => null,
                // The house brand is the platform: no "Powered by".
                'powered_by' => null,
                'version' => 0,
            ];
        }

        $brand = PartnerBrand::query()->where('partner_id', $partner->getKey())->first();
        $asset = fn (string $kind) => $brand?->{PartnerBrand::ASSETS[$kind]} === null
            ? null
            : "/brand-assets/{$partner->getKey()}/{$kind}?v={$brand->version}";

        $showPoweredBy = (bool) $this->rules->get('branding.show_powered_by', $this->contexts->forPartner($partner));

        return [
            'name' => $this->text($brand?->product_name) ?? $this->text($partner->name) ?? 'App',
            // A white-label partner never falls back to the house logo; its color may fall back.
            'primary_color' => strtoupper($this->color($brand?->primary_color) ?? $this->color($house['primary_color']) ?? '#2B4C9B'),
            'secondary_color' => $this->color($brand?->secondary_color) === null ? null : strtoupper($brand->secondary_color),
            'font' => $fonts[$brand?->font_key ?? 'inter'] ?? $fonts['inter'] ?? null,
            'support_email' => filter_var($brand?->support_email, FILTER_VALIDATE_EMAIL) ?: null,
            'support_phone' => $this->text($brand?->support_phone, 30),
            'logo_url' => $asset('logo_light'),
            'logo_dark_url' => $asset('logo_dark'),
            'mark_url' => $asset('mark'),
            'favicon_url' => $asset('favicon'),
            'tagline' => $this->texts($brand?->tagline, 120),
            'login_title' => $this->texts($brand?->login_title, 80),
            'login_text' => $this->texts($brand?->login_text, 300),
            'footer_text' => $this->texts($brand?->footer_text, 200),
            'terms_url' => $this->link($brand?->terms_url),
            'privacy_url' => $this->link($brand?->privacy_url),
            'powered_by' => $showPoweredBy ? $this->text(config('branding.powered_by')) : null,
            'version' => (int) ($brand?->version ?? 0),
        ];
    }

    private function text(mixed $value, int $max = 60): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private function color(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::COLOR, $value) === 1 ? $value : null;
    }

    private function path(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::LOCAL_PATH, $value) === 1 && ! str_contains($value, '..') ? $value : null;
    }

    private function link(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    /**
     * @return array<string, string> Per supported locale; missing ones are left out.
     */
    private function texts(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (config('tenancy.supported_locales') as $locale) {
            if (($text = $this->text($value[$locale] ?? null, $max)) !== null) {
                $out[$locale] = $text;
            }
        }

        return $out;
    }
}
