<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Exceptions\NotificationException;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\NotificationCatalog;
use App\Platform\Tenancy\Models\Partner;

/**
 * Turns a notification's wording into the text people receive. Wording is
 * plain text with {{ placeholder }} slots; only the notification's own
 * placeholders are filled, nothing is ever evaluated, and HTML is escaped
 * later by the mail view. A partner's wording replaces the default per
 * channel and language; anything it has not reworded stays the default.
 */
class TemplateRenderer
{
    private const SLOT = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/';

    public function __construct(private NotificationCatalog $catalog) {}

    /**
     * @return array{subject: string|null, body: string, custom: bool, version: int|null}
     */
    public function wording(?Partner $partner, string $key, string $channel, string $locale): array
    {
        if (! $this->catalog->supports($key, $channel)) {
            throw NotificationException::channelNotSupported();
        }

        $custom = $partner === null ? null : NotificationTemplate::query()
            ->where('partner_id', $partner->getKey())
            ->where('notification_key', $key)
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->first();

        if ($custom !== null) {
            return ['subject' => $custom->subject, 'body' => $custom->body, 'custom' => true, 'version' => $custom->version];
        }

        return [...$this->default($key, $channel, $locale), 'custom' => false, 'version' => null];
    }

    /**
     * @return array{subject: string|null, body: string}
     */
    public function default(string $key, string $channel, string $locale): array
    {
        $base = $this->catalog->textKey($key, 'templates');

        return $channel === 'sms'
            ? ['subject' => null, 'body' => (string) __("{$base}.sms", [], $locale)]
            : ['subject' => (string) __("{$base}.subject", [], $locale), 'body' => (string) __("{$base}.body", [], $locale)];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fill(?string $text, array $values): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback(self::SLOT, fn (array $match) => (string) ($values[$match[1]] ?? ''), $text);
    }

    /**
     * Placeholders a text uses that the notification does not offer.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $key, string $text): array
    {
        preg_match_all(self::SLOT, $text, $matches);

        return array_values(array_unique(array_diff($matches[1], $this->catalog->get($key)['placeholders'])));
    }

    /**
     * Example values for previews (in the language asked for).
     *
     * @return array<string, string>
     */
    public function sample(string $key, string $locale, string $product, string $link): array
    {
        $values = [];
        foreach ($this->catalog->get($key)['placeholders'] as $name) {
            $values[$name] = match ($name) {
                'product' => $product,
                'link' => $link,
                default => (string) __("notifications.samples.{$name}", [], $locale),
            };
        }

        return $values;
    }

    /**
     * Paragraphs (blank line) of lines (newline), for the mail view to print escaped.
     *
     * @return list<list<string>>
     */
    public static function paragraphs(string $body): array
    {
        $paragraphs = preg_split('/\R\s*\R/', trim($body)) ?: [];

        return array_values(array_map(fn (string $paragraph) => preg_split('/\R/', trim($paragraph)) ?: [], array_filter($paragraphs, fn ($p) => trim($p) !== '')));
    }
}
