<?php

namespace App\Platform\Audit\Console;

use App\Platform\Audit\Shipping\AuditShipping;
use Illuminate\Console\Command;

class ShipAudit extends Command
{
    protected $signature = 'audit:ship';

    protected $description = 'Copy new audit entries to the external audit store (AUDIT_SHIP_DRIVER)';

    public function handle(): int
    {
        if (config('audit.shipping.driver') === 'none') {
            $this->line('Audit shipping is off (AUDIT_SHIP_DRIVER=none).');

            return self::SUCCESS;
        }

        $this->info(app(AuditShipping::class)->run().' audit entries shipped.');

        return self::SUCCESS;
    }
}
