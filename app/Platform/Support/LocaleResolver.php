<?php

namespace App\Platform\Support;

use App\Models\User;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;

/**
 * Which language someone reads (Phase 6), in this order:
 *
 *   1. what they picked on this screen (X-Locale),
 *   2. their own profile language,
 *   3. the organization's language (its own, inherited, or its country's),
 *   4. the platform default.
 *
 * Only languages people may use (the file languages and the published
 * database ones) are used; anything else is skipped.
 * Timezones follow the same idea: the person's, then the organization's
 * (own, inherited or country), then UTC.
 */
class LocaleResolver
{
    /** Languages written right to left. */
    private const RTL = ['ar', 'fa', 'he', 'ur', 'ps', 'ckb', 'dv', 'yi'];

    public function __construct(private OrganizationSettingsResolver $settings) {}

    public function resolve(?string $chosen = null, ?User $user = null, ?Organization $organization = null): string
    {
        foreach ([$chosen, $user?->locale, $organization === null ? null : $this->settings->values($organization)['default_locale']] as $candidate) {
            if ($this->supports($candidate)) {
                return $candidate;
            }
        }

        return (string) config('tenancy.defaults.default_locale', 'en');
    }

    public function timezone(?User $user = null, ?Organization $organization = null): string
    {
        foreach ([$user?->timezone, $organization === null ? null : $this->settings->values($organization)['timezone']] as $candidate) {
            if (is_string($candidate) && in_array($candidate, \DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    public function supports(?string $locale): bool
    {
        return app(LanguageRegistry::class)->supports($locale);
    }

    public static function direction(string $locale): string
    {
        return in_array(strtolower(strtok($locale, '-_')), self::RTL, true) ? 'rtl' : 'ltr';
    }
}
