<?php

namespace App\Platform\Branding;

use App\Platform\Tenancy\Models\Partner;

/**
 * Brand tokens the browser app applies at runtime (CSS variables): no
 * per-partner builds. Every value is validated here because the primary
 * color is written into a <style> block.
 */
class BrandResolver
{
    private const COLOR = '/^#[0-9A-Fa-f]{6}$/';

    /**
     * @return array{name: string, primary_color: string, support_email: string|null}
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
        $color = $this->color($own['primary_color'] ?? null) ?? $this->color($house['primary_color']) ?? '#4F46E5';
        $email = filter_var($own['support_email'] ?? $house['support_email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;

        return ['name' => $name, 'primary_color' => strtoupper($color), 'support_email' => $email];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 60) : null;
    }

    private function color(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::COLOR, $value) === 1 ? $value : null;
    }
}
