<?php

namespace Modules\EducationFees\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Services\Fines;

/**
 * Every night: late fines on open bills past their due date, under each
 * campus's rule education_fees.late_fine (off by default). Running twice a
 * day adds nothing. Institutions with fees off are skipped (data stays).
 */
class ApplyLateFines extends Command
{
    protected $signature = 'education-fees:apply-fines';

    protected $description = 'Add the late fines due today to open fee bills.';

    public function handle(TenantDatabases $databases, ModuleResolver $modules, Fines $fines): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($modules, $fines, &$count) {
                $companies = Bill::query()->withoutGlobalScope(OrganizationScope::class)->where('status', 'open')->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    if ($modules->isEnabled('education_fees', $company)) {
                        $count += $fines->apply($company);
                    }
                }
            });
        }
        $this->info("Fined {$count} bill(s).");

        return self::SUCCESS;
    }
}
