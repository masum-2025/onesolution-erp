<?php

namespace Modules\EduWebsite\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\EduWebsite\Models\Enquiry;
use Modules\EduWebsite\Services\Enquiries;

/**
 * Admission enquiries older than each company's rule
 * (edu_website.enquiry_retention_days) are removed, with the details of
 * the people who asked. Runs in every client database.
 */
class PruneEnquiries extends Command
{
    protected $signature = 'edu-website:prune-enquiries';

    protected $description = 'Remove website admission enquiries kept longer than the company allows';

    public function handle(TenantDatabases $databases, Enquiries $enquiries): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $companies = $databases->onConnection($databases->connectionFor($database), fn () => Enquiry::query()
                ->withoutGlobalScope(OrganizationScope::class)->distinct()->pluck('organization_id')->all());
            foreach (Organization::query()->whereKey($companies)->get() as $company) {
                $count += $enquiries->prune($company);
            }
        }
        $this->info("Removed {$count} old enquiry(ies).");

        return self::SUCCESS;
    }
}
