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
 * Email first; people without an email (self-serve by phone, Phase 5C) get
 * SMS on their verified phone, where the partner has SMS turned on.
 */
class Notifier
{
    public function __construct(
        private NotificationCatalog $catalog,
        private LinkBuilder $links,
        private BrandResolver $brands,
        private OrganizationSettingsResolver $settings,
        private SmsSender $sms,
    ) {}

    /**
     * @param  iterable<User>  $users
     * @param  array<string, string>|callable(string $locale): array<string, string>  $values  Placeholder values (per language when a callable).
     * @return list<NotificationDelivery>
     */
    public function notify(
        string $key,
        iterable $users,
        array|callable $values,
        ?Partner $partner,
        ?Organization $organization = null,
        ?string $path = null,
        ?string $locale = null,
        bool $allChannels = false,
    ): array {
        $definition = $this->catalog->get($key);
        $supported = (array) config('tenancy.supported_locales');
        $locale = in_array($locale, $supported, true) ? $locale : $this->locale($organization);
        $filled = [
            ...(is_callable($values) ? $values($locale) : $values),
            'product' => $this->brands->for($partner, $organization)['name'],
            // A message may point somewhere of its own (e.g. a one-time invitation link).
            'link' => $this->links->to($path ?? $definition['path'], $partner, $organization),
        ];

        $deliveries = [];
        foreach ($users as $user) {
            foreach ($this->channelsFor($user, $definition['channels'], $partner, $allChannels) as $channel => $address) {
                $delivery = new NotificationDelivery;
                $delivery->forceFill([
                    'partner_id' => $partner?->getKey(),
                    'organization_id' => $organization?->getKey(),
                    'user_id' => $user->getKey(),
                    'notification_key' => $key,
                    'channel' => $channel,
                    'locale' => $locale,
                    'recipient' => $channel === 'sms' ? Mask::phone($address) : Mask::email($address),
                    'status' => NotificationDelivery::QUEUED,
                    'data' => $filled,
                ])->save();

                DeliverNotification::dispatch($delivery->getKey())->afterCommit();
                $deliveries[] = $delivery;
            }
        }

        return $deliveries;
    }

    /**
     * Email when the person has one; else SMS to a verified phone, where the
     * notification and the partner allow SMS. Security notices go to both.
     *
     * @param  list<string>  $channels
     * @return array<string, string> channel => address
     */
    private function channelsFor(User $user, array $channels, ?Partner $partner, bool $all): array
    {
        $mail = $user->email !== null && $user->email !== '' && in_array('mail', $channels, true) ? $user->email : null;
        $sms = $user->phone !== null && $user->phone_verified_at !== null && in_array('sms', $channels, true) && $this->sms->enabled($partner)
            ? $user->phone
            : null;

        if ($all) {
            return array_filter(['mail' => $mail, 'sms' => $sms]);
        }

        return $mail !== null ? ['mail' => $mail] : array_filter(['sms' => $sms]);
    }

    public function locale(?Organization $organization): string
    {
        $supported = (array) config('tenancy.supported_locales');
        $locale = $organization === null ? null : ($this->settings->values($organization)['default_locale'] ?? null);

        return in_array($locale, $supported, true) ? $locale : (string) config('tenancy.defaults.default_locale', 'en');
    }
}
