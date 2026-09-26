<?php

namespace App\Platform\Notifications\Services;

use App\Models\User;
use App\Platform\Branding\BrandResolver;
use App\Platform\Notifications\Jobs\DeliverNotification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\NotificationCatalog;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;

/**
 * Sends a catalog notification to people, in the partner's brand and the
 * organization's language. Each message is recorded (address masked) and
 * sent by a queued job, so a slow mail server never slows the app.
 *
 * SMS goes only to people with a verified phone number; those arrive with
 * self-serve sign-up (Phase 5C), so today notifications go by email.
 */
class Notifier
{
    public function __construct(
        private NotificationCatalog $catalog,
        private LinkBuilder $links,
        private BrandResolver $brands,
        private OrganizationSettingsResolver $settings,
    ) {}

    /**
     * @param  iterable<User>  $users
     * @param  array<string, string>|callable(string $locale): array<string, string>  $values  Placeholder values (per language when a callable).
     * @return list<NotificationDelivery>
     */
    public function notify(string $key, iterable $users, array|callable $values, ?Partner $partner, ?Organization $organization = null): array
    {
        $definition = $this->catalog->get($key);
        $locale = $this->locale($organization);
        $filled = [
            ...(is_callable($values) ? $values($locale) : $values),
            'product' => $this->brands->for($partner)['name'],
            'link' => $this->links->to($definition['path'], $partner, $organization),
        ];

        $deliveries = [];
        foreach ($users as $user) {
            if ($user->email === null || $user->email === '') {
                continue;
            }

            $delivery = new NotificationDelivery;
            $delivery->forceFill([
                'partner_id' => $partner?->getKey(),
                'organization_id' => $organization?->getKey(),
                'user_id' => $user->getKey(),
                'notification_key' => $key,
                'channel' => 'mail',
                'locale' => $locale,
                'recipient' => Mask::email($user->email),
                'status' => NotificationDelivery::QUEUED,
                'data' => $filled,
            ])->save();

            DeliverNotification::dispatch($delivery->getKey())->afterCommit();
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    public function locale(?Organization $organization): string
    {
        $supported = (array) config('tenancy.supported_locales');
        $locale = $organization === null ? null : ($this->settings->values($organization)['default_locale'] ?? null);

        return in_array($locale, $supported, true) ? $locale : (string) config('tenancy.defaults.default_locale', 'en');
    }
}
