<?php

namespace App\Platform\Identity\Console;

use App\Platform\Identity\Services\AccountDeletion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Erases the accounts whose deletion grace period is over (scheduled daily,
 * safe to repeat).
 *
 *   php artisan privacy:erase-due
 */
class EraseDueAccounts extends Command
{
    protected $signature = 'privacy:erase-due';

    protected $description = 'Erase the personal data of accounts whose deletion grace period has ended';

    public function handle(AccountDeletion $deletion): int
    {
        $counts = $deletion->eraseDue(CarbonImmutable::now());

        $this->info(sprintf('%d erased, %d waiting (something only they own must be handed over first).', $counts['erased'], $counts['waiting']));

        return self::SUCCESS;
    }
}
