<?php

namespace App\Platform\Monitoring\Channels;

use App\Platform\Monitoring\Models\SecurityAlert;
use App\Platform\Notifications\Contracts\SmsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tells the platform operators (config monitoring.platform): email to a list,
 * a Slack incoming webhook, SMS to on-call phones. Each channel takes alerts
 * from its minimum severity up. The text holds ids and codes only.
 */
class PlatformAlertChannels
{
    public const CHANNELS = ['mail', 'slack', 'sms'];

    public function __construct(private SmsGateway $sms) {}

    /**
     * The channels configured for this alert's severity.
     *
     * @return list<string>
     */
    public function for(SecurityAlert $alert): array
    {
        $config = (array) config('monitoring.platform');

        return array_values(array_filter(self::CHANNELS, function (string $channel) use ($config, $alert) {
            $settings = $config[$channel] ?? [];
            $configured = $channel === 'slack' ? ! empty($settings['webhook']) : ($settings['to'] ?? []) !== [];

            return $configured && $alert->atLeast((string) ($settings['min_severity'] ?? 'high'));
        }));
    }

    public function send(string $channel, string $subject, string $text): void
    {
        $settings = (array) config("monitoring.platform.{$channel}");

        match ($channel) {
            'mail' => Mail::raw($text, fn ($message) => $message->to($settings['to'])->subject($subject)),
            'slack' => Http::timeout(10)->post($settings['webhook'], ['text' => "*{$subject}*\n{$text}"])->throw(),
            'sms' => array_map(fn (string $phone) => $this->sms->send($phone, (string) config('notifications.sms.default_sender'), Str::limit($subject, 150)), $settings['to']),
        };
    }
}
