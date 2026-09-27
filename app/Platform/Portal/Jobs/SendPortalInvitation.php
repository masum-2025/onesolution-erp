<?php

namespace App\Platform\Portal\Jobs;

use App\Platform\Branding\BrandResolver;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Notifications\Services\MailSender;
use App\Platform\Notifications\Services\SmsSender;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a portal invitation (link and code) to someone who may not have an
 * account yet, in the client's brand. Encrypted in the queue: the link and
 * the code are never stored readable.
 */
class SendPortalInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public string $organizationId,
        public string $channel,
        public string $to,
        public string $name,
        public string $token,
        public string $code,
        public string $expires,
        public string $locale,
    ) {}

    public function handle(SmsSender $sms, MailSender $mail, BrandResolver $brands, LinkBuilder $links): void
    {
        $organization = Organization::query()->with('partner')->find($this->organizationId);
        if ($organization === null) {
            return;
        }

        $values = [
            'name' => $this->name,
            'organization' => $organization->displayName($this->locale),
            'product' => $brands->for($organization->partner, $organization)['name'],
            'code' => $this->code,
            'expires' => $this->expires,
            'link' => $links->to("/portal/join/{$this->token}", $organization->partner, $organization),
        ];

        if ($this->channel === 'sms') {
            $sms->send($organization->partner, $this->to, __('portal.invitation.sms', $values, $this->locale));

            return;
        }

        $mail->send(
            $organization->partner,
            $this->to,
            __('portal.invitation.mail_subject', $values, $this->locale),
            __('portal.invitation.mail_body', $values, $this->locale),
            ['label' => __('portal.invitation.action', [], $this->locale), 'url' => $values['link']],
            $this->locale,
            $organization,
        );
    }
}
