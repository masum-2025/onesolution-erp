<?php

namespace Modules\Hrm\Console;

use Illuminate\Console\Command;
use Modules\Hrm\Services\DocumentExpiry;

/** Daily: tell HR about employee documents that expire soon or have expired (each stage once). */
class SendDocumentExpiryAlerts extends Command
{
    protected $signature = 'hrm:document-expiry';

    protected $description = 'Send HR the reminders for employee documents that expire soon or have expired';

    public function handle(DocumentExpiry $expiry): int
    {
        $count = $expiry->run();
        $this->info("Reported {$count} document(s).");

        return self::SUCCESS;
    }
}
