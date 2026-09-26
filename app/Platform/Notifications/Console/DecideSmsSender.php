<?php

namespace App\Platform\Notifications\Console;

use App\Platform\Notifications\Models\PartnerSmsSender;
use App\Platform\Notifications\Services\SmsSenderService;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Console\Command;

/**
 * Platform operators approve a partner's SMS sender ID once it is registered
 * with the operators (or reject it). Audited.
 *
 *   php artisan sms:sender approve acme --reason="Registered with GP, Robi and Banglalink"
 *   php artisan sms:sender reject acme --reason="Name belongs to another company"
 */
class DecideSmsSender extends Command
{
    protected $signature = 'sms:sender
        {action : approve or reject}
        {partner : Partner slug or id}
        {--reason= : Why (required, shown to the partner)}';

    protected $description = 'Approve or reject a partner\'s SMS sender ID';

    public function handle(SmsSenderService $senders): int
    {
        $action = (string) $this->argument('action');
        $reason = trim((string) $this->option('reason'));

        if (! in_array($action, ['approve', 'reject'], true)) {
            $this->error('The action must be approve or reject.');

            return self::INVALID;
        }

        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason of at least 5 characters.');

            return self::INVALID;
        }

        $partner = Partner::query()->where('slug', $this->argument('partner'))->orWhere('id', $this->argument('partner'))->first();
        $sender = $partner === null ? null : PartnerSmsSender::query()->where('partner_id', $partner->getKey())->first();

        if ($sender === null) {
            $this->error($partner === null ? 'No partner with that slug or id.' : 'This partner has not asked for a sender ID.');

            return self::INVALID;
        }

        $senders->decide($sender, $action === 'approve', $reason);
        $this->info("{$partner->name}: sender ID \"{$sender->sender_id}\" is now {$sender->status}.");

        return self::SUCCESS;
    }
}
