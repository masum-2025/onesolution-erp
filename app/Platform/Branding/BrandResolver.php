<?php

namespace App\Platform\Branding;

use App\Platform\Tenancy\Models\Partner;

/**
 * Brand tokens the browser app applies at runtime (CSS variables, logo,
 * tagline): no per-partner builds. Every value is validated here because the
 * color is written into a <style> block and the images into <img> / <link>.
 */
class BrandResolver
{
    private const COLOR = '/^#[0-9A-Fa-f]{6}$/';

    // Only images served by this site: the CSP allows no other origin.
    private const LOCAL_PATH = '#^/(?!/)[A-Za-z0-9._\-/]+\.(png|svg|webp|jpg|jpeg)$#';

    /**
     * @return array{name: string, primary_color: string, support_email: string|null, logo_url: string|null, mark_url: string|null, tagline: array<string, string>}
     */
    public function for(?Partner $partner = null): array
    {
        $house = config('branding.house');
        $whiteLabel = $partner !== null && ! $partner->is_house;
        $own = $whiteLabel ? (array) (($partner->settings ?? [])['brand'] ?? []) : [];

        $name = $this->text($own['name'] ?? null)
            ?? ($whiteLabel ? $this->text($partner->name) : null)
            ?? $this->text($house['name'])
            ?? 'One Solutions';
        $color = $this->color($own['primary_color'] ?? null) ?? $this->color($house['primary_color']) ?? '#2B4C9B';
        $email = filter_var($own['support_email'] ?? $house['support_email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;

        // A white-label partner never shows the house logo or tagline.
        $source = $whiteLabel ? $own : $house;

        return [
            'name' => $name,
            'primary_color' => strtoupper($color),
            'support_email' => $email,
            'logo_url' => $this->path($source['logo_url'] ?? null),
            'mark_url' => $this->path($source['mark_url'] ?? null),
            'tagline' => $this->tagline($source['tagline'] ?? null),
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

    /**
     * @return array<string, string> Per supported locale; missing ones are left out.
     */
    private function tagline(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (config('tenancy.supported_locales') as $locale) {
            if (($text = $this->text($value[$locale] ?? null, 120)) !== null) {
                $out[$locale] = $text;
            }
        }

        return $out;
    }
}
