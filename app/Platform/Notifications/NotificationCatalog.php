<?php

namespace App\Platform\Notifications;

use InvalidArgumentException;

/**
 * Every notification the platform sends: its channels, the placeholders its
 * wording may use, and the screen its button opens. Default wording lives in
 * lang/{locale}/notifications.php under "templates.{key with dots as _}".
 * Partners may reword a notification but never add placeholders: only these
 * values are ever filled in.
 */
final class NotificationCatalog
{
    public const CHANNELS = ['mail', 'sms'];

    /** @var array<string, array{channels: list<string>, placeholders: list<string>, audience: string, path: string}> */
    private const NOTIFICATIONS = [
        'support.requested' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'partner', 'staff', 'severity', 'minutes', 'reason', 'link'],
            'audience' => 'client',
            'path' => '/support-access',
        ],
        'support.decided' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'decision', 'reason', 'minutes', 'link'],
            'audience' => 'partner',
            'path' => '/partner/support',
        ],
        'exports.ready' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'expires', 'link'],
            'audience' => 'client',
            'path' => '/export',
        ],
        'billing.invoice_issued' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'number', 'amount', 'due', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.partner_invoice_issued' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'partner', 'number', 'amount', 'due', 'link'],
            'audience' => 'partner',
            'path' => '/partner/billing',
        ],
        'transfers.requested' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'reason', 'link'],
            'audience' => 'partner',
            'path' => '/partner/transfers',
        ],
        'transfers.completed' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'partner', 'link'],
            'audience' => 'client',
            'path' => '/provider',
        ],
        'transfers.client_left' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'link'],
            'audience' => 'partner',
            'path' => '/partner/organizations',
        ],
        'legal.updated' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'document', 'summary', 'link'],
            'audience' => 'client',
            'path' => '/provider',
        ],
    ];

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::NOTIFICATIONS);
    }

    public function has(string $key): bool
    {
        return isset(self::NOTIFICATIONS[$key]);
    }

    /**
     * @return array{channels: list<string>, placeholders: list<string>, audience: string, path: string}
     */
    public function get(string $key): array
    {
        return self::NOTIFICATIONS[$key] ?? throw new InvalidArgumentException("Unknown notification [{$key}].");
    }

    public function supports(string $key, string $channel): bool
    {
        return $this->has($key) && in_array($channel, self::NOTIFICATIONS[$key]['channels'], true);
    }

    /** "support.requested" -> "support_requested" (translation keys). */
    public static function slug(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
