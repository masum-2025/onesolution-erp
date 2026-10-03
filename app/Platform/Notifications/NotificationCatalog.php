<?php

namespace App\Platform\Notifications;

use App\Platform\Modules\ModuleRegistry;
use InvalidArgumentException;

/**
 * Every notification the platform sends: its channels, the placeholders its
 * wording may use, and the screen its button opens. Default wording lives in
 * lang/{locale}/notifications.php under "templates.{key with dots as _}".
 * Partners may reword a notification but never add placeholders: only these
 * values are ever filled in.
 *
 * Modules add their own in the manifest (`notifications`, keys starting with
 * the module key); their wording lives in "{module}::notifications".
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
        // Self-serve billing (Phase 5C-2).
        'billing.payment_received' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'amount', 'number', 'method', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.payment_failed' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'amount', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.trial_ending' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'plan', 'ends', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.trial_ended' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'plan', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.payment_overdue' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'number', 'amount', 'due', 'read_only_on', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.workspace_restricted' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        'billing.workspace_restored' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'link'],
            'audience' => 'client',
            'path' => '/billing',
        ],
        // A partner added a company to a client's group (billed with the group).
        'partners.company_added' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'company', 'partner', 'link'],
            'audience' => 'client',
            'path' => '/organizations',
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
        'members.invited' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'inviter', 'link'],
            'audience' => 'client',
            'path' => '/login',
        ],
        'members.added' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'inviter', 'link'],
            'audience' => 'client',
            'path' => '/login',
        ],
        'legal.updated' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'document', 'summary', 'link'],
            'audience' => 'client',
            'path' => '/provider',
        ],
        // Security notices to the person (Phase 5C-1). Fixed wording: a partner
        // could otherwise remove the "if it was not you" warning.
        'identity.password_changed' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'time', 'link'],
            'audience' => 'person',
            'path' => '/forgot',
            'editable' => false,
        ],
        'identity.contact_changed' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'kind', 'time', 'link'],
            'audience' => 'person',
            'path' => '/forgot',
            'editable' => false,
        ],
        // "Delete my account" (Phase 5C-3): to every address, fixed wording.
        'identity.deletion_requested' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'date', 'link'],
            'audience' => 'person',
            'path' => '/account',
            'editable' => false,
        ],
        'identity.deletion_cancelled' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'link'],
            'audience' => 'person',
            'path' => '/account',
            'editable' => false,
        ],
        // Security alerts (Phase 9-2). Fixed wording: a partner could otherwise
        // soften a warning.
        'security.sign_in_attempts' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'count', 'time', 'link'],
            'audience' => 'person',
            'path' => '/account',
            'editable' => false,
        ],
        'security.alert' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'event', 'details', 'time', 'link'],
            'audience' => 'client',
            'path' => '/audit-log',
            'editable' => false,
        ],
        'security.partner_alert' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'partner', 'event', 'details', 'time', 'link'],
            'audience' => 'partner',
            'path' => '/partner/api-keys',
            'editable' => false,
        ],
        // A client's own payment gateway account (Phase 6): where its customers'
        // money goes. Fixed wording: a partner could otherwise soften the warning.
        'payments.merchant_change_requested' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'organization', 'gateway', 'person', 'takes_effect', 'link'],
            'audience' => 'client',
            'path' => '/online-payments',
            'editable' => false,
        ],
        'payments.merchant_change_applied' => [
            'channels' => ['mail'],
            'placeholders' => ['product', 'organization', 'gateway', 'person', 'link'],
            'audience' => 'client',
            'path' => '/online-payments',
            'editable' => false,
        ],
        'identity.signup_attempt' => [
            'channels' => ['mail', 'sms'],
            'placeholders' => ['product', 'link'],
            'audience' => 'person',
            'path' => '/login',
            'editable' => false,
        ],
    ];

    /** @var array<string, array<string, mixed>> The platform's and every module's notifications. */
    private array $notifications;

    public function __construct(?ModuleRegistry $modules = null)
    {
        $this->notifications = self::NOTIFICATIONS;

        foreach ($modules?->all() ?? [] as $module) {
            foreach ($module->notifications as $key => $definition) {
                $this->notifications[$key] = [...$definition, 'lang' => "{$module->key}::notifications"];
            }
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->notifications);
    }

    /**
     * Notifications partners may reword.
     *
     * @return list<string>
     */
    public function editableKeys(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key) => $this->notifications[$key]['editable'] ?? true));
    }

    public function has(string $key): bool
    {
        return isset($this->notifications[$key]);
    }

    /**
     * @return array{channels: list<string>, placeholders: list<string>, audience: string, path: string}
     */
    public function get(string $key): array
    {
        return $this->notifications[$key] ?? throw new InvalidArgumentException("Unknown notification [{$key}].");
    }

    public function supports(string $key, string $channel): bool
    {
        return $this->has($key) && in_array($channel, $this->notifications[$key]['channels'], true);
    }

    /**
     * The translation key of a notification's text: "templates" (subject, body,
     * sms, action) or "catalog" (name, description), e.g.
     * notifications.templates.support_requested or hrm::notifications.templates.hrm_document_expiring.
     */
    public function textKey(string $key, string $section): string
    {
        return ($this->notifications[$key]['lang'] ?? 'notifications').".{$section}.".self::slug($key);
    }

    /** "support.requested" -> "support_requested" (translation keys). */
    public static function slug(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
