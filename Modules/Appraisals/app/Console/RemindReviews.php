<?php

namespace Modules\Appraisals\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Appraisals\Models\Cycle;
use Modules\Appraisals\Services\Reviews;

/**
 * Reminds whoever must act on a review appraisals.reminder_days before its
 * step's last day, and on the day before. Companies with Appraisals off are
 * left alone. Runs in every client database.
 */
class RemindReviews extends Command
{
    protected $signature = 'appraisals:remind';

    protected $description = 'Remind employees and managers of appraisal steps ending soon';

    public function handle(TenantDatabases $databases, Reviews $reviews, ModuleResolver $modules): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $companies = $databases->onConnection($databases->connectionFor($database), fn () => Cycle::query()
                ->withoutGlobalScope(OrganizationScope::class)->where('status', Cycle::OPEN)->distinct()->pluck('organization_id')->all());
            foreach (Organization::query()->whereKey($companies)->get() as $company) {
                if ($modules->isEnabled('appraisals', $company)) {
                    $count += $reviews->remind($company);
                }
            }
        }
        $this->info("Sent {$count} reminder(s).");

        return self::SUCCESS;
    }
}
