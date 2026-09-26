<?php

namespace App\Platform\Identity\Jobs;

use App\Platform\Branding\BrandResolver;
use App\Platform\Notifications\Services\MailSender;
use App\Platform\Notifications\Services\SmsSender;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one one-time code by SMS or email, in the partner's brand. The job
 * is encrypted in the queue and nothing about it is recorded: the code is
 * never stored anywhere readable. Wording is fixed (not partner-editable),
 * so a code message always carries its "never share" warning.
 */
class SendOtp implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        public string $channel,
        public string $to,
        public string $code,
        public string $purpose,
        public ?string $partnerId,
        public string $locale,
    ) {}

    public function handle(SmsSender $sms, MailSender $mail, BrandResolver $brands): void
    {
        $partner = $this->partnerId === null ? null : Partner::query()->find($this->partnerId);
        $values = [
            'code' => $this->code,
            'product' => $brands->for($partner)['name'],
            'minutes' => (string) config('identity.otp.ttl_minutes'),
            'purpose' => __("identity.otp.purposes.{$this->purpose}", [], $this->locale),
        ];

        if ($this->channel === 'sms') {
            $sms->send($partner, $this->to, __('identity.otp.sms', $values, $this->locale));

            return;
        }

        $mail->send(
            $partner,
            $this->to,
            __('identity.otp.mail_subject', $values, $this->locale),
            __('identity.otp.mail_body', $values, $this->locale),
            null,
            $this->locale,
        );
    }
}
