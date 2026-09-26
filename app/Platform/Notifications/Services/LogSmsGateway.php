<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development and test driver: nothing leaves the server. The log shows the
 * masked number, the sender and the length; the text only on a local
 * machine with SMS_LOG_TEXT=true (to read sign-up codes).
 */
class LogSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, sender: string, text: string}> Kept in memory for tests. */
    public array $sent = [];

    public function send(string $to, string $senderId, string $text): string
    {
        $this->sent[] = ['to' => $to, 'sender' => $senderId, 'text' => $text];
        Log::info('SMS (log driver)', [
            'to' => Mask::phone($to),
            'sender' => $senderId,
            'segments' => SmsText::segments($text),
            // Only on a developer's own machine, to read codes without a real SMS provider.
            ...(app()->environment('local') && config('notifications.sms.log_text') ? ['text' => $text] : []),
        ]);

        return 'log-'.Str::ulid();
    }
}
