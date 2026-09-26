<?php

namespace App\Platform\Notifications\Jobs;

use App\Models\User;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\NotificationCatalog;
use App\Platform\Notifications\Services\MailSender;
use App\Platform\Notifications\Services\TemplateRenderer;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one recorded message. Wording is taken at send time (the partner's,
 * else the default); the placeholder values are removed once it is sent.
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public string $deliveryId) {}

    public function handle(TemplateRenderer $templates, MailSender $mail): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== NotificationDelivery::QUEUED) {
            return;
        }

        $user = User::query()->find($delivery->user_id);
        if ($user === null) {
            $this->finish($delivery, NotificationDelivery::FAILED, error: 'Recipient no longer exists.');

            return;
        }

        $partner = $delivery->partner_id === null ? null : Partner::query()->find($delivery->partner_id);
        $values = (array) $delivery->data;
        $wording = $templates->wording($partner, $delivery->notification_key, 'mail', $delivery->locale);
        $action = __('notifications.templates.'.NotificationCatalog::slug($delivery->notification_key).'.action', [], $delivery->locale);

        $sender = $mail->send(
            $partner,
            $user->email,
            (string) $templates->fill($wording['subject'], $values),
            (string) $templates->fill($wording['body'], $values),
            ['label' => $action, 'url' => $values['link'] ?? ''],
            $delivery->locale,
        );

        $this->finish($delivery, NotificationDelivery::SENT, $sender);
    }

    public function failed(Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery !== null) {
            // A short, address-free reason; the full error goes to the error log.
            $message = preg_replace('/[^\s<>"@]+@[^\s<>"@]+/', '***', $exception->getMessage());
            $this->finish($delivery, NotificationDelivery::FAILED, error: mb_substr(class_basename($exception).': '.$message, 0, 300));
        }
    }

    private function finish(NotificationDelivery $delivery, string $status, ?string $sender = null, ?string $error = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'sender' => $sender,
            'error' => $error,
            'data' => null,
            'sent_at' => $status === NotificationDelivery::SENT ? now() : null,
        ])->save();
    }
}
