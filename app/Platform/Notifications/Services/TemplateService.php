<?php

namespace App\Platform\Notifications\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Exceptions\NotificationException;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\NotificationCatalog;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * A partner rewords a notification for all of its clients, per channel and
 * language, using only the placeholders that notification offers. Resetting
 * brings the platform default back.
 */
class TemplateService
{
    public function __construct(private NotificationCatalog $catalog, private TemplateRenderer $renderer, private AuditLogger $audit) {}

    public function save(Partner $partner, string $key, string $channel, string $locale, ?string $subject, string $body, User $actor): NotificationTemplate
    {
        if (! $this->catalog->supports($key, $channel)) {
            throw NotificationException::channelNotSupported();
        }

        $subject = $channel === 'mail' ? trim((string) $subject) : null;
        $body = trim(str_replace("\r\n", "\n", $body));

        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            $unknown = $text === null ? [] : $this->renderer->unknownPlaceholders($key, $text);
            if ($unknown !== []) {
                throw NotificationException::unknownPlaceholders($unknown, $field);
            }
        }

        return DB::transaction(function () use ($partner, $key, $channel, $locale, $subject, $body, $actor) {
            $template = NotificationTemplate::query()
                ->where('partner_id', $partner->getKey())
                ->where('notification_key', $key)
                ->where('channel', $channel)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first() ?? new NotificationTemplate;

            $old = $template->exists ? ['subject' => $template->subject, 'body' => $template->body] : $this->renderer->default($key, $channel, $locale);

            $template->forceFill([
                'partner_id' => $partner->getKey(),
                'notification_key' => $key,
                'channel' => $channel,
                'locale' => $locale,
                'subject' => $subject,
                'body' => $body,
                'version' => ($template->version ?? 0) + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                action: 'partner.template_saved',
                target: $template,
                old: $old,
                new: ['notification' => $key, 'channel' => $channel, 'locale' => $locale, 'subject' => $subject, 'body' => $body],
                actor: $actor,
                partnerId: $partner->getKey(),
            );

            return $template;
        });
    }

    public function reset(Partner $partner, string $key, string $channel, string $locale, User $actor): void
    {
        $template = NotificationTemplate::query()
            ->where('partner_id', $partner->getKey())
            ->where('notification_key', $key)
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->first();

        if ($template === null) {
            return;
        }

        DB::transaction(function () use ($template, $actor, $partner) {
            $this->audit->record(
                action: 'partner.template_reset',
                target: $template,
                old: ['notification' => $template->notification_key, 'channel' => $template->channel, 'locale' => $template->locale, 'subject' => $template->subject, 'body' => $template->body],
                actor: $actor,
                partnerId: $partner->getKey(),
            );
            $template->delete();
        });
    }
}
